<?php

/**
 * Installing a packaged ZGW set against an operator-chosen register and schema.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Zgw;

use OCA\Integriq\Service\Zgw\ZgwSetInstaller;
use OCA\Integriq\Service\Zgw\ZgwSetInstallGuard;
use OCA\Integriq\Service\Zgw\ZgwSetInstallRefusedException;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The installer runs both guards, then binds the set's seeded synchronizations
 * (the real ones from register.d/zgw-consumer-sets.json) to the chosen schema
 * and records the binding the guard reads next time.
 */
class ZgwSetInstallerTest extends TestCase {

	/** @var array<string, array<string, mixed>> Seeded synchronizations by slug. */
	private array $syncs = [];

	/** @var array<int, array{slug: string, object: array<string, mixed>}> What the installer saved. */
	private array $saved = [];

	/** @var array<string, string> App config values. */
	private array $config = [];

	protected function setUp(): void {
		$path     = dirname(__DIR__, 4) . '/lib/Settings/register.d/zgw-consumer-sets.json';
		$fragment = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		foreach ($fragment['components']['objects'] as $object) {
			if ($object['@self']['schema'] !== 'synchronization') {
				continue;
			}

			$slug = $object['@self']['slug'];
			unset($object['@self']);
			$this->syncs[$slug] = $object;
		}
	}//end setUp()

	/**
	 * The installer over an in-memory synchronization store and app config.
	 *
	 * @return ZgwSetInstaller
	 */
	private function installer(): ZgwSetInstaller {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, ?string $register=null, ?string $schema=null) {
				if ($register !== 'integriq' || $schema !== 'synchronization' || isset($this->syncs[$id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('uuid-' . $id);
				$entity->setObject($this->syncs[$id]);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null) {
				$this->assertSame('integriq', $register);
				$this->assertSame('synchronization', $schema);
				$slug               = substr((string)$uuid, strlen('uuid-'));
				$this->syncs[$slug] = $object;
				$this->saved[]      = ['slug' => $slug, 'object' => $object];
				return new ObjectEntity();
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default='') => ($this->config[$app . '/' . $key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) {
				$this->config[$app . '/' . $key] = $value;
				return true;
			}
		);

		return new ZgwSetInstaller($objects, $appConfig, new ZgwSetInstallGuard());
	}//end installer()

	/**
	 * Install, returning the refusal text or null.
	 *
	 * @param string $slug     The set.
	 * @param string $register The target register.
	 * @param string $schema   The target schema.
	 *
	 * @return string|null
	 */
	private function refusal(string $slug, string $register, string $schema): ?string {
		try {
			$this->installer()->install(slug: $slug, register: $register, schema: $schema);
			return null;
		} catch (ZgwSetInstallRefusedException $e) {
			return $e->getMessage();
		}
	}//end refusal()

	/**
	 * An operator binds zaken to the case schema: the pull writes there, the push reads there.
	 *
	 * @return void
	 */
	public function testInstallingZakenBindsThePullAndThePushToTheChosenSchema(): void {
		$result = $this->installer()->install(slug: 'zgw-zaken', register: 'cases', schema: 'case');

		$this->assertSame(['zgw-zaken-pull', 'zgw-zaken-push'], $result['synchronizations']);
		$this->assertSame('register/schema', $this->syncs['zgw-zaken-pull']['targetType']);
		$this->assertSame('cases/case', $this->syncs['zgw-zaken-pull']['targetId']);
		$this->assertSame('register/schema', $this->syncs['zgw-zaken-push']['sourceType']);
		$this->assertSame('cases/case', $this->syncs['zgw-zaken-push']['sourceId']);
		$this->assertSame(['cases/case' => 'zgw-zaken'], $this->installer()->bindings());

		$this->assertCount(2, $this->saved);
		foreach ($this->saved as $save) {
			$this->assertSame([], RegisterSchemaValidator::errors('synchronization', $save['object']), $save['slug']);
		}
	}//end testInstallingZakenBindsThePullAndThePushToTheChosenSchema()

	/**
	 * A second set on the same schema is refused, names the holder, and saves nothing.
	 *
	 * @return void
	 */
	public function testASecondSetOnTheSameSchemaIsRefusedNamingTheHolder(): void {
		$this->installer()->install(slug: 'zgw-zaken', register: 'cases', schema: 'case');
		$this->saved = [];

		$refusal = $this->refusal('zgw-objecten', 'cases', 'case');

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('"zgw-zaken"', $refusal);
		$this->assertSame([], $this->saved);
		$this->assertSame('', $this->syncs['zgw-objecten-pull']['targetId']);
	}//end testASecondSetOnTheSameSchemaIsRefusedNamingTheHolder()

	/**
	 * Without a register or schema nothing is saved.
	 *
	 * @return void
	 */
	public function testWithoutATargetNothingIsSaved(): void {
		$this->assertStringContainsString('register and a schema', (string)$this->refusal('zgw-zaken', 'cases', ''));
		$this->assertSame([], $this->saved);
	}//end testWithoutATargetNothingIsSaved()

	/**
	 * A set that is not packaged is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownSetIsRefused(): void {
		$this->assertStringContainsString('not one of the packaged ZGW sets', (string)$this->refusal('zgw-verzoeken', 'cases', 'case'));
	}//end testAnUnknownSetIsRefused()

	/**
	 * A seeded synchronization that is missing on this instance is named, and nothing half-binds.
	 *
	 * @return void
	 */
	public function testAMissingSynchronizationIsNamedAndNothingIsSaved(): void {
		unset($this->syncs['zgw-zaken-push']);

		$refusal = $this->refusal('zgw-zaken', 'cases', 'case');

		$this->assertStringContainsString('"zgw-zaken-push"', (string)$refusal);
		$this->assertSame([], $this->saved, 'The pull must not be bound when the push cannot be.');
		$this->assertSame([], $this->installer()->bindings());
	}//end testAMissingSynchronizationIsNamedAndNothingIsSaved()

	/**
	 * Installing a set again on another schema moves its binding; the old schema is free.
	 *
	 * @return void
	 */
	public function testInstallingAgainMovesTheBinding(): void {
		$this->installer()->install(slug: 'zgw-zaken', register: 'cases', schema: 'case');
		$this->installer()->install(slug: 'zgw-zaken', register: 'cases', schema: 'zaak');

		$this->assertSame(['cases/zaak' => 'zgw-zaken'], $this->installer()->bindings());
		$this->assertSame('cases/zaak', $this->syncs['zgw-zaken-pull']['targetId']);
		$this->assertNull($this->refusal('zgw-objecten', 'cases', 'case'));
	}//end testInstallingAgainMovesTheBinding()
}//end class
