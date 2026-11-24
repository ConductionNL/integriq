<?php

/**
 * WorkspaceAdapterSet: the workspace-manager adapters a source can name, and what each reports.
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
 * @spec openspec/changes/archive/2026-11-24-connectors-endpoint-workspace-adapters/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\EndpointWorkspace;

/**
 * Resolves the adapter id a source names in `configuration.workspaceAdapter`
 * (`intune`, `jamf-pro`, `citrix-daas` or `azure-virtual-desktop`) to the kind
 * of entry it reports and to the entries themselves.
 *
 * @spec openspec/changes/archive/2026-11-24-connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
 */
class WorkspaceAdapterSet {

	/**
	 * Constructor.
	 *
	 * @param IntuneAdapter              $intune     The Intune adapter.
	 * @param JamfProAdapter             $jamfPro    The Jamf Pro adapter.
	 * @param CitrixDaasAdapter          $citrixDaas The Citrix DaaS adapter.
	 * @param AzureVirtualDesktopAdapter $avd        The Azure Virtual Desktop adapter.
	 */
	public function __construct(
		private readonly IntuneAdapter $intune,
		private readonly JamfProAdapter $jamfPro,
		private readonly CitrixDaasAdapter $citrixDaas,
		private readonly AzureVirtualDesktopAdapter $avd,
	) {
	}//end __construct()

	/**
	 * `device` or `session` for a known adapter id, else null.
	 *
	 * @param string $adapterId The adapter id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function kindOf(string $adapterId): ?string {
		if ($adapterId === $this->avd->getId()) {
			return AbstractWorkspaceAdapter::KIND_SESSION;
		}

		return $this->workspaceAdapter(adapterId: $adapterId)?->entryKind();
	}//end kindOf()

	/**
	 * The entries, or null when the adapter has no credential.
	 *
	 * @param string               $adapterId The adapter id.
	 * @param array<string, mixed> $settings  The source's configuration.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function entriesOf(string $adapterId, array $settings): ?array {
		$adapter = $this->workspaceAdapter(adapterId: $adapterId);
		if ($adapter !== null) {
			return $adapter->listEntries(settings: $settings);
		}

		if ($this->avd->isEnabled() === false) {
			return null;
		}

		return array_values(
			array_map(
				self::sessionEntry(...),
				$this->avd->list(register: '', schema: '', objectId: '', filters: $settings)
			)
		);
	}//end entriesOf()

	/**
	 * One Azure Virtual Desktop session as a workspace entry.
	 *
	 * @param array<string, mixed> $session The session as the adapter lists it.
	 *
	 * @return array<string, mixed>
	 */
	private static function sessionEntry(array $session): array {
		return [
			'kind' => AbstractWorkspaceAdapter::KIND_SESSION,
			'id' => ($session['id'] ?? null),
			'name' => null,
			'remoteUser' => ($session['userPrincipalName'] ?? null),
			'email' => null,
			'lastSeen' => ($session['createTime'] ?? null),
			'state' => ($session['sessionState'] ?? null),
		];
	}//end sessionEntry()

	/**
	 * The device-or-session adapter with that id, or null.
	 *
	 * @param string $adapterId The adapter id.
	 *
	 * @return AbstractWorkspaceAdapter|null
	 */
	private function workspaceAdapter(string $adapterId): ?AbstractWorkspaceAdapter {
		foreach ([$this->intune, $this->jamfPro, $this->citrixDaas] as $adapter) {
			if ($adapter->getId() === $adapterId) {
				return $adapter;
			}
		}

		return null;
	}//end workspaceAdapter()
}//end class
