<?php

/**
 * OpenConnector Admin Settings — AppHost adapter tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Settings\OpenConnectorAdmin;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pins the auth-critical semantics of the AppHost-adopted admin settings.
 *
 * `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]` gates ~30 controller
 * methods across the app; this test verifies the adopted class keeps the
 * exact fail-closed (full-admin-only) posture of the pre-adoption bespoke
 * implementation it replaces.
 */
class OpenConnectorAdminTest extends TestCase {
	/**
	 * Build a settings instance with mocked collaborators.
	 *
	 * @return OpenConnectorAdmin
	 */
	private function makeSettings(): OpenConnectorAdmin {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.2.21');

		return new OpenConnectorAdmin(
			appId: 'openconnector',
			sectionId: 'openconnector',
			priority: 10,
			appManager: $appManager,
			initialState: $this->createMock(IInitialState::class),
			appConfig: $this->createMock(IAppConfig::class)
		);
	}//end makeSettings()

	/**
	 * `getAuthorizedAppConfig()` must stay empty — byte-identical to the
	 * pre-adoption bespoke implementation — so every `#[AuthorizedAdminSetting]`
	 * gate keeps scoping to full admins only (no delegated sub-keys).
	 *
	 * @return void
	 */
	public function testAuthorizedAppConfigStaysEmpty(): void {
		$this->assertSame([], $this->makeSettings()->getAuthorizedAppConfig());
	}//end testAuthorizedAppConfigStaysEmpty()

	/**
	 * The section id must stay `openconnector`, matching the pre-adoption value.
	 *
	 * @return void
	 */
	public function testSectionIdUnchanged(): void {
		$this->assertSame('openconnector', $this->makeSettings()->getSection());
	}//end testSectionIdUnchanged()

	/**
	 * The priority must stay `10`, matching the pre-adoption value.
	 *
	 * @return void
	 */
	public function testPriorityUnchanged(): void {
		$this->assertSame(10, $this->makeSettings()->getPriority());
	}//end testPriorityUnchanged()
}//end class
