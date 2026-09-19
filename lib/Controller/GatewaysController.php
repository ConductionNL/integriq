<?php

/**
 * The statutory gateway catalogue, overview, bindings and bridges.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Bridge\BridgeRegistry;
use OCA\Integriq\Gateway\GatewayDescriptor;
use OCA\Integriq\Gateway\GatewayRegistry;
use OCA\Integriq\Gateway\ZgwRegistryBinding;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reading which laws this instance reaches is open to any signed-in account.
 * Changing a binding or revoking a bridge is not.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-where-its-endpoint-sits-req-sg-008
 */
class GatewaysController extends Controller {
	/**
	 * The ADR-023 action a binding test or a bridge revocation is gated on.
	 */
	public const ADMIN_ACTION = 'gateway.administer';

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request The request.
	 * @param GatewayRegistry $registry The gateway entries.
	 * @param ZgwRegistryBinding $binding The ZGW registry binding.
	 * @param BridgeRegistry $bridges The on-premise bridges.
	 * @param ActionAuthService $actionAuth ADR-023 action gate.
	 * @param IUserSession $userSession The current session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly GatewayRegistry $registry,
		private readonly ZgwRegistryBinding $binding,
		private readonly BridgeRegistry $bridges,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The gateway catalogue, filterable by standard.
	 *
	 * @param string $standard Narrow the list to one standard.
	 *
	 * @return JSONResponse The catalogue.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#scenario-the-catalogue-answers-which-laws-the-instance-reaches
	 *
	 * @no-admin-idor-exempt A static catalogue of the gateway descriptors this build ships.
	 *   It carries no instance data and no per-user object: the same answer goes to everyone,
	 *   and the optional standard filter selects among descriptors compiled into the app.
	 *   There is no object id here to scope to a caller.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $standard = ''): JSONResponse {
		$filter = null;
		if ($standard !== '') {
			$filter = $standard;
		}

		return new JSONResponse(
			[
				'standards' => $this->registry->standards(),
				'results' => array_map(
					static fn (GatewayDescriptor $g): array => $g->toArray(),
					$this->registry->all($filter)
				),
			]
		);
	}//end index()

	/**
	 * Every configured gateway with its jurisdiction.
	 *
	 * @return JSONResponse The overview.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#scenario-an-administrator-reads-where-data-goes
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function overview(): JSONResponse {
		return new JSONResponse(['results' => $this->registry->overview()]);
	}//end overview()

	/**
	 * The gateway overview as a file an administrator can keep.
	 *
	 * @return DataDownloadResponse The export.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#scenario-an-administrator-reads-where-data-goes
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportOverview(): DataDownloadResponse {
		return new DataDownloadResponse(
			$this->registry->exportOverview(),
			'integriq-gateways.csv',
			'text/csv'
		);
	}//end exportOverview()

	/**
	 * Test the ZGW registry binding's reachability.
	 *
	 * `#[NoAdminRequired]` with the authorization decision in the method body,
	 * through the ADR-023 `gateway.administer` action.
	 *
	 * @param array<string,mixed> $binding The binding to test, or empty for the configured one.
	 *
	 * @return JSONResponse The verdict.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#scenario-an-unreachable-binding-fails-at-test-time
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function testBinding(array $binding = []): JSONResponse {
		$refusal = $this->requireAdministerAction();
		if ($refusal !== null) {
			return $refusal;
		}

		$candidate = $binding;
		if ($binding === []) {
			$candidate = null;
		}

		$verdict = $this->binding->test($candidate);

		$status = Http::STATUS_BAD_REQUEST;
		if ($verdict['ok'] === true) {
			$status = Http::STATUS_OK;
		}

		return new JSONResponse($verdict, $status);
	}//end testBinding()

	/**
	 * Every registered bridge and its state.
	 *
	 * @return JSONResponse The bridges, without their token hashes.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-an-on-premise-bridge-reaches-a-system-behind-the-firewall-req-sg-007
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bridges(): JSONResponse {
		$refusal = $this->requireAdministerAction();
		if ($refusal !== null) {
			return $refusal;
		}

		$rows = [];
		foreach ($this->bridges->all() as $bridge) {
			unset($bridge['tokenHash']);
			$rows[] = $bridge;
		}

		return new JSONResponse(['results' => $rows]);
	}//end bridges()

	/**
	 * Revoke a bridge.
	 *
	 * @param string $id The bridge id.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#scenario-a-revoked-bridge-stops-answering
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function revokeBridge(string $id): JSONResponse {
		$refusal = $this->requireAdministerAction();
		if ($refusal !== null) {
			return $refusal;
		}

		if ($this->bridges->revoke($id) === false) {
			return new JSONResponse(['error' => sprintf('No bridge is registered under "%s".', $id)], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['revoked' => true, 'bridge' => $id]);
	}//end revokeBridge()

	/**
	 * The authorization decision shared by the administering routes.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 */
	private function requireAdministerAction(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ADMIN_ACTION);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end requireAdministerAction()
}//end class
