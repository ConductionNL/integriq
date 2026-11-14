<?php

/**
 * Integriq WellKnownController
 *
 * Serves RFC 9728 protected-resource metadata for token-protected endpoints
 * (access-oauth-and-token-validation, REQ-TOKV-004). `#[PublicPage]` on
 * purpose: a client without a token reads it to learn where to get one. It
 * answers nothing for an endpoint that is not token-protected.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\OAuth\ProtectedResourceMetadata;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The protected-resource metadata documents.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
 */
class WellKnownController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                    $appName  The app name.
	 * @param IRequest                  $request  The request.
	 * @param ProtectedResourceMetadata $metadata Builds the document.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ProtectedResourceMetadata $metadata,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /.well-known/oauth-protected-resource/{_path}
	 *
	 * Access control: none; the document names only public addresses and scope names.
	 *
	 * @param string $_path The endpoint's path.
	 *
	 * @return JSONResponse 200 the metadata; 404 for an endpoint that is not token-protected.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function protectedResource(string $_path): JSONResponse {
		$document = $this->metadata->forPath(endpointPath: $_path);
		if ($document === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($document);
	}//end protectedResource()
}//end class
