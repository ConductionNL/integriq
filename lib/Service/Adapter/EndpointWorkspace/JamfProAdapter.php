<?php

/**
 * Jamf Pro endpoint/workspace adapter: computer inventory over the Jamf Pro API.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\EndpointWorkspace
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\EndpointWorkspace;

/**
 * Lists Jamf Pro computers with their assigned user and last contact.
 *
 * The brokered credential's base URL is the Jamf Pro instance
 * (`https://<tenant>.jamfcloud.com`); the path is relative to it.
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
 */
class JamfProAdapter extends AbstractWorkspaceAdapter {

	/**
	 * Computers asked per page.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Jamf puts the rows under `results`.
	 */
	protected const ROWS_KEY = 'results';

	/**
	 * The provider id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function getId(): string {
		return 'jamf-pro';
	}//end getId()

	/**
	 * The provider label.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function getLabel(): string {
		return $this->l10n->t('Jamf Pro');
	}//end getLabel()

	/**
	 * Device inventory and user mapping (REQ-EWC-002).
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function getCapabilities(): array {
		return ['device-inventory', 'user-mapping'];
	}//end getCapabilities()

	/**
	 * The computers-inventory request, general and user sections only.
	 *
	 * @param array<string, mixed> $settings Unused: the instance is the credential's.
	 *
	 * @return array{path: string, headers: array<string, string>}
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class's signature
	 */
	protected function listRequest(array $settings): ?array {
		return self::jsonRequest(path: '/api/v1/computers-inventory?section=GENERAL&section=USER_AND_LOCATION&page=0&page-size=' . self::PAGE_SIZE);
	}//end listRequest()

	/**
	 * One computer as an entry.
	 *
	 * @param array<string, mixed> $row The Jamf computer inventory record.
	 *
	 * @return array<string, mixed>
	 */
	protected function entryOf(array $row): array {
		$general = self::arrayOf(value: ($row['general'] ?? null));
		$user = self::arrayOf(value: ($row['userAndLocation'] ?? null));

		$id = ($row['id'] ?? null);
		if (is_int($id) === true) {
			$id = (string)$id;
		}

		return [
			'id' => self::stringOrNull(value: $id),
			'name' => self::stringOrNull(value: ($general['name'] ?? null)),
			'remoteUser' => self::stringOrNull(value: ($user['username'] ?? null)),
			'email' => self::stringOrNull(value: ($user['email'] ?? null)),
			'lastSeen' => self::stringOrNull(value: ($general['lastContactTime'] ?? null)),
			'state' => null,
		];
	}//end entryOf()
}//end class
