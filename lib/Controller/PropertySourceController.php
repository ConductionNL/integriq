<?php

/**
 * HTTP surface for registry-backed property sources.
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

use OCA\Integriq\PropertySource\Exception\MissingSourceConfigurationException;
use OCA\Integriq\PropertySource\Exception\UnknownPropertySourceException;
use OCA\Integriq\PropertySource\ListResyncService;
use OCA\Integriq\PropertySource\PropertySourceRegistry;
use OCA\Integriq\PropertySource\PropertySourceResolver;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * openregister resolves a declared property source through this surface, and
 * an administration screen resyncs a list-shaped one through it.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
 */
class PropertySourceController extends Controller {
	/**
	 * The ADR-023 action a resync is gated on.
	 */
	public const RESYNC_ACTION = 'propertySource.resync';

	/**
	 * Prefix of the ADR-023 action a query is gated on, completed with the provider id.
	 */
	public const QUERY_ACTION_PREFIX = 'propertySource.query.';

	/**
	 * Providers exempt from the query gate, because what they return is public.
	 *
	 * The list is an EXEMPTION list, not a sensitive list, and deliberately so.
	 * A gate keyed on "which providers are sensitive" ships a new personal-data
	 * provider OPEN whenever someone forgets to add it — which is how
	 * `suggest()` came to hand out a BSN to any signed-in account
	 * (integriq#1983 review 5278999788). Keyed this way a new provider is gated
	 * until someone consciously declares it public, so what a maintainer has to
	 * remember fails in the safe direction.
	 *
	 * `bag` is the Dutch address register and `kvk` the company register. Both
	 * are open registries, and an applicant filling in a form needs them — that
	 * is the scenario `suggest()` exists for. Gating them would put an address
	 * lookup behind an administrator, which is not what this gate is for.
	 */
	public const PUBLIC_PROVIDERS = ['bag', 'kvk'];

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request The request.
	 * @param PropertySourceRegistry $registry Registry of bindings.
	 * @param PropertySourceResolver $resolver The resolver.
	 * @param ListResyncService $listResync On-demand resync for list-shaped providers.
	 * @param ActionAuthService $actionAuth ADR-023 action gate.
	 * @param IUserSession $userSession The current session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PropertySourceRegistry $registry,
		private readonly PropertySourceResolver $resolver,
		private readonly ListResyncService $listResync,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every registered provider and what it says about itself.
	 *
	 * @return JSONResponse The provider inventory.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-describe-says-what-the-provider-keys-on
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(['results' => $this->registry->describeAll()]);
	}//end index()

	/**
	 * Type-ahead while a field is being filled in.
	 *
	 * A suggestion is not an answer: every entry is marked `authoritative`
	 * false, and must be resolved by its identifier before it is stored.
	 *
	 * @param string $provider Provider id the property declares.
	 * @param string $q Partial query.
	 *
	 * @return JSONResponse Suggestions, or a 404 naming an unknown provider.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-an-applicant-types-an-address
	 *
	 * @no-admin-idor-exempt Queries an authoritative registry the instance is configured for, by search
	 *     term. The identifier is a registry key, not an id of a record this app stores, so there is no per-
	 *     object owner to compare against.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function suggest(string $provider, string $q = ''): JSONResponse {
		$refusal = $this->requireQueryPermission(provider: $provider);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse(['results' => $this->resolver->suggest($provider, $q)]);
		} catch (UnknownPropertySourceException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}//end suggest()

	/**
	 * One authoritative read, with the provenance it carries.
	 *
	 * @param string $provider Provider id the property declares.
	 * @param string $identifier Identifier at the source.
	 * @param bool $fresh Bypass the cache and reach the source.
	 *
	 * @return JSONResponse The value and its provenance.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-resolved-value-carries-its-provenance-req-rfs-003
	 *
	 * @no-admin-idor-exempt Queries an authoritative registry the instance is configured for, by registry
	 *     identifier. Not a read of a record this app stores, so there is no per-object owner to compare
	 *     against.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function resolve(string $provider, string $identifier = '', bool $fresh = false): JSONResponse {
		$refusal = $this->requireQueryPermission(provider: $provider);
		if ($refusal !== null) {
			return $refusal;
		}

		if ($identifier === '') {
			return new JSONResponse(['error' => 'An identifier is required to resolve a value.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$resolved = $this->resolver->resolve($provider, $identifier, [], $fresh);
		} catch (UnknownPropertySourceException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (MissingSourceConfigurationException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse($resolved->toArray());
	}//end resolve()

	/**
	 * The authorization decision for querying a provider, or null when it passes.
	 *
	 * `suggest()` and `resolve()` reach an authoritative registry on the
	 * caller's behalf. For `brp` that registry answers with a person and their
	 * **burgerservicenummer**, so *who may ask* is itself the control: there is
	 * no such thing as a harmless result.
	 *
	 * This is deliberately NOT an OpenRegister RBAC check, and the reason is
	 * worth stating so the next reader does not "fix" it into one:
	 *
	 * - The source row IS the credential store.
	 *   `configuration.headers.Authorization`, `configuration.cert` and
	 *   `configuration.ssl_key` are NOT covered by
	 *   `99-source-nested-auth-writeonly.json`, which is an exact-path list with
	 *   no wildcards. A read permission on that row would hand out the RvIG keys
	 *   along with the right to query. "May query" and "may see the credentials"
	 *   are different questions and a read permission only answers the second.
	 * - `ConnectionStore::findSourceBySlug()` reads in system context
	 *   (`_rbac: false`) — as ocon#147 designed it, so the engine can serve
	 *   non-admin syncs — so `PermissionHandler` never runs on this path and an
	 *   `authorization` block on the source object would be inert.
	 *
	 * The action matrix is the one authorization surface `_rbac: false` cannot
	 * switch off, because it is consulted here, before anything is read.
	 * Per-object granularity needs the RBAC rebuild: integriq#2112,
	 * integriq#2125, openregister#2432.
	 *
	 * The action is unseeded on purpose. `getAllowedGroups()` answers
	 * `['admin']` for an unknown action and `requireAction()` reads `['admin']`
	 * as "no non-admin passes", so a provider is admin-only the moment it is
	 * added, with no seed entry to forget. An operator then delegates it to the
	 * group that carries the connector — `brp-beheer` — in Admin Settings >
	 * Integriq > Action authorization, without a deploy. That is the convention
	 * `docs/administrators/sources/sensitive-sources.md` prescribes; until now
	 * nothing enforced it.
	 *
	 * @param string $provider Provider id the caller named.
	 *
	 * @return JSONResponse|null The refusal, or null when the query may proceed.
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
	 */
	private function requireQueryPermission(string $provider): ?JSONResponse {
		if (in_array($provider, self::PUBLIC_PROVIDERS, true) === true) {
			return null;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: (self::QUERY_ACTION_PREFIX . $provider));
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return null;

	}//end requireQueryPermission()

	/**
	 * Resync a list-shaped provider from the administration screen.
	 *
	 * `#[NoAdminRequired]` plus the ADR-023 `propertySource.resync` gate
	 * below: the route is reachable by an authenticated session, and the
	 * authorization decision is made here, in the method body.
	 *
	 * @param string $provider Provider id.
	 *
	 * @return JSONResponse The resync report.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-list-shaped-source-resyncs-on-demand-req-rfs-007
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function resync(string $provider): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::RESYNC_ACTION);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($this->listResync->resync($provider));
		} catch (UnknownPropertySourceException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}//end resync()
}//end class
