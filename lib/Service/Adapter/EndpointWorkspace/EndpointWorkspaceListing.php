<?php

/**
 * EndpointWorkspaceListing: what an administrator sees of the workspace manager behind a source.
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
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\EndpointWorkspace;

use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * The response to an administrator asking what a source's workspace manager
 * reports: the caller is checked first, then the source, its adapter and its
 * credential, and only then is the vendor called.
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
 */
class EndpointWorkspaceListing {

	/**
	 * Constructor.
	 *
	 * @param IUserSession          $userSession  The session, for the admin check.
	 * @param IGroupManager         $groupManager The admin check.
	 * @param WorkspaceSourceReader $sources      The source's configuration and what its workspace manager reports.
	 * @param IL10N                 $l            Translations.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly WorkspaceSourceReader $sources,
		private readonly IL10N $l,
	) {
	}//end __construct()

	/**
	 * The HTTP status and body for the entries of one source.
	 *
	 * @param string $id The source's uuid.
	 *
	 * @return array{0: int, 1: array<string, mixed>}
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function forSource(string $id): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [Http::STATUS_UNAUTHORIZED, ['error' => $this->l->t('Not authenticated')]];
		}

		if ($this->groupManager->isAdmin($user->getUID()) !== true) {
			return [Http::STATUS_FORBIDDEN, ['error' => $this->l->t('Only an administrator can see the sessions and devices of a source.')]];
		}

		$configuration = $this->sources->configurationOf(id: $id);
		if ($configuration === null) {
			return [Http::STATUS_NOT_FOUND, ['error' => $this->l->t('Not Found')]];
		}

		$adapterId = (string)($configuration['workspaceAdapter'] ?? '');
		$kind = $this->sources->kindOf(adapterId: $adapterId);
		if ($kind === null) {
			return [Http::STATUS_BAD_REQUEST, ['error' => $this->l->t('This source is not connected to a workspace manager.')]];
		}

		$entries = $this->sources->entriesOf(adapterId: $adapterId, settings: $configuration);
		if ($entries === null) {
			return [
				Http::STATUS_OK,
				[
					'adapter' => $adapterId,
					'kind' => $kind,
					'credentialMissing' => true,
					'message' => $this->l->t('This source has no credential yet, so nothing was called. Add a credential to see what it reports.'),
					'entries' => [],
				],
			];
		}

		$listed = $this->sources->withUsers(entries: $entries, configuration: $configuration);

		return [Http::STATUS_OK, ['adapter' => $adapterId, 'kind' => $kind, 'credentialMissing' => false, 'message' => null, 'entries' => $listed]];
	}//end forSource()
}//end class
