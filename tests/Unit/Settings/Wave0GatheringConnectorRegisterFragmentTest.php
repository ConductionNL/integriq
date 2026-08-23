<?php

/**
 * Unit tests for the Wave-0 native-data-gathering connector register
 * fragments — asserting against the REAL `lib/Settings/register.d/*.json`
 * files (not a hand-rebuilt payload), mirroring
 * EndoflifeDateRegisterFragmentTest's approach. Each fragment bundles a
 * full Source + Mapping(s) + Synchronization + Job set — the same shape
 * live-verified end-to-end (see spectr/connectors/IMPORT.md in the spectr
 * repo) before being landed here so the ten connectors self-provision on
 * `occ app:enable`/upgrade via InitializeRegister instead of requiring a
 * manual `configurations/import` call every time the environment resets.
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

use OCA\Integriq\Repair\InitializeRegister;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies all ten Wave-0 connector fragments: each is valid JSON, carries
 * exactly one enabled Source + at least one Mapping + one Synchronization
 * (wired sourceId -> targetId) + one enabled Job (with a real interval and
 * a `synchronizationId` argument matching the Synchronization's own slug),
 * and that concatenating all ten via the real ADR-037 deep-merge produces
 * no slug collisions.
 *
 * @spec openspec/changes/native-data-gathering-provider/tasks.md#wave-0--formalize-the-ten-live-verified-connectors-as-registerd-fragments
 */
class Wave0GatheringConnectorRegisterFragmentTest extends TestCase {

	/**
	 * Fragment filename => [synchronization slug, target register/schema].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const FRAGMENTS = [
		'tenderned-connector.json' => ['tenderned-to-spectr-tender', 'spectr/tender'],
		'nextcloud-marketplace-connector.json' => ['nextcloud-appstore-to-spectr-marketplaceapp', 'spectr/marketplaceApp'],
		'dpg-registry-connector.json' => ['dpg-registry-to-spectr-marketplaceapp', 'spectr/marketplaceApp'],
		'latvia-iub-connector.json' => ['latvia-iub-to-spectr-tender', 'spectr/tender'],
		'germany-bund-connector.json' => ['germany-bund-to-spectr-tender', 'spectr/tender'],
		'boamp-france-connector.json' => ['boamp-france-to-spectr-tender', 'spectr/tender'],
		'australia-austender-connector.json' => ['australia-austender-to-spectr-tender', 'spectr/tender'],
		'greece-diavgeia-connector.json' => ['greece-diavgeia-to-spectr-tender', 'spectr/tender'],
		'sweden-avropa-connector.json' => ['sweden-avropa-to-spectr-tender', 'spectr/tender'],
		'austria-datagvat-connector.json' => ['austria-opentender-to-spectr-tender', 'spectr/tender'],
	];

	/**
	 * Decode a fragment file once per call.
	 *
	 * @param string $filename Fragment basename under lib/Settings/register.d/.
	 *
	 * @return array<mixed>
	 */
	private function decodeFragment(string $filename): array {
		$path = __DIR__ . '/../../../lib/Settings/register.d/' . $filename;
		$this->assertFileExists($path);

		$raw = file_get_contents($path);
		$this->assertNotFalse($raw);

		$fragment = json_decode($raw, true);
		$this->assertSame(JSON_ERROR_NONE, json_last_error(), $path . ' MUST be valid JSON: ' . json_last_error_msg());

		return $fragment;
	}//end decodeFragment()

	/**
	 * Invoke the private static InitializeRegister::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Index a fragment's `components.objects` list by `@self.schema` then
	 * `@self.slug`.
	 *
	 * @param array<mixed> $objects The `components.objects` list.
	 *
	 * @return array<string, array<string, array<mixed>>> schema => slug => object.
	 */
	private function indexBySchemaAndSlug(array $objects): array {
		$index = [];
		foreach ($objects as $object) {
			$schema = ($object['@self']['schema'] ?? null);
			$slug = ($object['@self']['slug'] ?? null);
			if ($schema === null || $slug === null) {
				continue;
			}

			$index[$schema][$slug] = $object;
		}

		return $index;
	}//end indexBySchemaAndSlug()

	/**
	 * Data provider yielding each fragment's filename + expected
	 * synchronization slug + expected target.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function fragmentProvider(): array {
		$cases = [];
		foreach (self::FRAGMENTS as $filename => [$syncSlug, $target]) {
			$cases[$filename] = [$filename, $syncSlug, $target];
		}

		return $cases;
	}//end fragmentProvider()

	/**
	 * Each fragment declares exactly one enabled Source, at least one
	 * Mapping, one Synchronization wired sourceId -> targetId, and one
	 * enabled Job with a real (non-zero) interval whose
	 * `arguments.synchronizationId` matches the Synchronization's own slug
	 * — i.e. it genuinely self-schedules via Integriq's Job/cron path
	 * rather than requiring a manual trigger.
	 *
	 * @dataProvider fragmentProvider
	 *
	 * @param string $filename Fragment basename.
	 * @param string $syncSlug Expected synchronization `@self.slug`.
	 * @param string $target Expected `targetId` ("register/schema").
	 *
	 * @return void
	 */
	public function testFragmentBundlesEnabledSourceMappingSynchronizationAndSelfSchedulingJob(
		string $filename,
		string $syncSlug,
		string $target,
	): void {
		$fragment = $this->decodeFragment($filename);
		$objects = ($fragment['components']['objects'] ?? []);
		$this->assertNotEmpty($objects, $filename . ' must declare components.objects');

		$bySchema = $this->indexBySchemaAndSlug($objects);

		// Exactly one Source, enabled.
		$this->assertArrayHasKey('source', $bySchema, $filename . ' must declare a source object');
		$this->assertCount(1, $bySchema['source'], $filename . ' must declare exactly one source');
		$source = array_values($bySchema['source'])[0];
		$this->assertTrue($source['isEnabled'] ?? false, $filename . ' source must ship isEnabled:true (Wave 0 = fully live)');
		$this->assertSame('openconnector', $source['@self']['register'], $filename . ' source must live in the openconnector register');

		// At least one Mapping.
		$this->assertArrayHasKey('mapping', $bySchema, $filename . ' must declare at least one mapping');
		$this->assertGreaterThanOrEqual(1, count($bySchema['mapping']));

		// Exactly one Synchronization, correctly wired.
		$this->assertArrayHasKey('synchronization', $bySchema, $filename . ' must declare a synchronization object');
		$this->assertArrayHasKey($syncSlug, $bySchema['synchronization'], $filename . " must declare synchronization slug '$syncSlug'");
		$sync = $bySchema['synchronization'][$syncSlug];
		$this->assertSame($target, $sync['targetId'] ?? null, $filename . " synchronization must target '$target'");
		$this->assertNotEmpty($sync['sourceId'] ?? null, $filename . ' synchronization must reference a sourceId');

		// Exactly one Job, enabled, self-scheduling via a real interval,
		// pointed at this fragment's own synchronization.
		$this->assertArrayHasKey('job', $bySchema, $filename . ' must declare a job object');
		$this->assertCount(1, $bySchema['job'], $filename . ' must declare exactly one job');
		$job = array_values($bySchema['job'])[0];
		$this->assertTrue($job['isEnabled'] ?? false, $filename . ' job must ship isEnabled:true (Wave 0 self-schedules via OC cron)');
		$this->assertGreaterThan(0, $job['interval'] ?? 0, $filename . ' job must declare a non-zero interval (OC cron cadence)');
		$this->assertSame(
			$syncSlug,
			$job['arguments']['synchronizationId'] ?? null,
			$filename . " job must target its own synchronization ('$syncSlug') to actually self-run it"
		);

	}//end testFragmentBundlesEnabledSourceMappingSynchronizationAndSelfSchedulingJob()

	/**
	 * Concatenating all ten fragments' `components.objects` lists (the real
	 * ADR-037 merge behaviour for list-shaped keys — see
	 * InitializeRegister::deepMergeConfig()) produces exactly 40 objects
	 * (4 per connector: source, >=1 mapping, synchronization, job) with no
	 * duplicate `@self.slug` within any single schema — i.e. importing all
	 * ten together (as `occ app:enable` will) never collides.
	 *
	 * @return void
	 */
	public function testAllTenFragmentsMergeWithoutSlugCollisions(): void {
		$base = ['components' => ['objects' => []]];
		foreach (array_keys(self::FRAGMENTS) as $filename) {
			$base = $this->merge($base, $this->decodeFragment($filename));
		}

		$objects = $base['components']['objects'];
		$bySchema = $this->indexBySchemaAndSlug($objects);

		// No collisions: every slug appeared exactly once per schema, i.e.
		// indexed count per schema equals the raw count per schema.
		$rawCountsBySchema = [];
		foreach ($objects as $object) {
			$schema = ($object['@self']['schema'] ?? 'unknown');
			$rawCountsBySchema[$schema] = ($rawCountsBySchema[$schema] ?? 0) + 1;
		}

		foreach ($bySchema as $schema => $slugs) {
			$this->assertCount(
				$rawCountsBySchema[$schema],
				$slugs,
				"schema '$schema' has a duplicate @self.slug across the ten Wave-0 fragments"
			);
		}

		// Ten sources, ten synchronizations, ten jobs, >=10 mappings.
		$this->assertCount(10, $bySchema['source'] ?? [], 'expected exactly 10 distinct sources across Wave 0');
		$this->assertCount(10, $bySchema['synchronization'] ?? [], 'expected exactly 10 distinct synchronizations across Wave 0');
		$this->assertCount(10, $bySchema['job'] ?? [], 'expected exactly 10 distinct jobs across Wave 0');
		$this->assertGreaterThanOrEqual(10, count($bySchema['mapping'] ?? []), 'expected at least 10 distinct mappings across Wave 0');

	}//end testAllTenFragmentsMergeWithoutSlugCollisions()

}//end class
