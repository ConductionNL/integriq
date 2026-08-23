<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Settings\GenericSettingsSection.
 *
 * The real class lives in the peer OpenRegister app (not in vendor). Mirrors
 * the public constructor + IIconSection surface so unit tests can construct/
 * assert against the leaf `IntegriqAdmin` (Sections) subclass without a
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

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Minimal stand-in mirroring the engine's generic admin settings section.
 */
class GenericSettingsSection implements IIconSection {
	/**
	 * Constructor.
	 *
	 * @param string $sectionId The section identifier.
	 * @param string $name Display name.
	 * @param string $appId Owning app id (for the icon path).
	 * @param string $iconFile Icon file name.
	 * @param int $priority Section ordering priority.
	 * @param IURLGenerator $urlGenerator URL generator.
	 */
	public function __construct(
		protected readonly string $sectionId,
		protected readonly string $name,
		protected readonly string $appId,
		protected readonly string $iconFile,
		protected readonly int $priority,
		protected readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * The section identifier.
	 *
	 * @return string
	 */
	public function getID(): string {
		return $this->sectionId;
	}//end getID()

	/**
	 * The display name of this section.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}//end getName()

	/**
	 * The ordering priority for this section.
	 *
	 * @return int
	 */
	public function getPriority(): int {
		return $this->priority;
	}//end getPriority()

	/**
	 * The icon path for this section.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath($this->appId, $this->iconFile);
	}//end getIcon()
}//end class
