<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Settings\GenericAdminSettings.
 *
 * The real class lives in the peer OpenRegister app (not in vendor). Mirrors
 * the public constructor + IDelegatedSettings surface so unit tests can
 * construct/assert against the leaf `IntegriqAdmin` subclass without a
 * full Nextcloud + OpenRegister install (adopt-apphost change).
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\Settings\IDelegatedSettings;

/**
 * Minimal stand-in mirroring the engine's generic admin settings form.
 */
class GenericAdminSettings implements IDelegatedSettings {
	protected readonly string $appId;
	protected readonly string $sectionId;
	protected readonly int $priority;
	protected readonly IAppManager $appManager;
	protected readonly IInitialState $initialState;
	protected readonly ?IAppConfig $appConfig;

	/**
	 * Construct the admin settings panel.
	 *
	 * @param SettingsPlacement $placement App id, section id and priority.
	 * @param AdminFormServices $services  App manager, initial state and app config.
	 */
	public function __construct(SettingsPlacement $placement, AdminFormServices $services) {
		$this->appId        = $placement->appId;
		$this->sectionId    = $placement->sectionId;
		$this->priority     = $placement->priority;
		$this->appManager   = $services->appManager;
		$this->initialState = $services->initialState;
		$this->appConfig    = $services->appConfig;
	}//end __construct()

	/**
	 * Get the settings form template.
	 *
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		return new TemplateResponse($this->appId, 'settings/admin', []);
	}//end getForm()

	/**
	 * Section id this settings page belongs to.
	 *
	 * @return string
	 */
	public function getSection(): string {
		return $this->sectionId;
	}//end getSection()

	/**
	 * Ordering priority within the section.
	 *
	 * @return int
	 */
	public function getPriority(): int {
		return $this->priority;
	}//end getPriority()

	/**
	 * Human-readable name of the delegated settings section.
	 *
	 * @return string|null
	 */
	public function getName(): ?string {
		return null;
	}//end getName()

	/**
	 * App-config keys a delegated admin may manage. Empty = full-admin only.
	 *
	 * @return array<string, string[]>
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}//end getAuthorizedAppConfig()
}//end class
