<?php

/**
 * Integriq IdpMetadataController.
 *
 * Publishes each organisation's Service Provider or Relying Party to the
 * identity provider or broker that registers it (portal-idp-broker-config
 * D3). Public and throttled; every answer for something that is not
 * published is the same 404, so the endpoint does not tell a caller which
 * organisations exist.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Auth\Idp\IdpPublicationInterface;
use OCA\Integriq\Exception\IdpMetadataUnavailableException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * The metadata and JWKS of a registration.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
 */
class IdpMetadataController extends Controller {

	/**
	 * The brute-force action an unpublished request is counted under.
	 */
	public const BRUTE_FORCE_ACTION = 'integriq_idp_metadata';

	/**
	 * The media type of SAML metadata.
	 */
	public const SAML_METADATA_TYPE = 'application/samlmetadata+xml';

	/**
	 * Constructor.
	 *
	 * @param string                  $appName     The app id.
	 * @param IRequest                $request     The request.
	 * @param IdpPublicationInterface $publication Finds the published registration and builds its documents.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IdpPublicationInterface $publication,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The SP metadata XML of a `saml-sp` registration, or the client document
	 * of an `oidc-rp` one.
	 *
	 * @param string $organisation The organisation.
	 * @param string $provider     The provider.
	 *
	 * @return Response
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	public function metadata(string $organisation, string $provider): Response {
		try {
			$document = $this->publication->document(organisation: $organisation, provider: $provider);
		} catch (IdpMetadataUnavailableException) {
			return $this->unavailable();
		}

		if ($document === null) {
			return $this->notFound();
		}

		if ($document['type'] === IdpPublicationInterface::DOCUMENT_SAML) {
			return new DataDisplayResponse(
				data: $document['body'],
				statusCode: Http::STATUS_OK,
				headers: ['Content-Type' => self::SAML_METADATA_TYPE]
			);
		}

		return new JSONResponse(data: $document['body']);

	}//end metadata()

	/**
	 * The JWKS of an `oidc-rp` registration that signs its client assertion.
	 *
	 * @param string $organisation The organisation.
	 * @param string $provider     The provider.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	public function jwks(string $organisation, string $provider): JSONResponse {
		try {
			$keys = $this->publication->jwks(organisation: $organisation, provider: $provider);
		} catch (IdpMetadataUnavailableException) {
			return $this->unavailable();
		}

		if ($keys === null) {
			return $this->notFound();
		}

		return new JSONResponse(data: $keys);

	}//end jwks()

	/**
	 * The one answer for anything not published, counted for brute-force throttling.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		$response = new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		$response->throttle(['action' => self::BRUTE_FORCE_ACTION]);

		return $response;

	}//end notFound()

	/**
	 * The answer when a certificate cannot be read; it names nothing of the credential.
	 *
	 * @return JSONResponse
	 */
	private function unavailable(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'The metadata is temporarily unavailable'],
			statusCode: Http::STATUS_SERVICE_UNAVAILABLE
		);

	}//end unavailable()
}//end class
