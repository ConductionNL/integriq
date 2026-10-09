<?php

/**
 * Unit tests for the SLO curriculum tree walker.
 *
 * Real trees come from the recorded fixture (SLO release data); the
 * mechanics (deprecated, duplicate, bare reference, guards) use small
 * synthetic trees.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Slo;

use OCA\Integriq\Adapters\Slo\JsonTagReader;
use OCA\Integriq\Adapters\Slo\SloCurriculumClient;
use OCA\Integriq\Adapters\Slo\SloCurriculumClientMock;
use OCA\Integriq\Adapters\Slo\SloCurriculumNodeReader;
use OCA\Integriq\Adapters\Slo\SloCurriculumPresetRegistry;
use OCA\Integriq\Adapters\Slo\SloCurriculumTreeWalker;
use OCA\Integriq\Exception\SloCurriculumException;
use PHPUnit\Framework\TestCase;

/**
 * The walk follows the profile.
 */
class SloCurriculumTreeWalkerTest extends TestCase {
	private const BURGERSCHAP = '612afa33-c49c-4b12-a7d1-7e44f2d69d25';

	/**
	 * @return SloCurriculumTreeWalker
	 */
	private function walker(): SloCurriculumTreeWalker {
		return new SloCurriculumTreeWalker(new JsonTagReader(), new SloCurriculumNodeReader(new JsonTagReader()));
	}//end walker()

	/**
	 * @param array<int,array<string,mixed>> $nodes Nodes.
	 * @param string $type An SLO type.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function ofType(array $nodes, string $type): array {
		return array_values(array_filter($nodes, static fn (array $node): bool => $node['sloType'] === $type));
	}//end ofType()

	/**
	 * @return void
	 */
	public function testRenewedKerndoelensetKeepsItsKernzinLevel(): void {
		$client = new SloCurriculumClientMock();
		$walker = $this->walker();
		$profile = (new SloCurriculumPresetRegistry())->set('fo-kerndoelen');

		$result = $walker->walk([$walker->fetchTree($client, self::BURGERSCHAP)], $profile, $client, false);
		$nodes = $result['nodes'];
		$byUuid = array_column($nodes, null, 'sloUuid');

		$this->assertCount(3, $this->ofType($nodes, 'FoDomein'));
		$this->assertCount(6, $this->ofType($nodes, 'FoKernzin'));
		$this->assertCount(10, $this->ofType($nodes, 'FoDoelzin'));
		$this->assertSame(10, $result['stats']['leaves']);
		$this->assertSame(19, $result['stats']['nodes']);
		$this->assertSame(0, $result['stats']['expansions']);

		foreach ($this->ofType($nodes, 'FoDoelzin') as $doelzin) {
			$this->assertSame('FoKernzin', $byUuid[$doelzin['parentSloUuid']]['sloType']);
			$this->assertStringStartsWith('Doelzin ', $doelzin['code']);
			$this->assertStringStartsWith('De ', $doelzin['title']);
			$this->assertSame([], $doelzin['niveaus']);
		}

		foreach ($this->ofType($nodes, 'FoDomein') as $domein) {
			$this->assertNull($domein['parentSloUuid']);
		}

		// Parents come before children.
		$seen = [];
		foreach ($nodes as $node) {
			if ($node['parentSloUuid'] !== null) {
				$this->assertArrayHasKey($node['parentSloUuid'], $seen);
			}

			$seen[$node['sloUuid']] = true;
		}

		// Top-level order runs 0..n-1.
		$this->assertSame([0, 1, 2], array_column($this->ofType($nodes, 'FoDomein'), 'order'));
	}//end testRenewedKerndoelensetKeepsItsKernzinLevel()

	/**
	 * @return void
	 */
	public function testPrimaryFilterKeepsOnlyPrimaryKerndoelen(): void {
		$client = new SloCurriculumClientMock();
		$walker = $this->walker();
		$profile = (new SloCurriculumPresetRegistry())->set('kerndoelen-2006-po');
		$listing = $walker->fetchJson($client, 'kerndoel_vakleergebied/', ['page' => 0, 'perPage' => 1000]);
		$roots = array_map(static fn (array $item): array => $walker->fetchTree($client, $item['uuid']), $listing['data']);

		$result = $walker->walk($roots, $profile, $client, true);
		$kerndoelen = $this->ofType($result['nodes'], 'Kerndoel');

		$this->assertCount(58, $kerndoelen);
		$this->assertCount(10, $this->ofType($result['nodes'], 'KerndoelVakleergebied'));
		$this->assertCount(10, $this->ofType($result['nodes'], 'KerndoelDomein'));
		$this->assertGreaterThan(0, $result['stats']['filteredByNiveau']);
		$this->assertGreaterThan(0, $result['stats']['prunedBranches']);
		$this->assertGreaterThan(0, $result['stats']['skippedDuplicates']);

		foreach ($kerndoelen as $kerndoel) {
			$this->assertContains('512e4729-03a4-43a2-95ba-758071d1b725', array_column($kerndoel['niveaus'], 'uuid'));
			$this->assertStringStartsWith('PO Kerndoel', $kerndoel['code']);
			$this->assertNotNull($kerndoel['description'], 'the full statement lands in description');
		}

		foreach ($this->ofType($result['nodes'], 'KerndoelVakleergebied') as $vakleergebied) {
			$this->assertNull($vakleergebied['parentSloUuid']);
		}
	}//end testPrimaryFilterKeepsOnlyPrimaryKerndoelen()

	/**
	 * @return void
	 */
	public function testExamenprogrammaAndLeerdoelenkaartShapes(): void {
		$client = new SloCurriculumClientMock();
		$walker = $this->walker();
		$registry = new SloCurriculumPresetRegistry();

		$exam = $walker->walk(
			[$walker->fetchTree($client, '43beb4d1-9950-4e88-b18e-0dee930169fe')],
			$registry->set('examenprogramma'),
			$client,
			false
		);
		$this->assertSame(9, $exam['stats']['nodes']);
		$this->assertSame(4, $exam['stats']['leaves']);
		$this->assertCount(2, $this->ofType($exam['nodes'], 'ExamenprogrammaSubdomein'));

		$ldk = $walker->walk(
			[$walker->fetchTree($client, '9f638551-cd79-439a-a41e-b11e29899164')],
			$registry->set('leerdoelenkaarten'),
			$client,
			false
		);
		$doelniveaus = $this->ofType($ldk['nodes'], 'Doelniveau');
		$this->assertCount(4, $doelniveaus);
		$this->assertSame(7, $ldk['stats']['nodes']);
		foreach ($doelniveaus as $doelniveau) {
			$this->assertNotSame('', $doelniveau['title']);
			$this->assertNotSame([], $doelniveau['niveaus']);
		}

		$titles = array_merge(...array_map(static fn (array $node): array => array_column($node['niveaus'], 'title'), $doelniveaus));
		$this->assertContains('groep 3-4', $titles);
		$this->assertContains('groep 5-6', $titles);
	}//end testExamenprogrammaAndLeerdoelenkaartShapes()

	/**
	 * @return void
	 */
	public function testDeprecatedUnreleasedAndDuplicatesAreSkippedAndCounted(): void {
		$root = (new JsonTagReader())->decode(
			'<object class="S" id="/uuid/root">{"title":"root","A":['
			. '<object class="A" id="/uuid/a1">{"title":"a1","B":[<object class="B" id="/uuid/b1">{"title":"b1"}]},'
			. '<object class="A" id="/uuid/a2">{"title":"a2","deprecated":true,"B":[<object class="B" id="/uuid/b2">{"title":"b2"}]},'
			. '<object class="A" id="/uuid/a3">{"title":"a3","unreleased":true},'
			. '<object class="A" id="/uuid/a4">{"title":"a4","B":[<link>"/uuid/b1"]},'
			. '"not an object"]}'
		);
		$profile = ['levels' => ['A', 'B'], 'leafTypes' => ['B'], 'leafNiveauFilter' => [], 'fields' => []];

		$result = $this->walker()->walk([$root], $profile, new SloCurriculumClientMock(), false);

		$this->assertSame(['a1', 'b1', 'a4'], array_column($result['nodes'], 'sloUuid'));
		$this->assertSame(1, $result['stats']['skippedDeprecated']);
		$this->assertSame(1, $result['stats']['skippedUnreleased']);
		$this->assertSame(1, $result['stats']['skippedDuplicates']);
		$this->assertSame(1, $result['stats']['malformed']);
	}//end testDeprecatedUnreleasedAndDuplicatesAreSkippedAndCounted()

	/**
	 * A bare reference (a shallow JSON response) is expanded through uuid/{id}.
	 *
	 * @return void
	 */
	public function testBareReferenceIsExpandedThroughUuid(): void {
		$client = $this->createMock(SloCurriculumClient::class);
		$client->expects($this->once())
			->method('fetch')
			->with('uuid/d1', [], 'application/json')
			->willReturn('{"@type":"D","uuid":"d1","title":"domein","L":[{"@type":"L","uuid":"l1","title":"leaf","prefix":"L1"}]}');

		$root = ['@type' => 'S', 'uuid' => 'root', 'title' => 'root', 'D' => [['@id' => 'https://opendata.slo.nl/curriculum/uuid/d1']]];
		$profile = ['levels' => ['D', 'L'], 'leafTypes' => ['L'], 'leafNiveauFilter' => [], 'fields' => []];

		$result = $this->walker()->walk([$root], $profile, $client, false);

		$this->assertSame(['d1', 'l1'], array_column($result['nodes'], 'sloUuid'));
		$this->assertSame(1, $result['stats']['expansions']);
		$this->assertSame('L1', $result['nodes'][1]['code']);
		$this->assertSame('d1', $result['nodes'][1]['parentSloUuid']);
	}//end testBareReferenceIsExpandedThroughUuid()

	/**
	 * @return void
	 */
	public function testAnExpansionAnsweredWithAListThrows(): void {
		$client = $this->createMock(SloCurriculumClient::class);
		$client->method('fetch')->willReturn('[{"uuid":"d1"}]');

		$this->expectException(SloCurriculumException::class);
		$this->walker()->walk(
			[['uuid' => 'root', 'title' => 'root', 'D' => [['uuid' => 'd1']]]],
			['levels' => ['D'], 'leafTypes' => [], 'leafNiveauFilter' => [], 'fields' => []],
			$client,
			false
		);
	}//end testAnExpansionAnsweredWithAListThrows()

	/**
	 * @return void
	 */
	public function testTheExpansionLimitStopsTheWalk(): void {
		$client = $this->createMock(SloCurriculumClient::class);
		$client->method('fetch')->willReturnCallback(
			static fn (string $path): string => '{"uuid":"' . substr($path, 5) . '","title":"t"}'
		);
		$children = array_map(static fn (int $i): array => ['uuid' => 'c' . $i], range(1, SloCurriculumTreeWalker::MAX_EXPANSIONS + 1));

		$this->expectException(SloCurriculumException::class);
		$this->expectExceptionMessage('separate lookup');
		$this->walker()->walk(
			[['uuid' => 'root', 'title' => 'root', 'C' => $children]],
			['levels' => ['C'], 'leafTypes' => [], 'leafNiveauFilter' => [], 'fields' => []],
			$client,
			false
		);
	}//end testTheExpansionLimitStopsTheWalk()

	/**
	 * @return void
	 */
	public function testTheNodeLimitStopsARunawayGraph(): void {
		$children = array_map(
			static fn (int $i): array => ['uuid' => 'c' . $i, 'title' => 't' . $i],
			range(1, SloCurriculumTreeWalker::MAX_NODES + 1)
		);

		$this->expectException(SloCurriculumException::class);
		$this->expectExceptionMessage('more than 25000 nodes');
		$this->walker()->walk(
			[['uuid' => 'root', 'title' => 'root', 'C' => $children]],
			['levels' => ['C'], 'leafTypes' => [], 'leafNiveauFilter' => [], 'fields' => []],
			new SloCurriculumClientMock(),
			false
		);
	}//end testTheNodeLimitStopsARunawayGraph()

	/**
	 * @return void
	 */
	public function testDepthGuardStopsARunawayTree(): void {
		$node = ['@type' => 'N', 'uuid' => 'n17', 'title' => 'deepest'];
		for ($level = 16; $level >= 0; $level--) {
			$node = ['@type' => 'N', 'uuid' => 'n' . $level, 'title' => 'n' . $level, 'C' => [$node]];
		}

		$this->expectException(SloCurriculumException::class);
		$this->walker()->walk([$node], ['levels' => ['C'], 'leafTypes' => [], 'leafNiveauFilter' => [], 'fields' => []], new SloCurriculumClientMock(), true);
	}//end testDepthGuardStopsARunawayTree()

	/**
	 * @return void
	 */
	public function testDescribeEntityAndUuidOf(): void {
		$walker = $this->walker();
		$info = $walker->describeEntity(
			['@id' => 'https://opendata.slo.nl/curriculum/uuid/x1', 'title' => ' Tekenen ', 'versie' => '2020', 'status' => '', 'Vakleergebied' => [['uuid' => 'v1', 'title' => 'Tekenen']]]
		);

		$this->assertSame('x1', $info['uuid']);
		$this->assertSame('Tekenen', $info['title']);
		$this->assertSame('2020', $info['versie']);
		$this->assertNull($info['status']);
		$this->assertSame(['v1', 'tekenen', 'x1'], $info['subjectKeys']);
		$this->assertSame('', (new SloCurriculumNodeReader(new JsonTagReader()))->uuidOf([]));
		$this->assertSame('z', (new SloCurriculumNodeReader(new JsonTagReader()))->uuidOf(['@link' => '/uuid/z']));
	}//end testDescribeEntityAndUuidOf()

	/**
	 * @return void
	 */
	public function testNonObjectAnswersThrow(): void {
		$client = $this->createMock(SloCurriculumClient::class);
		$client->method('fetch')->willReturn('[1,2]');

		$this->expectException(SloCurriculumException::class);
		$this->walker()->fetchTree($client, 'x');
	}//end testNonObjectAnswersThrow()
}//end class
