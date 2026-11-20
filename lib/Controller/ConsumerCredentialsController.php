<?php

/**
 * ConsumerCredentialsController: list, generate and revoke a consumer's credentials.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Consumer\ConsumerCredentialList;
use OCA\Integriq\Service\Consumer\ConsumerCredentials;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A consumer's credentials: the list without hashes, a generated key shown
 * once in the response that creates it, and revoking one entry.
 *
 * There is no route that returns a key after it was generated.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
 */
class ConsumerCredentialsController extends Controller {

	/**
	 * Longest label accepted.
	 */
	private const MAX_LABEL_LENGTH = 100;

	/**
	 * Constructor.
	 *
	 * @param string              $appName       The app name.
	 * @param IRequest            $request       The request.
	 * @param IUserSession        $userSession   The session, for the admin check.
	 * @param IGroupManager       $groupManager  The admin check.
	 * @param ConsumerCredentialList $consumers   Reads and writes a consumer's credentials list.
	 * @param ConsumerCredentials $credentials   The credentials list rules.
	 * @param IL10N               $l             Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ConsumerCredentialList $consumers,
		private readonly ConsumerCredentials $credentials,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The consumer's credentials, without their hashes.
	 *
	 * @param string $id The consumer's uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function index(string $id): JSONResponse {
		$refused = $this->refusal();
		if ($refused !== null) {
			return $refused;
		}

		$consumer = $this->consumers->find(id: $id);
		if ($consumer === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['results' => $this->credentials->listed(entries: $this->consumers->entries(data: $consumer->getObject()))]);
	}//end index()

	/**
	 * Generate an API key, store its hash, and return the key in this one response.
	 *
	 * @param string $id The consumer's uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function create(string $id): JSONResponse {
		$label     = trim((string)$this->request->getParam('label', ''));
		$expiresAt = $this->expiry(value: $this->request->getParam('expiresAt'));
		$refused   = $this->createRefusal(label: $label);
		if ($refused !== null) {
			return $refused;
		}

		if ($expiresAt === false) {
			return new JSONResponse(['error' => $this->l->t('Give the expiry as a date and time.')], Http::STATUS_BAD_REQUEST);
		}

		$consumer = $this->consumers->find(id: $id);
		if ($consumer === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		if ($this->request->getParam('type') === ConsumerCredentials::TYPE_CLIENT_CERTIFICATE) {
			return $this->pinCertificate(consumer: $consumer, label: $label, expiresAt: $expiresAt);
		}

		return $this->storeGeneratedKey(consumer: $consumer, label: $label, expiresAt: $expiresAt);
	}//end create()

	/**
	 * Why a create request is refused before its expiry is looked at: not an
	 * administrator, then the label; null when it is not.
	 *
	 * @param string $label The trimmed label.
	 *
	 * @return JSONResponse|null
	 */
	private function createRefusal(string $label): ?JSONResponse {
		$refused = $this->refusal();
		if ($refused !== null) {
			return $refused;
		}

		return $this->labelRefusal(label: $label);
	}//end createRefusal()

	/**
	 * A 400 for an empty or too long label, else null.
	 *
	 * @param string $label The trimmed label.
	 *
	 * @return JSONResponse|null
	 */
	private function labelRefusal(string $label): ?JSONResponse {
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
			return new JSONResponse(
				['error' => $this->l->t('Give a label of at most %1$s characters.', [(string)self::MAX_LABEL_LENGTH])],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end labelRefusal()

	/**
	 * Generate an API key, store its hashed entry, and answer the key once.
	 *
	 * @param ObjectEntity $consumer  The consumer.
	 * @param string       $label     The label.
	 * @param string|null  $expiresAt ISO-8601 expiry, or null.
	 *
	 * @return JSONResponse
	 */
	private function storeGeneratedKey(ObjectEntity $consumer, string $label, ?string $expiresAt): JSONResponse {
		$generated = $this->credentials->generate(label: $label, expiresAt: $expiresAt);
		if ($this->consumers->add(consumer: $consumer, entry: $generated['credential']) === false) {
			return $this->notSaved();
		}

		return new JSONResponse(
			['key' => $generated['key'], 'credential' => $this->credentials->shown(credential: $generated['credential'])],
			Http::STATUS_CREATED
		);
	}//end storeGeneratedKey()

	/**
	 * Pin a client certificate on a consumer: the certificate itself, or an
	 * issuing CA with a subject pattern. Nothing secret is stored or returned.
	 *
	 * @param ObjectEntity $consumer  The consumer.
	 * @param string       $label     The label.
	 * @param string|null  $expiresAt ISO-8601 expiry, or null.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 */
	private function pinCertificate(ObjectEntity $consumer, string $label, ?string $expiresAt): JSONResponse {
		$certificate = $this->credentials->certificateOf(pem: $this->stringParam(name: 'certificate'));
		$issuer      = null;
		if ($certificate === null) {
			$issuer = $this->credentials->certificateOf(pem: $this->stringParam(name: 'issuerCertificate'));
		}

		$entry = $this->credentials->certificateEntry(
			label: $label,
			certificate: $certificate,
			issuer: $issuer,
			subjectPattern: $this->stringParam(name: 'subjectPattern'),
			expiresAt: $expiresAt
		);
		if ($entry === null) {
			return new JSONResponse(
				['error' => $this->l->t('Give a PEM certificate, or an issuing CA certificate and a subject pattern.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($this->consumers->add(consumer: $consumer, entry: $entry) === false) {
			return $this->notSaved();
		}

		return new JSONResponse(['credential' => $this->credentials->shown(credential: $entry)], Http::STATUS_CREATED);
	}//end pinCertificate()

	/**
	 * A request parameter when it is a string, else null.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string|null
	 */
	private function stringParam(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return null;
		}

		return $value;
	}//end stringParam()

	/**
	 * The 500 answered when the consumer could not be saved.
	 *
	 * @return JSONResponse
	 */
	private function notSaved(): JSONResponse {
		return new JSONResponse(['error' => $this->l->t('The consumer could not be saved. Nothing changed.')], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end notSaved()

	/**
	 * Revoke one credential, leaving the others as they are.
	 *
	 * @param string $id           The consumer's uuid.
	 * @param string $credentialId The credential's id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function destroy(string $id, string $credentialId): JSONResponse {
		$refused = $this->refusal();
		if ($refused !== null) {
			return $refused;
		}

		$consumer = $this->consumers->find(id: $id);
		if ($consumer === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		$revoked = $this->consumers->remove(consumer: $consumer, credentialId: $credentialId);
		if ($revoked === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		if ($revoked === false) {
			return $this->notSaved();
		}

		return new JSONResponse(['revoked' => $credentialId]);
	}//end destroy()

	/**
	 * A 401 or 403 when the caller is not an administrator, else null.
	 *
	 * @return JSONResponse|null
	 */
	private function refusal(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) !== true) {
			return new JSONResponse(['error' => $this->l->t('Only an administrator can manage consumer credentials.')], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refusal()

	/**
	 * An ISO-8601 expiry, null when none was given, false when it cannot be read.
	 *
	 * @param mixed $value The request value.
	 *
	 * @return string|null|false
	 */
	private function expiry(mixed $value): string|null|false {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_string($value) === false) {
			return false;
		}

		$date = date_create_immutable($value);
		if ($date === false) {
			return false;
		}

		return $date->format(DATE_ATOM);
	}//end expiry()

}//end class
