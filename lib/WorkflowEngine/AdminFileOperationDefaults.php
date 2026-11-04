<?php

/**
 * The palette metadata and settings decoding every Integriq WorkflowEngine operation shares.
 *
 * Each operation acts on the file entity, shows the app icon, is offered to
 * admins only, and stores its settings as one JSON object in the flow's
 * `operation` string. A using class provides `$urlGenerator`.
 *
 * @category WorkflowEngine
 * @package  OCA\Integriq\WorkflowEngine
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
 * @spec openspec/specs/flow-workflowengine-operations/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\WorkflowEngine;

use OCA\Integriq\Kernel\AppIdentity;
use OCP\WorkflowEngine\IManager;
use Throwable;

trait AdminFileOperationDefaults {

	/**
	 * The entity the operation acts on.
	 *
	 * Referenced only as a compile-time ::class string: NC core's
	 * `workflowengine` app ships this class but it is never instantiated or
	 * type-hinted here, so no autoload is triggered even when the bundled
	 * `workflowengine` app is disabled (design.md Decision 1 / discovery.md
	 * finding 6).
	 *
	 * @return string The entity class name.
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md
	 */
	public function getEntityId(): string {
		return \OCA\WorkflowEngine\Entity\File::class;
	}//end getEntityId()

	/**
	 * Palette icon: the app icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(AppIdentity::APP_ID, 'app.svg');
	}//end getIcon()

	/**
	 * The operation is offered in the admin scope only.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return $scope === IManager::SCOPE_ADMIN;
	}//end isAvailableForScope()

	/**
	 * Decode the flow's operation string into its settings object.
	 *
	 * @param string $operation The stored operation string.
	 *
	 * @return array<string, mixed>|null The settings, or null when the string is not a JSON object.
	 */
	private function decodeOperationJson(string $operation): ?array {
		try {
			$settings = json_decode($operation, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($settings) === false) {
			return null;
		}

		return $settings;
	}//end decodeOperationJson()
}//end trait
