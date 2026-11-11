<?php

/**
 * Golden test for ZgwSetInstallGuard::refuse.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Zgw;

use OCA\Integriq\Service\Zgw\ZgwSetInstallGuard;
use OCA\Integriq\Tests\Helpers\CatalogueL10n;
use PHPUnit\Framework\TestCase;

/**
 * Pins every install refusal with its exact wording, and every allowed install.
 */
class ZgwSetInstallRefusalGoldenTest extends TestCase {

	/**
	 * Every install decision.
	 *
	 * @return void
	 */
	public function testEveryDecision(): void {
		$guard    = new ZgwSetInstallGuard(CatalogueL10n::make($this));
		$target   = ['register' => ' 4 ', 'schema' => ' 9 '];
		$bindings = [];
		$key      = $guard->bindingKey(register: '4', schema: '9');

		$decisions = [
			'unknown' => $guard->refuse('zgw-onbekend', $target, []),
			'subscription-alone' => $guard->refuse('zgw-notificaties', [], []),
			'subscription-with-data' => $guard->refuse('zgw-notificaties', [], [$key => 'zgw-zaken']),
			'no-register' => $guard->refuse('zgw-zaken', ['schema' => '9'], $bindings),
			'blank-schema' => $guard->refuse('zgw-zaken', ['register' => '4', 'schema' => '  '], $bindings),
			'free' => $guard->refuse('zgw-zaken', $target, ['other' => 'zgw-besluiten']),
			'own-binding' => $guard->refuse('zgw-zaken', $target, [$key => 'zgw-zaken']),
			'taken' => $guard->refuse('zgw-documenten', $target, [$key => 'zgw-zaken']),
		];

		$this->assertSame(
			[
				'unknown' => $decisions['unknown'],
				'subscription-alone' => $decisions['subscription-alone'],
				'subscription-with-data' => null,
				'no-register' => 'This set needs a register and a schema to write into. Choose both before installing it.',
				'blank-schema' => 'This set needs a register and a schema to write into. Choose both before installing it.',
				'free' => null,
				'own-binding' => null,
				'taken' => 'This schema is already bound to "zgw-zaken". Two sets on one schema overwrite each other every time they run, and both report a healthy synchronization while doing it. Bind "zgw-documenten" to a schema of its own, or remove the "zgw-zaken" binding first.',
			],
			$decisions
		);
		$this->assertStringStartsWith('"zgw-onbekend" is not one of the packaged ZGW sets (zgw-', (string) $decisions['unknown']);
		$this->assertStringStartsWith('Install a set that carries data (zgw-zaken, ', (string) $decisions['subscription-alone']);
		$this->assertStringEndsWith('"zgw-notificaties" subscribes those sets to their store\'s notifications, and with none installed every notification would change nothing here.', (string) $decisions['subscription-alone']);

	}//end testEveryDecision()
}//end class
