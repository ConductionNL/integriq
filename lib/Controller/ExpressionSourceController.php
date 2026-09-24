<?php

/**
 * The environment allowlist, administered — and only by an administrator.
 *
 * 🔴 WHO MAY CHANGE IT: AN INSTANCE ADMINISTRATOR, ENFORCED TWICE. Nextcloud's
 * `#[AuthorizedAdminSetting]` refuses a non-administrator before the controller
 * runs, and every method asks again in its body. The doubling is deliberate:
 * this list decides what an expression language can read out of the process, so
 * widening it is a code-execution-adjacent act, and a guard that exists only as
 * an attribute is one that disappears the moment somebody adds a route by hand
 * or calls the method from another service.
 *
 * 🔴 AND THE SURFACE SHOWS KEYS, NEVER VALUES. Listing the value would publish
 * the secret to everyone who can open the page — which is a wider audience than
 * whoever may read the environment, and the whole point of the list is that the
 * second audience is small.
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
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Expression\EnvironmentAllowlist;
use OCA\Integriq\Expression\ExpressionValueSourceRegistry;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Reads and edits the environment allowlist, and lists the registered sources.
 *
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
 */
class ExpressionSourceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                       $request      The request.
	 * @param EnvironmentAllowlist           $allowlist    The list.
	 * @param ExpressionValueSourceRegistry  $registry     The registered sources.
	 * @param IUserSession                   $userSession  Who is asking.
	 * @param IGroupManager                  $groupManager Whether they administer this instance.
	 * @param LoggerInterface                $logger       Where a change is recorded.
	 */
	public function __construct(
		IRequest $request,
		private readonly EnvironmentAllowlist $allowlist,
		private readonly ExpressionValueSourceRegistry $registry,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The listed keys and the registered prefixes.
	 *
	 * @return JSONResponse The list.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function index(): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$keys = [];
		foreach ($this->allowlist->entries() as $key => $entry) {
			// Key, who added it, when. No value, and no "isSet" either: telling
			// a reader which allowlisted variables happen to be populated is a
			// map of what is worth asking for.
			$keys[] = ['key' => $key, 'addedBy' => $entry['addedBy'], 'addedAt' => $entry['addedAt']];
		}

		return new JSONResponse(['keys' => $keys, 'sources' => $this->registry->describeAll()]);
	}//end index()

	/**
	 * Add a key to the allowlist.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function add(): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$key = (string)$this->request->getParam('key', '');
		$principal = (string)($this->userSession->getUser()?->getUID() ?? '');

		$result = $this->allowlist->add(key: $key, principal: $principal);
		if ($result['added'] === false) {
			return new JSONResponse(['error' => $result['reason']], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		// Recorded out loud. Widening what an expression may read is the act an
		// auditor comes back to, and the record has to name the key and the
		// person, never the value.
		$this->logger->info(
			sprintf('[ExpressionSources] %s added "%s" to the environment allowlist', $principal, trim($key))
		);

		return new JSONResponse(['key' => trim($key)], Http::STATUS_CREATED);
	}//end add()

	/**
	 * Remove a key from the allowlist.
	 *
	 * @param string $key The key.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function remove(string $key): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$principal = (string)($this->userSession->getUser()?->getUID() ?? '');

		if ($this->allowlist->remove(key: $key) === false) {
			return new JSONResponse(['error' => sprintf('"%s" is not on the list.', $key)], Http::STATUS_NOT_FOUND);
		}

		$this->logger->info(
			sprintf('[ExpressionSources] %s removed "%s" from the environment allowlist', $principal, $key)
		);

		return new JSONResponse(['key' => $key]);
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
			return new JSONResponse(
				[
					'error' => 'Only an instance administrator may change what an expression may read from the environment.',
				],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end requireAdmin()
}//end class
