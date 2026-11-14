<?php

/**
 * Microsoft Intune endpoint/workspace adapter: managed devices over Microsoft Graph.
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
 * Lists Intune managed devices with their owner, last check-in and compliance.
 *
 * The brokered credential's base URL is Microsoft Graph
 * (`https://graph.microsoft.com`); the path is relative to it.
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
 */
class IntuneAdapter extends AbstractWorkspaceAdapter {

	/**
	 * The fields asked of Graph.
	 */
	private const SELECT = 'id,deviceName,userPrincipalName,emailAddress,lastSyncDateTime,complianceState,operatingSystem';

	/**
	 * Graph puts the rows under `value`.
	 */
	protected const ROWS_KEY = 'value';

	/**
	 * The provider id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function getId(): string {
		return 'intune';
	}//end getId()

	/**
	 * The provider label.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function getLabel(): string {
		return $this->l10n->t('Microsoft Intune');
	}//end getLabel()

	/**
	 * Device-side capabilities only (REQ-EWC-002).
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function getCapabilities(): array {
		return ['device-inventory', 'device-compliance', 'user-mapping'];
	}//end getCapabilities()

	/**
	 * The Graph managed-devices request.
	 *
	 * @param array<string, mixed> $settings Unused: the tenant is the credential's.
	 *
	 * @return array{path: string, headers: array<string, string>}
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class's signature
	 */
	protected function listRequest(array $settings): ?array {
		return self::jsonRequest(path: '/v1.0/deviceManagement/managedDevices?$select=' . self::SELECT);
	}//end listRequest()

	/**
	 * One managed device as an entry.
	 *
	 * @param array<string, mixed> $row The Graph managedDevice.
	 *
	 * @return array<string, mixed>
	 */
	protected function entryOf(array $row): array {
		return [
			'id' => self::stringOrNull(value: ($row['id'] ?? null)),
			'name' => self::stringOrNull(value: ($row['deviceName'] ?? null)),
			'remoteUser' => self::stringOrNull(value: ($row['userPrincipalName'] ?? null)),
			'email' => self::stringOrNull(value: ($row['emailAddress'] ?? null)),
			'lastSeen' => self::stringOrNull(value: ($row['lastSyncDateTime'] ?? null)),
			'state' => self::stringOrNull(value: ($row['complianceState'] ?? null)),
		];
	}//end entryOf()
}//end class
