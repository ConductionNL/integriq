<?php

/**
 * Unit tests for the native data-gathering contract.
 *
 * Reads the real `lib/Settings/register.d/*.json` fragments and the real gather
 * classes, rather than copies of them, and checks what
 * Wave0GatheringConnectorRegisterFragmentTest does not: the Synchronization's
 * `targetType` and wiring to its own Source and Mappings, the Job's action
 * class, the `$comment` every gathering fragment carries, the transport-only
 * role of the leaf seed sources, and that the gather path depends on no
 * flow-engine code.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/native-data-gathering-provider/specs/native-data-gathering-provider/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\Job\SynchronizationAction;
use PHPUnit\Framework\TestCase;

/**
 * The gather contract, the leaf boundary and the flow-reaction seam.
 *
 * @spec openspec/changes/native-data-gathering-provider/specs/native-data-gathering-provider/spec.md#requirement-scheduled-gather-pipeline-replaces-a-specter-ingestion-script
 */
class GatherContractTest extends TestCase {

	/**
	 * The fragment directory.
	 */
	private const FRAGMENT_DIR = __DIR__ . '/../../../lib/Settings/register.d/';

	/**
	 * The ten Wave-0 gathering connector fragments.
	 *
	 * @var array<int, string>
	 */
	private const CONNECTORS = [
		'tenderned-connector.json',
		'nextcloud-marketplace-connector.json',
		'dpg-registry-connector.json',
		'latvia-iub-connector.json',
		'germany-bund-connector.json',
		'boamp-france-connector.json',
		'australia-austender-connector.json',
		'greece-diavgeia-connector.json',
		'sweden-avropa-connector.json',
		'austria-datagvat-connector.json',
	];

	/**
	 * Transport-only seed sources a leaf provider calls live.
	 *
	 * @var array<string, string> fragment => source slug
	 */
	private const TRANSPORT_SOURCES = [
		'brp-haalcentraal-source.json' => 'brp-haalcentraal',
		'kvk-source.json' => 'kvk',
		'xwiki-source.json' => 'xwiki',
	];

	/**
	 * The classes that fetch, map, upsert and schedule a gathering run.
	 *
	 * @var array<int, string>
	 */
	private const GATHER_PATH = [
		'lib/BackgroundJob/JobTask.php',
		'lib/Service/JobService.php',
		'lib/Service/Job/SynchronizationAction.php',
		'lib/Service/SynchronizationService.php',
	];

	/**
	 * A fragment, decoded.
	 *
	 * @param string $filename The fragment's basename.
	 *
	 * @return array<string, mixed>
	 */
	private function fragment(string $filename): array {
		$data = json_decode((string)file_get_contents(self::FRAGMENT_DIR . $filename), true);
		$this->assertIsArray($data, $filename . ' must be valid JSON.');

		return $data;
	}//end fragment()

	/**
	 * A fragment's objects of one schema, by slug.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param string               $schema   The schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(array $fragment, string $schema): array {
		$bySlug = [];
		foreach (($fragment['components']['objects'] ?? []) as $object) {
			if (($object['@self']['schema'] ?? null) === $schema) {
				$bySlug[(string)$object['@self']['slug']] = $object;
			}
		}

		return $bySlug;
	}//end objects()

	/**
	 * Every Synchronization and Job across all shipped fragments.
	 *
	 * @return array{synchronization: array<string, array<string, mixed>>, job: array<string, array<string, mixed>>}
	 */
	private function allSynchronizationsAndJobs(): array {
		$all = ['synchronization' => [], 'job' => []];
		foreach (glob(self::FRAGMENT_DIR . '*.json') as $path) {
			$fragment = $this->fragment(basename($path));
			foreach (array_keys($all) as $schema) {
				$all[$schema] = array_merge($all[$schema], $this->objects($fragment, $schema));
			}
		}

		return $all;
	}//end allSynchronizationsAndJobs()

	/**
	 * The ten connectors as a data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function connectorProvider(): array {
		$cases = [];
		foreach (self::CONNECTORS as $filename) {
			$cases[$filename] = [$filename];
		}

		return $cases;
	}//end connectorProvider()

	/**
	 * The Synchronization upserts into a register/schema target, from the
	 * fragment's own Source, through the fragment's own Mappings, and the Job
	 * runs it through SynchronizationAction.
	 *
	 * @dataProvider connectorProvider
	 *
	 * @param string $filename The fragment's basename.
	 *
	 * @return void
	 */
	public function testTheConnectorIsWiredSourceToMappingToSynchronizationToJob(string $filename): void {
		$fragment = $this->fragment($filename);
		$sources = $this->objects($fragment, 'source');
		$mappings = $this->objects($fragment, 'mapping');
		$synchronizations = $this->objects($fragment, 'synchronization');
		$jobs = $this->objects($fragment, 'job');

		$this->assertCount(1, $synchronizations, $filename);
		$sync = array_values($synchronizations)[0];
		$this->assertSame('register/schema', $sync['targetType'], $filename . ' must upsert into a register/schema target.');
		$this->assertMatchesRegularExpression('#^[A-Za-z0-9_-]+/[A-Za-z0-9_-]+$#', $sync['targetId'], $filename . ' targetId must be "<register>/<schema>".');
		$this->assertSame(array_keys($sources), [$sync['sourceId']], $filename . ' must fetch from its own Source.');
		$this->assertArrayHasKey($sync['sourceTargetMapping'], $mappings, $filename . ' must map through its own Mapping.');
		if (isset($sync['sourceHashMapping']) === true) {
			$this->assertArrayHasKey($sync['sourceHashMapping'], $mappings, $filename . ' must hash through its own Mapping.');
		}

		$job = array_values($jobs)[0];
		$this->assertSame(SynchronizationAction::class, $job['jobClass'], $filename . ' must run on Integriq\'s own cron through SynchronizationAction.');
		$this->assertSame(array_keys($synchronizations)[0], $job['arguments']['synchronizationId']);

	}//end testTheConnectorIsWiredSourceToMappingToSynchronizationToJob()

	/**
	 * Every gathering fragment says, in its `$comment`, what it ingests, so a
	 * disabled or blocked one is never an undocumented silent zero.
	 *
	 * @dataProvider connectorProvider
	 *
	 * @param string $filename The fragment's basename.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/native-data-gathering-provider/specs/native-data-gathering-provider/spec.md#requirement-a-connector-blocked-on-an-unbuilt-capability-ships-disabled
	 */
	public function testTheConnectorCarriesAComment(string $filename): void {
		$comment = ($this->fragment($filename)['$comment'] ?? '');

		$this->assertIsString($comment);
		$this->assertNotSame('', trim($comment), $filename . ' must document itself in $comment.');

	}//end testTheConnectorCarriesAComment()

	/**
	 * A transport-only seed source is never the source of a Synchronization,
	 * and no Job runs a Synchronization that is missing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/native-data-gathering-provider/specs/native-data-gathering-provider/spec.md#requirement-bulk-ingestion-sources-are-distinct-from-transport-only-leaf-sources
	 */
	public function testLeafTransportSourcesCarryNoSynchronizationOrJob(): void {
		$all = $this->allSynchronizationsAndJobs();
		$gathered = array_map(static fn (array $sync): mixed => ($sync['sourceId'] ?? null), $all['synchronization']);

		foreach (self::TRANSPORT_SOURCES as $filename => $slug) {
			$fragment = $this->fragment($filename);
			$this->assertArrayHasKey($slug, $this->objects($fragment, 'source'), $filename);
			$this->assertSame([], $this->objects($fragment, 'synchronization'), $filename . ' is transport only.');
			$this->assertSame([], $this->objects($fragment, 'job'), $filename . ' is transport only.');
			$this->assertNotContains($slug, $gathered, $slug . ' is a leaf transport, so no Synchronization may gather from it.');
		}

		foreach ($all['job'] as $slug => $job) {
			if (($job['jobClass'] ?? null) !== SynchronizationAction::class) {
				continue;
			}

			$this->assertArrayHasKey($job['arguments']['synchronizationId'] ?? '', $all['synchronization'], $slug . ' runs a Synchronization no fragment ships.');
		}

	}//end testLeafTransportSourcesCarryNoSynchronizationOrJob()

	/**
	 * The gather path imports no flow-engine class: it fetches, maps, upserts
	 * and schedules on its own cron, and flows react to the objects it writes
	 * through OpenRegister's object events.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/native-data-gathering-provider/specs/native-data-gathering-provider/spec.md#requirement-gathered-objects-react-through-openregister-events
	 */
	public function testTheGatherPathDependsOnNoFlowEngine(): void {
		foreach (self::GATHER_PATH as $file) {
			$source = (string)file_get_contents(__DIR__ . '/../../../' . $file);
			$this->assertNotSame('', $source, $file);

			preg_match_all('/^use\s+([^;]+);/m', $source, $imports);
			foreach ($imports[1] as $import) {
				$this->assertDoesNotMatchRegularExpression('/\\\\(Flow|Workflow)\\\\/', '\\' . $import . '\\', $file . ' imports flow-engine code: ' . $import);
			}
		}

		$sync = (string)file_get_contents(__DIR__ . '/../../../lib/Service/SynchronizationService.php');
		$this->assertStringContainsString('$this->orObjectService->saveObject(', $sync, 'Gathered rows must be written through OpenRegister, which emits the object events flows react to.');

	}//end testTheGatherPathDependsOnNoFlowEngine()
}//end class
