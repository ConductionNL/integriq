<?php

/**
 * Unit tests for the SLO curriculum node reader.
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
use OCA\Integriq\Adapters\Slo\SloCurriculumNodeReader;
use PHPUnit\Framework\TestCase;

/**
 * Field paths, fallbacks and link resolution.
 */
class SloCurriculumNodeReaderTest extends TestCase {
	/**
	 * @return SloCurriculumNodeReader
	 */
	private function nodes(): SloCurriculumNodeReader {
		return new SloCurriculumNodeReader(new JsonTagReader());
	}//end nodes()

	/**
	 * @param array<string,mixed> $entity The entity.
	 * @param array<string,mixed> $fields Profile field paths per type.
	 * @param array<string,array<string,mixed>> $index The link index.
	 *
	 * @return array<string,mixed>
	 */
	private function record(array $entity, array $fields = [], array $index = []): array {
		return $this->nodes()->record(
			$entity,
			['uuid' => 'u1', 'type' => 'T', 'parentUuid' => 'p', 'isLeaf' => true, 'fields' => $fields],
			$index
		);
	}//end record()

	/**
	 * @return void
	 */
	public function testCodeAndTitleNeverEndUpEmpty(): void {
		$this->assertSame(['u1', 'u1', null], array_values(array_intersect_key($this->record(['@type' => 'T']), array_flip(['code', 'title', 'description']))));

		$onlyTitle = $this->record(['title' => '  Opmaak  ']);
		$this->assertSame('Opmaak', $onlyTitle['code']);
		$this->assertSame('Opmaak', $onlyTitle['title']);

		$onlyPrefix = $this->record(['prefix' => 7]);
		$this->assertSame('7', $onlyPrefix['code']);
		$this->assertSame('7', $onlyPrefix['title']);
	}//end testCodeAndTitleNeverEndUpEmpty()

	/**
	 * @return void
	 */
	public function testProfilePathsFollowLinks(): void {
		$index = ['/uuid/d1' => ['@id' => '/uuid/d1', 'title' => 'Doeltekst', 'description' => 'Uitleg']];
		$record = $this->record(
			['prefix' => 'DN1', 'Doel' => [['@link' => '/uuid/d1']], 'Niveau' => [['@link' => '/uuid/n1'], 'junk', ['title' => 'no id']]],
			['T' => ['title' => ['Doel.0.title', 'title'], 'description' => ['Doel.0.description'], 'code' => ['Doel.9.title', 'prefix']]],
			$index + ['n1' => ['@id' => '/uuid/n1', 'title' => 'groep 5']]
		);

		$this->assertSame('DN1', $record['code']);
		$this->assertSame('Doeltekst', $record['title']);
		$this->assertSame('Uitleg', $record['description']);
		$this->assertSame([['uuid' => 'n1', 'title' => 'groep 5']], $record['niveaus']);
		$this->assertSame('p', $record['parentSloUuid']);
		$this->assertTrue($record['isLeaf']);
	}//end testProfilePathsFollowLinks()

	/**
	 * @return void
	 */
	public function testASingleNiveauObjectAndNoVakleergebied(): void {
		$nodes = $this->nodes();

		$this->assertSame([['uuid' => 'n', 'title' => null]], $nodes->niveaus(['NiveauIndex' => ['uuid' => 'n', 'title' => ' ']], []));
		$this->assertSame([], $nodes->niveaus(['Niveau' => 'po'], []));
		$this->assertSame(['e1', 'engels'], $nodes->subjectKeys(['uuid' => 'e1', 'title' => 'Engels'], []));
		$this->assertSame(['x'], $nodes->listOf(['x']));
		$this->assertSame([['a' => 1]], $nodes->listOf(['a' => 1]));
		$this->assertSame([], $nodes->listOf(null));
	}//end testASingleNiveauObjectAndNoVakleergebied()

	/**
	 * @return void
	 */
	public function testDescribeReadsStatusVersieAndFloatsAsText(): void {
		$info = $this->nodes()->describe(['id' => 'r', '@type' => 'Examenprogramma', 'title' => 'T', 'versie' => 2020, 'status' => 1.5]);

		$this->assertSame('r', $info['uuid']);
		$this->assertSame('Examenprogramma', $info['type']);
		$this->assertSame('2020', $info['versie']);
		$this->assertSame('1.5', $info['status']);
	}//end testDescribeReadsStatusVersieAndFloatsAsText()
}//end class
