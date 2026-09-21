<?php

/**
 * Integriq ScimController.
 *
 * The SCIM 2.0 endpoint an identity system calls to create, change and
 * deactivate accounts. It authenticates on its own credential rather than on a
 * Nextcloud session, so `#[PublicPage]` and `#[NoCSRFRequired]` are present and
 * the credential check IS the auth body of every route here, exactly as it is
 * for the inbound webhook endpoints. The check runs BEFORE any account is read,
 * and a rejection is logged.
 *
 * The credential is the app's existing consumer-backed API key store, resolved
 * by `AuthorizationService::authorizeApiKey()`. No new secret is introduced and
 * none is written into this package.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Directory\ScimProvisioningService;
use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Exception\DirectorySyncRefusalException;
use OCA\Integriq\Service\AuthorizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * SCIM 2.0 `Users` and `Groups`, gated by its own credential.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
 */
class ScimController extends Controller {

	/**
	 * The SCIM 2.0 list-response schema urn.
	 *
	 * @var string
	 */
	private const LIST_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

	/**
	 * The SCIM 2.0 error schema urn.
	 *
	 * @var string
	 */
	private const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';

	/**
	 * The consumer this request was answered as, resolved by authorize().
	 *
	 * Request-scoped: the controller is constructed per request, so this never
	 * carries an identity from one call into the next. Set by authorize(), which
	 * carries the REQ-DS-007 reference.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $callingConsumer = null;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param ScimProvisioningService $provisioningService Applies the SCIM call.
	 * @param AuthorizationService $authorizationService The existing inbound credential check.
	 * @param LoggerInterface $logger Logger for rejections.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly ScimProvisioningService $provisioningService,
		private readonly AuthorizationService $authorizationService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * List SCIM users.
	 *
	 * @return JSONResponse A SCIM ListResponse, or 401 before anything is read.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function listUsers(): JSONResponse {
		$rejected = $this->authorize();
		if ($rejected !== null) {
			return $rejected;
		}

		$resources = $this->provisioningService->listUsers(
			filterUserName: $this->filterValue(attribute: 'userName'),
			limit: (int)$this->request->getParam('count', 100)
		);

		return $this->listResponse(resources: $resources);

	}//end listUsers()

	/**
	 * Read one SCIM user.
	 *
	 * @param string $id The Nextcloud account id.
	 *
	 * @return JSONResponse The SCIM user resource, 404, or 401 before anything is read.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function getUser(string $id): JSONResponse {
		$rejected = $this->authorize();
		if ($rejected !== null) {
			return $rejected;
		}

		$resource = $this->provisioningService->getUser(userId: $id);
		if ($resource === null) {
			return $this->scimError(status: Http::STATUS_NOT_FOUND, detail: 'Resource not found');
		}

		return new JSONResponse($resource);

	}//end getUser()

	/**
	 * Create a SCIM user.
	 *
	 * @return JSONResponse The created SCIM user resource, or 401 before anything is read.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function createUser(): JSONResponse {
		$rejected = $this->authorize();
		if ($rejected !== null) {
			return $rejected;
		}

		try {
			$resource = $this->provisioningService->upsertUser(
				resource: $this->request->getParams(),
				consumerLabel: $this->consumerLabel()
			);
		} catch (DirectorySyncRefusalException $refusal) {
			// Same shape as the group route: the log names what was refused, the
			// body carries only what the refusal decided is safe to tell a caller.
			$this->logger->warning(
				'[Scim] refused a user write for consumer ' . $this->consumerLabel() . ': ' . $refusal->getMessage()
			);

			return $this->scimError(
				status: Http::STATUS_FORBIDDEN,
				detail: (string)($refusal->getContext()['detail'] ?? 'This account cannot be managed over SCIM.')
			);
		}

		return new JSONResponse($resource, Http::STATUS_CREATED);

	}//end createUser()

	/**
	 * Replace or patch a SCIM user, including a deactivation.
	 *
	 * @param string $id The Nextcloud account id.
	 *
	 * @return JSONResponse The SCIM user resource, or 401 before anything is read.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function updateUser(string $id): JSONResponse {
		$rejected = $this->authorize();
		if ($rejected !== null) {
			return $rejected;
		}

		$body = $this->request->getParams();
		$resource = array_merge($this->normalisePatch(body: $body), ['userName' => $id]);

		try {
			$updated = $this->provisioningService->upsertUser(
				resource: $resource,
				consumerLabel: $this->consumerLabel()
			);
		} catch (DirectorySyncRefusalException $refusal) {
			// Same shape as the group route: the log names what was refused, the
			// body carries only what the refusal decided is safe to tell a caller.
			$this->logger->warning(
				'[Scim] refused a user write for consumer ' . $this->consumerLabel() . ': ' . $refusal->getMessage()
			);

			return $this->scimError(
				status: Http::STATUS_FORBIDDEN,
				detail: (string)($refusal->getContext()['detail'] ?? 'This account cannot be managed over SCIM.')
			);
		}

		return new JSONResponse($updated);

	}//end updateUser()

	/**
	 * Deprovision a SCIM user: disable the account, never delete it.
	 *
	 * @param string $id The Nextcloud account id.
	 *
	 * @return JSONResponse What the account still holds, or 401 before anything is read.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function deleteUser(string $id): JSONResponse {
		$rejected = $this->authorize();
		if ($rejected !== null) {
			return $rejected;
		}

		try {
			$openWork = $this->provisioningService->deactivateUser(
				userId: $id,
				consumerLabel: $this->consumerLabel()
			);
		} catch (DirectorySyncRefusalException $refusal) {
			// Same shape as the group route: the log names what was refused, the
			// body carries only what the refusal decided is safe to tell a caller.
			$this->logger->warning(
				'[Scim] refused a user write for consumer ' . $this->consumerLabel() . ': ' . $refusal->getMessage()
			);

			return $this->scimError(
				status: Http::STATUS_FORBIDDEN,
				detail: (string)($refusal->getContext()['detail'] ?? 'This account cannot be managed over SCIM.')
			);
		}


		// 200 with the open-work report rather than 204: a deprovision that
		// leaves a live case list behind is exactly what the caller needs told.
		return new JSONResponse(['active' => false, 'openWork' => $openWork]);

	}//end deleteUser()

	/**
	 * List SCIM groups.
	 *
	 * @return JSONResponse A SCIM ListResponse, or 401 before anything is read.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function listGroups(): JSONResponse {
		$rejected = $this->authorize();
		if ($rejected !== null) {
			return $rejected;
		}

		$resources = $this->provisioningService->listGroups(
			filterDisplayName: $this->filterValue(attribute: 'displayName'),
			limit: (int)$this->request->getParam('count', 100)
		);

		return $this->listResponse(resources: $resources);

	}//end listGroups()

	/**
	 * Set one SCIM group's membership.
	 *
	 * @param string $id The Nextcloud group id.
	 *
	 * @return JSONResponse The group resource, 404, or 401 before anything is read.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function updateGroup(string $id): JSONResponse {
		$rejected = $this->authorize();
		if ($rejected !== null) {
			return $rejected;
		}

		$members = (array)($this->request->getParam('members', []));

		try {
			$written = $this->provisioningService->setGroupMembers(
				groupId: $id,
				members: $members,
				consumerLabel: $this->consumerLabel()
			);
		} catch (DirectorySyncRefusalException $refusal) {
			// The message names what was refused, for the operator reading the log.
			// The body carries only what the refusal itself decided is safe to tell
			// a caller — see ScimProvisioningService::REFUSAL_DETAIL_*.
			$this->logger->warning(
				'[Scim] refused a group write for consumer ' . $this->consumerLabel() . ': ' . $refusal->getMessage()
			);

			return $this->scimError(
				status: Http::STATUS_FORBIDDEN,
				detail: (string)($refusal->getContext()['detail'] ?? 'This group cannot be managed over SCIM.')
			);
		}

		if ($written === false) {
			return $this->scimError(status: Http::STATUS_NOT_FOUND, detail: 'Resource not found');
		}

		$resources = $this->provisioningService->listGroups(filterDisplayName: $id);

		return new JSONResponse(($resources[0] ?? []));

	}//end updateGroup()

	/**
	 * Check the SCIM credential, answering a 401 response when it does not hold.
	 *
	 * @return JSONResponse|null The rejection, or null when the call is authorised.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	private function authorize(): ?JSONResponse {
		$header = (string)$this->request->getHeader('Authorization');
		$presented = trim(preg_replace('/^Bearer\s+/i', '', $header) ?? '');

		if ($presented === '') {
			$this->logger->warning('[Scim] rejected a call with no credential');

			return $this->scimError(status: Http::STATUS_UNAUTHORIZED, detail: 'Unauthorized');
		}

		try {
			$this->authorizationService->authorizeApiKey(header: $presented, keys: []);
		} catch (AuthenticationException) {
			// Undifferentiated body: never say which check failed.
			$this->logger->warning('[Scim] rejected a call with an invalid credential');

			return $this->scimError(status: Http::STATUS_UNAUTHORIZED, detail: 'Unauthorized');
		}

		// REQ-DS-007: a SCIM call is answered as a NAMED consumer. Authentication
		// alone is not enough, because a call nobody can be held to is a call an
		// operator cannot investigate afterwards. `keys: []` above means the
		// rule-inline branch never matches, so a successful authorisation always
		// leaves a resolved consumer behind — the guard is a fail-closed backstop
		// against that invariant changing, not a path expected to be taken.
		$consumer = $this->authorizationService->getResolvedConsumer();
		if ($consumer === null) {
			$this->logger->warning('[Scim] rejected a call that authenticated without naming a consumer');

			return $this->scimError(status: Http::STATUS_UNAUTHORIZED, detail: 'Unauthorized');
		}

		$this->callingConsumer = $consumer;

		return null;

	}//end authorize()

	/**
	 * The consumer this call was answered as, for a log line.
	 *
	 * Never returned to the caller — attribution belongs in the log, where an
	 * operator can read it, and not in a response an attacker can probe.
	 *
	 * @return string The consumer's uuid, or `unknown` before authorize() has run.
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-a-scim-call-is-answered-as-a-named-consumer-req-ds-007
	 */
	private function consumerLabel(): string {
		if ($this->callingConsumer === null) {
			return 'unknown';
		}

		return (string)$this->callingConsumer->getUuid();

	}//end consumerLabel()

	/**
	 * Read an exact-match value out of a SCIM `filter` query parameter.
	 *
	 * Only the `eq` form is honoured, which is the one an identity system uses
	 * to look an account up before deciding to create it.
	 *
	 * @param string $attribute The SCIM attribute name.
	 *
	 * @return string The value, or the empty string when the filter does not name it.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	private function filterValue(string $attribute): string {
		$filter = (string)$this->request->getParam('filter', '');
		if ($filter === '') {
			return '';
		}

		$pattern = '/^' . preg_quote($attribute, '/') . '\s+eq\s+"([^"]*)"$/i';
		if (preg_match($pattern, trim($filter), $matches) === 1) {
			return $matches[1];
		}

		return '';

	}//end filterValue()

	/**
	 * Flatten a SCIM PATCH body into the resource shape upsertUser reads.
	 *
	 * @param array<string,mixed> $body The request body.
	 *
	 * @return array<string,mixed> The resource fields the patch sets.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	private function normalisePatch(array $body): array {
		$operations = ($body['Operations'] ?? $body['operations'] ?? null);
		if (is_array($operations) === false) {
			return $body;
		}

		$resource = [];
		foreach ($operations as $operation) {
			if (is_array($operation) === false) {
				continue;
			}

			$path = (string)($operation['path'] ?? '');
			$value = ($operation['value'] ?? null);

			if ($path === '' && is_array($value) === true) {
				$resource = array_merge($resource, $value);
				continue;
			}

			if ($path !== '') {
				$resource[$path] = $value;
			}
		}

		return $resource;

	}//end normalisePatch()

	/**
	 * Wrap resources in a SCIM ListResponse.
	 *
	 * @param array<int,array<string,mixed>> $resources The resources.
	 *
	 * @return JSONResponse The ListResponse.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	private function listResponse(array $resources): JSONResponse {
		return new JSONResponse(
			[
				'schemas' => [self::LIST_SCHEMA],
				'totalResults' => count($resources),
				'itemsPerPage' => count($resources),
				'startIndex' => 1,
				'Resources' => $resources,
			]
		);

	}//end listResponse()

	/**
	 * A SCIM error response.
	 *
	 * @param integer $status The HTTP status.
	 * @param string $detail The undifferentiated detail.
	 *
	 * @return JSONResponse The error.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	private function scimError(int $status, string $detail): JSONResponse {
		return new JSONResponse(
			['schemas' => [self::ERROR_SCHEMA], 'status' => (string)$status, 'detail' => $detail],
			$status
		);

	}//end scimError()
}//end class
