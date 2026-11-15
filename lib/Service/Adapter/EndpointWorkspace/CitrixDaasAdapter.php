<?php

/**
 * Citrix DaaS endpoint/workspace adapter: user sessions over the Citrix DaaS REST API.
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
 * Lists Citrix DaaS sessions with their user, machine, state and start time.
 *
 * The brokered credential's base URL is the Citrix Cloud API
 * (`https://api.cloud.com`); the customer and site come from the source's
 * settings (`customerId`, `siteId`) and travel as the Citrix headers.
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
 */
class CitrixDaasAdapter extends AbstractWorkspaceAdapter {

	/**
	 * Citrix DaaS lists sessions.
	 */
	protected const ENTRY_KIND = self::KIND_SESSION;

	/**
	 * Citrix puts the rows under `Items`.
	 */
	protected const ROWS_KEY = 'Items';

	/**
	 * The provider icon.
	 */
	protected const ICON = 'Monitor';

	/**
	 * The provider id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function getId(): string {
		return 'citrix-daas';
	}//end getId()

	/**
	 * The provider label.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function getLabel(): string {
		return $this->l10n->t('Citrix DaaS');
	}//end getLabel()

	/**
	 * Session enumeration and user mapping (REQ-EWC-002).
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function getCapabilities(): array {
		return ['session-enumerate', 'user-mapping'];
	}//end getCapabilities()

	/**
	 * The sessions request, or null without a customer and site.
	 *
	 * @param array<string, mixed> $settings The source's `customerId` and `siteId`.
	 *
	 * @return array{path: string, headers: array<string, string>}|null
	 */
	protected function listRequest(array $settings): ?array {
		$customerId = self::setting(settings: $settings, key: 'customerId');
		$siteId = self::setting(settings: $settings, key: 'siteId');
		if ($customerId === null || $siteId === null) {
			return null;
		}

		return self::jsonRequest(
			path: '/cvad/manage/Sessions',
			headers: ['Citrix-CustomerId' => $customerId, 'Citrix-InstanceId' => $siteId]
		);
	}//end listRequest()

	/**
	 * One session as an entry.
	 *
	 * @param array<string, mixed> $row The Citrix session.
	 *
	 * @return array<string, mixed>
	 */
	protected function entryOf(array $row): array {
		$user = self::arrayOf(value: ($row['User'] ?? null));
		$machine = self::arrayOf(value: ($row['Machine'] ?? null));

		return [
			'id' => self::stringOrNull(value: ($row['Id'] ?? null)),
			'name' => self::stringOrNull(value: ($machine['Name'] ?? null)),
			'remoteUser' => (self::stringOrNull(value: ($user['PrincipalName'] ?? null)) ?? self::stringOrNull(value: ($user['SamName'] ?? null))),
			'email' => self::stringOrNull(value: ($user['Mail'] ?? null)),
			'lastSeen' => self::stringOrNull(value: ($row['StartTime'] ?? null)),
			'state' => self::stringOrNull(value: ($row['SessionState'] ?? null)),
		];
	}//end entryOf()
}//end class
