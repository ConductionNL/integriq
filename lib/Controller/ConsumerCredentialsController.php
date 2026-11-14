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

use DateTimeImmutable;
use OCA\Integriq\Service\Auth\ClientCertificateReader;
use OCA\Integriq\Service\Consumer\ConsumerCredentials;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

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
	 * @param OrObjectService     $objectService OpenRegister's object service.
	 * @param ConsumerCredentials $credentials   The credentials list rules.
	 * @param IL10N               $l             Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly OrObjectService $objectService,
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

		$consumer = $this->findConsumer(id: $id);
		if ($consumer === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		$now = new DateTimeImmutable();
		$shown = [];
		foreach ($this->listOf(data: $consumer->getObject()) as $credential) {
			$expired = ($this->credentials->isLive(credential: $credential, now: $now) === false);
			$shown[] = ConsumerCredentials::shown(credential: $credential) + ['expired' => $expired];
		}

		return new JSONResponse(['results' => $shown]);
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
		$refused = $this->refusal();
		if ($refused !== null) {
			return $refused;
		}

		$label = trim((string)$this->request->getParam('label', ''));
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
			return new JSONResponse(
				['error' => $this->l->t('Give a label of at most %1$s characters.', [(string)self::MAX_LABEL_LENGTH])],
				Http::STATUS_BAD_REQUEST
			);
		}

		$expiresAt = $this->expiry(value: $this->request->getParam('expiresAt'));
		if ($expiresAt === false) {
			return new JSONResponse(['error' => $this->l->t('Give the expiry as a date and time.')], Http::STATUS_BAD_REQUEST);
		}

		$consumer = $this->findConsumer(id: $id);
		if ($consumer === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		if ($this->request->getParam('type') === ConsumerCredentials::TYPE_CLIENT_CERTIFICATE) {
			return $this->pinCertificate(consumer: $consumer, label: $label, expiresAt: $expiresAt);
		}

		$generated = $this->credentials->generate(label: $label, expiresAt: $expiresAt);
		$data = $consumer->getObject();
		$list = $this->listOf(data: $data);
		$list[] = $generated['credential'];
		$data[ConsumerCredentials::FIELD] = $list;
		if ($this->save(consumer: $consumer, data: $data) === false) {
			return new JSONResponse(['error' => $this->l->t('The consumer could not be saved. Nothing changed.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			['key' => $generated['key'], 'credential' => ConsumerCredentials::shown(credential: $generated['credential'])],
			Http::STATUS_CREATED
		);
	}//end create()

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
		$certificate = null;
		$issuer = null;
		$pem = $this->request->getParam('certificate');
		if (is_string($pem) === true && trim($pem) !== '') {
			$certificate = ClientCertificateReader::parse(pem: $pem);
		}

		$issuerPem = $this->request->getParam('issuerCertificate');
		if ($certificate === null && is_string($issuerPem) === true && trim($issuerPem) !== '') {
			$issuer = ClientCertificateReader::parse(pem: $issuerPem);
		}

		$pattern = $this->request->getParam('subjectPattern');
		if (is_string($pattern) === false) {
			$pattern = null;
		}

		$entry = $this->credentials->certificateEntry(
			label: $label,
			certificate: $certificate,
			issuer: $issuer,
			subjectPattern: $pattern,
			expiresAt: $expiresAt
		);
		if ($entry === null) {
			return new JSONResponse(
				['error' => $this->l->t('Give a PEM certificate, or an issuing CA certificate and a subject pattern.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$data = $consumer->getObject();
		$data[ConsumerCredentials::FIELD] = array_merge($this->listOf(data: $data), [$entry]);
		if ($this->save(consumer: $consumer, data: $data) === false) {
			return new JSONResponse(['error' => $this->l->t('The consumer could not be saved. Nothing changed.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['credential' => ConsumerCredentials::shown(credential: $entry)], Http::STATUS_CREATED);
	}//end pinCertificate()

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

		$consumer = $this->findConsumer(id: $id);
		if ($consumer === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		$data = $consumer->getObject();
		$list = $this->listOf(data: $data);
		$kept = array_values(array_filter($list, static fn (array $credential): bool => ($credential['id'] ?? null) !== $credentialId));
		if (count($kept) === count($list)) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		$data[ConsumerCredentials::FIELD] = $kept;
		if ($this->save(consumer: $consumer, data: $data) === false) {
			return new JSONResponse(['error' => $this->l->t('The consumer could not be saved. Nothing changed.')], Http::STATUS_INTERNAL_SERVER_ERROR);
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

	/**
	 * The stored credentials list, entries that are not objects dropped.
	 *
	 * @param array<string, mixed> $data The consumer's raw data.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function listOf(array $data): array {
		$list = ($data[ConsumerCredentials::FIELD] ?? []);
		if (is_array($list) === false) {
			return [];
		}

		return array_values(array_filter($list, 'is_array'));
	}//end listOf()

	/**
	 * The consumer's raw object, hashes included, or null.
	 *
	 * @param string $id The consumer's uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function findConsumer(string $id): ?ObjectEntity {
		try {
			$consumer = $this->objectService->find(
				id: $id,
				register: 'integriq',
				schema: 'consumer',
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (DoesNotExistException) {
			return null;
		}

		if (($consumer instanceof ObjectEntity) === false || is_array($consumer->getObject()) === false) {
			return null;
		}

		return $consumer;
	}//end findConsumer()

	/**
	 * Save the consumer.
	 *
	 * @param ObjectEntity         $consumer The consumer.
	 * @param array<string, mixed> $data     Its new data.
	 *
	 * @return bool Whether the save landed.
	 */
	private function save(ObjectEntity $consumer, array $data): bool {
		try {
			$this->objectService->saveObject(
				object: $data,
				register: 'integriq',
				schema: 'consumer',
				uuid: $consumer->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end save()
}//end class
