<?php

/**
 * EndpointWorkspaceController: the sessions or devices a workspace-manager source reports.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Adapter\EndpointWorkspace\AbstractWorkspaceAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\AzureVirtualDesktopAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\CitrixDaasAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\IntuneAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\JamfProAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\WorkspaceUserMatcher;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Lists what the workspace manager behind a source reports, each entry with
 * the Nextcloud user it maps to or none.
 *
 * The source names its adapter in `configuration.workspaceAdapter`
 * (`intune`, `jamf-pro`, `citrix-daas` or `azure-virtual-desktop`); the rest
 * of `configuration` holds the vendor settings and an optional `userMapping`.
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
 */
class EndpointWorkspaceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                     $appName       The app name.
	 * @param IRequest                   $request       The request.
	 * @param IUserSession               $userSession   The session, for the admin check.
	 * @param IGroupManager              $groupManager  The admin check.
	 * @param OrObjectService            $objectService OpenRegister's object service.
	 * @param IntuneAdapter              $intune        The Intune adapter.
	 * @param JamfProAdapter             $jamfPro       The Jamf Pro adapter.
	 * @param CitrixDaasAdapter          $citrixDaas    The Citrix DaaS adapter.
	 * @param AzureVirtualDesktopAdapter $avd           The Azure Virtual Desktop adapter.
	 * @param WorkspaceUserMatcher       $users         Maps entries to Nextcloud users.
	 * @param IL10N                      $l             Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly OrObjectService $objectService,
		private readonly IntuneAdapter $intune,
		private readonly JamfProAdapter $jamfPro,
		private readonly CitrixDaasAdapter $citrixDaas,
		private readonly AzureVirtualDesktopAdapter $avd,
		private readonly WorkspaceUserMatcher $users,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The entries the source's workspace manager reports.
	 *
	 * @param string $id The source's uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function index(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) !== true) {
			return new JSONResponse(['error' => $this->l->t('Only an administrator can see the sessions and devices of a source.')], Http::STATUS_FORBIDDEN);
		}

		$configuration = $this->configurationOf(id: $id);
		if ($configuration === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		$adapterId = (string)($configuration['workspaceAdapter'] ?? '');
		$kind = $this->kindOf(adapterId: $adapterId);
		if ($kind === null) {
			return new JSONResponse(['error' => $this->l->t('This source is not connected to a workspace manager.')], Http::STATUS_BAD_REQUEST);
		}

		$entries = $this->entriesOf(adapterId: $adapterId, settings: $configuration);
		if ($entries === null) {
			return new JSONResponse(
				[
					'adapter' => $adapterId,
					'kind' => $kind,
					'credentialMissing' => true,
					'message' => $this->l->t('This source has no credential yet, so nothing was called. Add a credential to see what it reports.'),
					'entries' => [],
				]
			);
		}

		$mapping = ($configuration['userMapping'] ?? []);
		if (is_array($mapping) === false) {
			$mapping = [];
		}

		$listed = [];
		foreach ($entries as $entry) {
			$matched = $this->users->match(entry: $entry, mapping: $mapping);
			$listed[] = $entry + ['user' => $matched, 'userFound' => ($matched !== null)];
		}

		return new JSONResponse(['adapter' => $adapterId, 'kind' => $kind, 'credentialMissing' => false, 'message' => null, 'entries' => $listed]);
	}//end index()

	/**
	 * `device` or `session` for a known adapter id, else null.
	 *
	 * @param string $adapterId The adapter id.
	 *
	 * @return string|null
	 */
	private function kindOf(string $adapterId): ?string {
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
	 */
	private function entriesOf(string $adapterId, array $settings): ?array {
		$adapter = $this->workspaceAdapter(adapterId: $adapterId);
		if ($adapter !== null) {
			return $adapter->listEntries(settings: $settings);
		}

		if ($this->avd->isEnabled() === false) {
			return null;
		}

		$entries = [];
		foreach ($this->avd->list(register: '', schema: '', objectId: '', filters: $settings) as $session) {
			$entries[] = [
				'kind' => AbstractWorkspaceAdapter::KIND_SESSION,
				'id' => ($session['id'] ?? null),
				'name' => null,
				'remoteUser' => ($session['userPrincipalName'] ?? null),
				'email' => null,
				'lastSeen' => ($session['createTime'] ?? null),
				'state' => ($session['sessionState'] ?? null),
			];
		}

		return $entries;
	}//end entriesOf()

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

	/**
	 * The source's configuration, [] when it has none, null when there is no such source.
	 *
	 * @param string $id The source's uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function configurationOf(string $id): ?array {
		try {
			$source = $this->objectService->find(id: $id, register: 'integriq', schema: 'source', _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException) {
			return null;
		}

		if (($source instanceof ObjectEntity) === false) {
			return null;
		}

		$configuration = ($source->getObject()['configuration'] ?? []);
		if (is_array($configuration) === false) {
			return [];
		}

		return $configuration;
	}//end configurationOf()
}//end class
