<?php

/**
 * Integriq Portal Keys Controller.
 *
 * A developer generates, lists and revokes the API keys of an application
 * they own, without an administrator (access-developer-portal-and-subscriptions
 * D4). The routes wrap the consumer credentials of access-consumer-credentials:
 * a new key is shown once, in the response that creates it, and never again.
 * Every route checks the developer group and the ownership of the application
 * before it reads or writes anything; an application the developer does not
 * own answers 404, so the developer learns nothing about it.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Service\Consumer\ConsumerCredentials;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The keys of a developer's own application.
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
 */
class PortalKeysController extends Controller {

	/**
	 * Longest label accepted, the same as the administrator's credential route.
	 *
	 * @var integer
	 */
	private const MAX_LABEL_LENGTH = 100;

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request      The request.
	 * @param DeveloperApplications $applications The signed-in developer's own applications.
	 * @param ConsumerCredentials   $credentials  The credentials list rules.
	 * @param IL10N                 $l            Translates the refusals.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	public function __construct(
		IRequest $request,
		private readonly DeveloperApplications $applications,
		private readonly ConsumerCredentials $credentials,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The application's credentials, without their hashes.
	 *
	 * @param string $consumerId The application's id.
	 *
	 * @return JSONResponse `{results: list}`, or 401, 403, 404 or 503.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	#[NoAdminRequired]
	public function index(string $consumerId): JSONResponse {
		$application = $this->applications->owned(consumerId: $consumerId);
		if (is_int($application) === true) {
			return $this->refusal(status: $application);
		}

		$entries = $this->applications->credentials(application: $application);

		return new JSONResponse(['results' => $this->credentials->listed(entries: $entries)]);
	}//end index()

	/**
	 * Generate an API key for the application and answer it in this one response.
	 *
	 * @param string $consumerId The application's id.
	 *
	 * @return JSONResponse 201 with `{key, credential}`, or 400, 401, 403, 404, 500 or 503.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	#[NoAdminRequired]
	public function create(string $consumerId): JSONResponse {
		$application = $this->applications->owned(consumerId: $consumerId);
		if (is_int($application) === true) {
			return $this->refusal(status: $application);
		}

		$label = trim((string)$this->request->getParam('label', ''));
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
			$message = $this->l->t('Give a label of at most %1$s characters.', [(string)self::MAX_LABEL_LENGTH]);
			return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
		}

		$generated = $this->credentials->generate(label: $label);
		if ($this->applications->addCredential(application: $application, entry: $generated['credential']) === false) {
			return $this->refusal(status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$shown = $this->credentials->shown(credential: $generated['credential']);

		return new JSONResponse(['key' => $generated['key'], 'credential' => $shown], Http::STATUS_CREATED);
	}//end create()

	/**
	 * Revoke one API key of the application, leaving its other credentials as they are.
	 *
	 * @param string $consumerId   The application's id.
	 * @param string $credentialId The key's id.
	 *
	 * @return JSONResponse `{revoked}`, or 401, 403, 404, 500 or 503.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	#[NoAdminRequired]
	public function destroy(string $consumerId, string $credentialId): JSONResponse {
		$application = $this->applications->owned(consumerId: $consumerId);
		if (is_int($application) === true) {
			return $this->refusal(status: $application);
		}

		$revoked = $this->applications->revokeKey(
			application: $application,
			credentialId: $credentialId,
			apiKeyType: ConsumerCredentials::TYPE_API_KEY
		);
		if ($revoked === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		if ($revoked === false) {
			return $this->refusal(status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['revoked' => $credentialId]);
	}//end destroy()

	/**
	 * The refusal for a status, worded.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function refusal(int $status): JSONResponse {
		$message = match ($status) {
			Http::STATUS_UNAUTHORIZED => $this->l->t('Not authenticated'),
			Http::STATUS_FORBIDDEN => $this->l->t('The developer portal is open to members of the developer group only.'),
			Http::STATUS_NOT_FOUND => $this->l->t('No such application'),
			Http::STATUS_SERVICE_UNAVAILABLE => $this->l->t('The application could not be read. Try again later.'),
			default => $this->l->t('The consumer could not be saved. Nothing changed.'),
		};

		return new JSONResponse(['error' => $message], $status);
	}//end refusal()
}//end class
