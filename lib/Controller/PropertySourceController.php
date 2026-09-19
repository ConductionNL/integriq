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
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function suggest(string $provider, string $q = ''): JSONResponse {
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
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function resolve(string $provider, string $identifier = '', bool $fresh = false): JSONResponse {
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
