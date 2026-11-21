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
use OCA\Integriq\Service\Consumer\ConsumerCredentialList;
use OCA\Integriq\Service\Consumer\ConsumerCredentials;
use OCA\Integriq\Service\DeveloperPortalAccess;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

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
	 * @param IRequest               $request     The request.
	 * @param DeveloperPortalAccess  $access      Who a developer is and what they own.
	 * @param IUserSession           $userSession The session.
	 * @param ConsumerCredentialList $consumers   Reads and writes an application's credentials list.
	 * @param ConsumerCredentials    $credentials The credentials list rules.
	 * @param IL10N                  $l           Translates the refusals.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	public function __construct(
		IRequest $request,
		private readonly DeveloperPortalAccess $access,
		private readonly IUserSession $userSession,
		private readonly ConsumerCredentialList $consumers,
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
	 * @return JSONResponse `{results: list}`, or 401, 403 or 404.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	#[NoAdminRequired]
	public function index(string $consumerId): JSONResponse {
		$application = $this->ownedApplication(consumerId: $consumerId);
		if ($application instanceof JSONResponse) {
			return $application;
		}

		$entries = $this->consumers->entries(data: $application->getObject());

		return new JSONResponse(['results' => $this->credentials->listed(entries: $entries)]);
	}//end index()

	/**
	 * Generate an API key for the application and answer it in this one response.
	 *
	 * @param string $consumerId The application's id.
	 *
	 * @return JSONResponse 201 with `{key, credential}`, or 400, 401, 403, 404 or 500.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	#[NoAdminRequired]
	public function create(string $consumerId): JSONResponse {
		$application = $this->ownedApplication(consumerId: $consumerId);
		if ($application instanceof JSONResponse) {
			return $application;
		}

		$label = trim((string)$this->request->getParam('label', ''));
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
			$message = $this->l->t('Give a label of at most %1$s characters.', [(string)self::MAX_LABEL_LENGTH]);
			return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
		}

		$generated = $this->credentials->generate(label: $label);
		if ($this->consumers->add(consumer: $application, entry: $generated['credential']) === false) {
			return $this->notSaved();
		}

		$shown = $this->credentials->shown(credential: $generated['credential']);

		return new JSONResponse(['key' => $generated['key'], 'credential' => $shown], Http::STATUS_CREATED);
	}//end create()

	/**
	 * Revoke one API key of the application, leaving its other credentials as they are.
	 *
	 * Only an API key: a client certificate is pinned by an administrator, so
	 * the portal does not remove one.
	 *
	 * @param string $consumerId   The application's id.
	 * @param string $credentialId The key's id.
	 *
	 * @return JSONResponse `{revoked}`, or 401, 403, 404 or 500.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	#[NoAdminRequired]
	public function destroy(string $consumerId, string $credentialId): JSONResponse {
		$application = $this->ownedApplication(consumerId: $consumerId);
		if ($application instanceof JSONResponse) {
			return $application;
		}

		if ($this->isApiKey(application: $application, credentialId: $credentialId) === false) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		if ($this->consumers->remove(consumer: $application, credentialId: $credentialId) !== true) {
			return $this->notSaved();
		}

		return new JSONResponse(['revoked' => $credentialId]);
	}//end destroy()

	/**
	 * The application when the signed-in account is a developer who owns it,
	 * else the refusal to answer with: 401 signed out, 403 outside the
	 * developer group, 404 for an application that is not theirs or not there.
	 *
	 * @param string $consumerId The application's id.
	 *
	 * @return ObjectEntity|JSONResponse The application, or the refusal.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/design.md#d2-an-application-is-a-consumer-the-developer-owns
	 */
	private function ownedApplication(string $consumerId): ObjectEntity|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->access->isDeveloper(user: $user) === false) {
			$refusal = $this->l->t('The developer portal is open to members of the developer group only.');
			return new JSONResponse(['error' => $refusal], Http::STATUS_FORBIDDEN);
		}

		$application = $this->consumers->find(id: $consumerId);
		if ($this->access->owns(user: $user, consumer: $application) === false) {
			return new JSONResponse(['error' => $this->l->t('No such application')], Http::STATUS_NOT_FOUND);
		}

		return $application;
	}//end ownedApplication()

	/**
	 * Whether the application holds an API key with this id.
	 *
	 * @param ObjectEntity $application  The application.
	 * @param string       $credentialId The credential's id.
	 *
	 * @return bool True for an API key of this application.
	 */
	private function isApiKey(ObjectEntity $application, string $credentialId): bool {
		foreach ($this->consumers->entries(data: $application->getObject()) as $entry) {
			if (($entry['id'] ?? null) === $credentialId) {
				return ($entry['type'] ?? null) === ConsumerCredentials::TYPE_API_KEY;
			}
		}

		return false;
	}//end isApiKey()

	/**
	 * The 500 answered when the application could not be saved.
	 *
	 * @return JSONResponse
	 */
	private function notSaved(): JSONResponse {
		$message = $this->l->t('The consumer could not be saved. Nothing changed.');
		return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end notSaved()
}//end class
