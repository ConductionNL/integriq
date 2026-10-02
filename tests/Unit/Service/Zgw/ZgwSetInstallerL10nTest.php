<?php

/**
 * An operator meets the installer's refusals in their own language.
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

use OCA\Integriq\Exception\ZgwSetInstallRefusedException;
use OCA\Integriq\Service\NotificatiesSubscriberService;
use OCA\Integriq\Service\Zgw\ZgwSetInstaller;
use OCA\Integriq\Service\Zgw\ZgwSetInstallGuard;
use OCA\Integriq\Tests\Helpers\CatalogueL10n;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The guard and the installer translate every refusal an operator can meet, through the
 * app's real nl and en catalogues (task 4).
 */
class ZgwSetInstallerL10nTest extends TestCase {

	/**
	 * Every refusal the operator can meet, produced in the given language.
	 *
	 * @param string $language The catalogue.
	 *
	 * @return array<string, string> Path name to refusal text.
	 */
	private function refusals(string $language): array {
		$guard = new ZgwSetInstallGuard(l10n: CatalogueL10n::make($this, $language));
		$out   = [
			'unknown set'     => (string)$guard->refuse(slug: 'zgw-klanten', target: [], bindings: []),
			'nothing to feed' => (string)$guard->refuse(slug: 'zgw-notificaties', target: [], bindings: []),
			'no target'       => (string)$guard->refuse(slug: 'zgw-zaken', target: ['register' => ' ', 'schema' => ''], bindings: []),
			'already bound'   => (string)$guard->refuse(slug: 'zgw-besluiten', target: ['register' => 'cases', 'schema' => 'case'], bindings: ['cases/case' => 'zgw-zaken']),
		];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new \RuntimeException('not seeded'));

		$installers = [
			'no set file'        => [sys_get_temp_dir() . '/integriq-no-sets-' . uniqid(), 'zgw-zaken'],
			'no synchronization' => [null, 'zgw-zaken'],
			'no source'          => [null, 'zgw-notificaties'],
		];
		foreach ($installers as $path => [$directory, $slug]) {
			$arguments = [
				'objectService' => $objects,
				'guard'         => $guard,
				'subscriber'    => $this->createMock(NotificatiesSubscriberService::class),
				'l10n'          => CatalogueL10n::make($this, $language),
			];
			if ($directory !== null) {
				$arguments['setDirectory'] = $directory;
			}

			$bindings = $slug === 'zgw-notificaties' ? '{"cases/case":"zgw-zaken"}' : '';
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn($bindings);
			$arguments['appConfig'] = $appConfig;

			try {
				(new ZgwSetInstaller(...$arguments))->install(slug: $slug, register: 'cases', schema: 'case');
				$this->fail($path . ' must be refused.');
			} catch (ZgwSetInstallRefusedException $e) {
				$out[$path] = $e->getMessage();
			}
		}

		return $out;
	}//end refusals()

	/**
	 * A Dutch operator reads every refusal in Dutch, with the set and schema names filled in.
	 *
	 * @return void
	 */
	public function testEveryRefusalReadsInDutch(): void {
		$english = $this->refusals('en');
		$dutch   = $this->refusals('nl');

		$this->assertCount(7, $dutch);
		foreach ($dutch as $path => $text) {
			$this->assertNotSame($english[$path], $text, $path . ' is still English in Dutch.');
			$this->assertStringNotContainsString('%', $text, $path . ' left a placeholder unfilled.');
		}

		$this->assertStringContainsString('zgw-zaken', $dutch['already bound'], 'The refusal names the set that holds the schema.');
		$this->assertStringContainsString('zgw-besluiten', $dutch['already bound']);
		$this->assertStringContainsString('zgw-klanten', $dutch['unknown set']);
	}//end testEveryRefusalReadsInDutch()

	/**
	 * Every string the installer asks for is a key in both backend catalogues, so no language falls back silently.
	 *
	 * @return void
	 */
	public function testEveryAskedStringIsInTheEnglishAndDutchCatalogue(): void {
		CatalogueL10n::$asked = [];
		$this->refusals('en');
		$asked = array_values(array_unique(CatalogueL10n::$asked));
		$this->assertNotEmpty($asked);

		foreach (['en', 'nl'] as $language) {
			$path      = dirname(__DIR__, 4) . '/l10n/' . $language . '.json';
			$catalogue = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['translations'];
			foreach ($asked as $text) {
				$this->assertNotSame('', trim((string)($catalogue[$text] ?? '')), $language . '.json lacks: ' . $text);
			}
		}
	}//end testEveryAskedStringIsInTheEnglishAndDutchCatalogue()
}//end class
