<?php

/**
 * The palette metadata every Integriq flow node shares.
 *
 * A node is identified by its NODE_ID constant, is offered in the admin and
 * user scopes, and shows the icon named by its PALETTE_ICON constant
 * (`[app, image path]`). A using class provides `$urlGenerator`, and may
 * declare its own getIcon() instead of PALETTE_ICON.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use OCP\WorkflowEngine\IManager;

trait FlowNodePaletteDefaults {

	/**
	 * The step type.
	 *
	 * @return string The type identifier.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(self::PALETTE_ICON[0], self::PALETTE_ICON[1]);
	}//end getIcon()

	/**
	 * The node is offered in the admin and the user scope.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()
}//end trait
