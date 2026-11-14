<?php

/**
 * Integriq MappingLookupController.
 *
 * Reads and edits the lookup allowlist: the register schemas a mapping's
 * `lookup()` may read. Instance administrators only; every change is recorded
 * with the administrator and the time (mapping-formats-and-lookups D4).
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\Integriq\Twig\LookupAllowlist;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The lookup allowlist, for administrators.
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
 */
class MappingLookupController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request      The request.
	 * @param LookupAllowlist $allowlist    The list.
	 * @param IUserSession    $userSession  Who is asking.
	 * @param IGroupManager   $groupManager Whether they administer this instance.
	 * @param LoggerInterface $logger       Where a change is recorded.
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	public function __construct(
		IRequest $request,
		private readonly LookupAllowlist $allowlist,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The listed schemas, with who added each and when.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function index(): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$schemas = [];
		foreach ($this->allowlist->entries() as $schema => $entry) {
			$schemas[] = ['schema' => $schema, 'addedBy' => $entry['addedBy'], 'addedAt' => $entry['addedAt']];
		}

		return new JSONResponse(['schemas' => $schemas]);
	}//end index()

	/**
	 * Add a schema to the allowlist.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function add(): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$schema = trim((string)$this->request->getParam('schema', ''));
		$principal = (string)($this->userSession->getUser()?->getUID() ?? '');

		$result = $this->allowlist->add(schema: $schema, principal: $principal);
		if ($result['added'] === false) {
			return new JSONResponse(['error' => $result['reason']], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->logger->info(sprintf('[MappingLookups] %s added "%s" to the lookup allowlist', $principal, $schema));

		return new JSONResponse(['schema' => $schema], Http::STATUS_CREATED);
	}//end add()

	/**
	 * Remove a schema from the allowlist.
	 *
	 * @param string $register The register part of the schema.
	 * @param string $schema   The schema part.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function remove(string $register, string $schema): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$name = $register . '/' . $schema;
		$principal = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($this->allowlist->remove(schema: $name) === false) {
			return new JSONResponse(['error' => sprintf('"%s" is not on the list.', $name)], Http::STATUS_NOT_FOUND);
		}

		$this->logger->info(sprintf('[MappingLookups] %s removed "%s" from the lookup allowlist', $principal, $name));

		return new JSONResponse(['schema' => $name]);
	}//end remove()

	/**
	 * Refuse a caller who is not an instance administrator.
	 *
	 * @return JSONResponse|null A refusal, or null when they may proceed.
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) !== true) {
			return new JSONResponse(['error' => 'Only an instance administrator may change which schemas a mapping may look up in.'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end requireAdmin()
}//end class
