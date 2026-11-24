<?php

/**
 * WorkspaceSourceReader: a source's workspace configuration, and its entries with the users they map to.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Reads a source's configuration from the register and lists what its
 * workspace manager reports, each entry with the Nextcloud user it maps to.
 *
 * @spec openspec/changes/archive/2026-11-24-connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
 */
class WorkspaceSourceReader {

	/**
	 * Constructor.
	 *
	 * @param OrObjectService      $objectService OpenRegister's object service.
	 * @param WorkspaceAdapterSet  $adapters      The workspace-manager adapters.
	 * @param WorkspaceUserMatcher $users         Maps entries to Nextcloud users.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly WorkspaceAdapterSet $adapters,
		private readonly WorkspaceUserMatcher $users,
	) {
	}//end __construct()

	/**
	 * The source's configuration, [] when it has none, null when there is no such source.
	 *
	 * @param string $id The source's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function configurationOf(string $id): ?array {
		try {
			$source = $this->objectService->find(
				id: $id,
				options: new ObjectFindOptions(register: 'integriq', schema: 'source'),
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException) {
			return null;
		}

		if (($source instanceof ObjectEntity) === false) {
			return null;
		}

		$configuration = ($source->getObject()['configuration'] ?? null);
		if (is_array($configuration) === true) {
			return $configuration;
		}

		return [];
	}//end configurationOf()

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
		return $this->adapters->kindOf(adapterId: $adapterId);
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
		return $this->adapters->entriesOf(adapterId: $adapterId, settings: $settings);
	}//end entriesOf()

	/**
	 * Every entry with the user it maps to under the source's `userMapping`.
	 *
	 * @param array<int, array<string, mixed>> $entries       The entries the adapter reported.
	 * @param array<string, mixed>             $configuration The source's configuration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-11-24-connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function withUsers(array $entries, array $configuration): array {
		$mapping = ($configuration['userMapping'] ?? []);
		if (is_array($mapping) === false) {
			$mapping = [];
		}

		$listed = [];
		foreach ($entries as $entry) {
			$matched = $this->users->match(entry: $entry, mapping: $mapping);
			$listed[] = $entry + ['user' => $matched, 'userFound' => ($matched !== null)];
		}

		return $listed;
	}//end withUsers()
}//end class
