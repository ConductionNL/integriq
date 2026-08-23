<?php

/**
 * Integriq — OpenRegister dependency setup-check tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\SetupCheck
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\SetupCheck;

use OCA\Integriq\SetupCheck\OpenRegisterDependencyCheck;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-ADM-003: the boot guard raises an admin notice when
 * OpenRegister is disabled and is silent when it is enabled.
 *
 * @spec openspec/changes/licence-and-or-requirement-honesty/specs/app-distribution-metadata/spec.md
 */
class OpenRegisterDependencyCheckTest extends TestCase {
	/**
	 * Build the check with an IAppManager mock reporting the given state.
	 *
	 * @param bool $installed Whether openregister reports as installed/enabled.
	 *
	 * @return OpenRegisterDependencyCheck
	 */
	private function makeCheck(bool $installed): OpenRegisterDependencyCheck {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->with('openregister')->willReturn($installed);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new OpenRegisterDependencyCheck($appManager, $l10n);
	}//end makeCheck()

	/**
	 * When OpenRegister is disabled the check reports an error naming it.
	 *
	 * @return void
	 */
	public function testErrorWhenOpenRegisterDisabled(): void {
		$result = $this->makeCheck(false)->run();

		$this->assertSame(SetupResult::ERROR, $result->getSeverity());
		$this->assertStringContainsString('OpenRegister', (string)$result->getDescription());
	}//end testErrorWhenOpenRegisterDisabled()

	/**
	 * When OpenRegister is enabled the check is silent (success).
	 *
	 * @return void
	 */
	public function testSuccessWhenOpenRegisterEnabled(): void {
		$result = $this->makeCheck(true)->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}//end testSuccessWhenOpenRegisterEnabled()
}//end class
