<?php

/**
 * The seeded sources, mappings and synchronizations behind the packaged ZGW sets.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-six-packaged-slug-referenced-zgw-consumer-sets-req-zgwc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\Zgw\ZgwSetCatalogue;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Every slug a packaged set names must be a seeded object, or the installer
 * has nothing to bind and the set is a promise with no engine behind it
 * (the state #2070 left: six set files, no seeds, no caller).
 */
class ZgwConsumerSetsSeedTest extends TestCase {

	private const FRAGMENT = 'lib/Settings/register.d/zgw-consumer-sets.json';

	/**
	 * The data sets this fragment seeds. zgw-notificaties is the
	 * notification-pull half (Task 3) and seeds with it.
	 */
	private const DATA_SETS = ['zgw-zaken', 'zgw-documenten', 'zgw-catalogi', 'zgw-besluiten', 'zgw-objecten'];

	/**
	 * The seeded objects, keyed by schema and slug.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function seeds(): array {
		$path = dirname(__DIR__, 3) . '/' . self::FRAGMENT;
		$this->assertFileExists($path);
		$fragment = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

		$seeds = [];
		foreach ($fragment['components']['objects'] as $object) {
			$self = $object['@self'];
			$this->assertSame('integriq', $self['register']);
			unset($object['@self']);
			$seeds[$self['schema']][$self['slug']] = $object;
		}

		return $seeds;
	}//end seeds()

	/**
	 * A packaged set file.
	 *
	 * @param string $slug The set slug.
	 *
	 * @return array<string, mixed>
	 */
	private function set(string $slug): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/configurations/' . $slug . '.json';
		return json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
	}//end set()

	/**
	 * Every synchronization, mapping and source a data set names is seeded.
	 *
	 * @return void
	 */
	public function testEverySlugADataSetNamesIsSeeded(): void {
		$seeds = $this->seeds();
		foreach (self::DATA_SETS as $slug) {
			$set = $this->set($slug);
			foreach ($set['synchronizations'] as $sync) {
				$this->assertArrayHasKey($sync, $seeds['synchronization'] ?? [], $slug . ' names synchronization ' . $sync);
			}

			foreach ($set['mappings'] as $mapping) {
				$this->assertArrayHasKey($mapping, $seeds['mapping'] ?? [], $slug . ' names mapping ' . $mapping);
			}

			$this->assertArrayHasKey($set['source']['slug'] ?? '', $seeds['source'] ?? [], $slug . ' names its source by slug');
		}
	}//end testEverySlugADataSetNamesIsSeeded()

	/**
	 * Every seeded object is one OpenRegister accepts.
	 *
	 * @return void
	 */
	public function testEverySeedValidatesAgainstTheRegister(): void {
		foreach ($this->seeds() as $schema => $objects) {
			foreach ($objects as $slug => $object) {
				$this->assertSame([], RegisterSchemaValidator::errors($schema, $object), $schema . ' ' . $slug);
			}
		}
	}//end testEverySeedValidatesAgainstTheRegister()

	/**
	 * Pulls are unbound until an operator installs the set; pushes read the bound schema.
	 *
	 * @return void
	 */
	public function testPullsArriveUnboundAndPushesOnlyForWriteBackSets(): void {
		$seeds = $this->seeds();
		foreach (self::DATA_SETS as $slug) {
			$set    = $this->set($slug);
			$source = $set['source']['slug'];
			$pull   = $seeds['synchronization'][$slug . '-pull'];
			$this->assertSame('api', $pull['sourceType']);
			$this->assertSame($source, $pull['sourceId']);
			$this->assertSame('register/schema', $pull['targetType']);
			$this->assertSame('', $pull['targetId'], 'A shipped target would write into somebody else\'s schema.');
			$this->assertSame('url', $pull['sourceConfig']['idPosition'], 'The remote url is the contract\'s origin id.');
			$this->assertSame('results', $pull['sourceConfig']['resultsPosition']);
			$this->assertContains($pull['sourceTargetMapping'], $set['mappings']);

			$push = ($seeds['synchronization'][$slug . '-push'] ?? null);
			if (ZgwSetCatalogue::writesBack($slug) === false) {
				$this->assertNull($push, $slug . ' does not write back');
				continue;
			}

			$this->assertNotNull($push, $slug . ' writes back');
			$this->assertSame('register/schema', $push['sourceType']);
			$this->assertSame('', $push['sourceId']);
			$this->assertSame('api', $push['targetType']);
			$this->assertSame($source, $push['targetId']);
			$this->assertSame('PATCH', $push['targetConfig']['updateMethod']);
		}
	}//end testPullsArriveUnboundAndPushesOnlyForWriteBackSets()

	/**
	 * Sources ship disabled, sign with a ZGW JWT from a broker credential, and name no fleet app.
	 *
	 * @return void
	 */
	public function testSourcesShipDisabledWithAZgwJwtAndNoFleetApp(): void {
		$seeds = $this->seeds();
		foreach (self::DATA_SETS as $slug) {
			$source = $seeds['source'][$this->set($slug)['source']['slug']];
			$this->assertFalse($source['isEnabled']);
			$this->assertSame(ZgwSetCatalogue::authFor($slug), $this->set($slug)['auth']);
			if (ZgwSetCatalogue::authFor($slug) === ZgwSetCatalogue::TOKEN_AUTH) {
				$this->assertSame('Token {{ source.configuration.authentication.token }}', $source['configuration']['headers']['Authorization']);
				$this->assertArrayHasKey('credentialRef', $source['configuration']['authentication']['token']);
			} else {
				$this->assertSame('Bearer {{ jwtToken(source) }}', $source['configuration']['headers']['Authorization']);
				$this->assertArrayHasKey('credentialRef', $source['configuration']['authentication']['secret']);
			}

			$this->assertSame($this->set($slug)['apiVersion'], $source['configuration']['apiVersion']);
		}

		// The seeds without @self: every @self names the integriq register, which is where they live.
		$serialised = strtolower((string)json_encode($seeds));
		foreach (ZgwSetCatalogue::FLEET_APPS as $app) {
			$this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($app, '/') . '\b/', $serialised, 'names ' . $app);
		}
	}//end testSourcesShipDisabledWithAZgwJwtAndNoFleetApp()
	/**
	 * A bound schema holds the store's own ZGW shape: no set names a translator, and every mapping it names passes the resource through as it is (design D5, decided 2 Oct 2026).
	 *
	 * @return void
	 */
	public function testEveryMappingPassesTheStoresOwnShapeThroughUntranslated(): void {
		$seeds = $this->seeds();
		foreach (array_merge(self::DATA_SETS, ['zgw-notificaties']) as $slug) {
			$set = $this->set($slug);
			$this->assertArrayNotHasKey('translator', $set, $slug . ' names a translator; a bound schema holds the store\'s own shape.');
			foreach ($set['mappings'] ?? [] as $mappingSlug) {
				$mapping = $seeds['mapping'][$mappingSlug];
				$this->assertTrue($mapping['passThrough'], $mappingSlug . ' passes the resource through.');
				$this->assertSame([], $mapping['mapping'], $mappingSlug . ' renames or reshapes no field.');
				$this->assertSame([], $mapping['cast'], $mappingSlug . ' casts no field.');
			}
		}
	}//end testEveryMappingPassesTheStoresOwnShapeThroughUntranslated()
}//end class
