<?php

/**
 * WebhookConnectionsSettingsController
 *
 * Admin-only API for the signed public webhooks on the consumer model: one
 * consumer per webhook ({@see WebhookProfiles::all()}), holding the signature
 * trust and the Nextcloud account the webhook acts as. It is the Open
 * Formulieren connection settings with the webhook as a parameter. Both
 * endpoints are gated at the middleware layer via #[AuthorizedAdminSetting],
 * so no in-body authorization is required.
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
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-an-administrator-chooses-each-webhooks-account-req-cm-021
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Intake\WebhookConnection;
use OCA\Integriq\Service\Intake\WebhookProfile;
use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin-only controller for the webhook connections.
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-an-administrator-chooses-each-webhooks-account-req-cm-021
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) the connection, the profiles, the admin check, l10n,
 * logger and the HTTP and OpenRegister types it answers with; one admin form, one class.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) WebhookProfiles is a final catalogue of pure lookups; there is nothing to inject.
 */
class WebhookConnectionsSettingsController extends Controller {

	/**
	 * The signature schemes WebhookSignatureService verifies.
	 *
	 * @var list<string>
	 */
	public const SCHEMES = ['openconnector', 'stripe', 'github', 'teams'];

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request       The request.
	 * @param WebhookConnection $webhooks      Finds and checks each webhook's consumer and account.
	 * @param ORObjectService   $objectService Saves a consumer as the administrator, under RBAC.
	 * @param IGroupManager     $groupManager  Tells an administrator account apart.
	 * @param IL10N             $l             Field errors and warnings.
	 * @param LoggerInterface   $logger        Diagnostics.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public function __construct(
		IRequest $request,
		private readonly WebhookConnection $webhooks,
		private readonly ORObjectService $objectService,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * List every webhook connection. Never returns a secret.
	 *
	 * @return JSONResponse `{connections: [...]}`.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-the-settings-list-every-webhook-without-its-secret
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getConfig(): JSONResponse {
		$connections = [];
		foreach (WebhookProfiles::all() as $profile) {
			$connections[] = $this->describe(profile: $profile);
		}

		return new JSONResponse(['connections' => $connections]);

	}//end getConfig()

	/**
	 * Save one webhook connection: its trust and its account.
	 *
	 * Refuses, with a field error, an account that does not exist, is
	 * disabled, or lacks the webhook's rights on its schema. Warns, without
	 * refusing, when the account is an administrator. A blank secret keeps the
	 * stored one.
	 *
	 * @param string $authorizationType The webhook's consumer type.
	 *
	 * @return JSONResponse The saved state, or 400/404/409 with errors.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-administrator-chooses-a-webhooks-account
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setConfig(string $authorizationType): JSONResponse {
		$profile = WebhookProfiles::byAuthorizationType(authorizationType: $authorizationType);
		if ($profile === null) {
			return new JSONResponse(['errors' => [$this->l->t('Unknown webhook %s.', [$authorizationType])]], Http::STATUS_NOT_FOUND);
		}

		$scheme = (string)$this->request->getParam('scheme', $profile->defaultScheme);
		if (in_array($scheme, self::SCHEMES, true) === false) {
			$schemeError = $this->l->t('Unknown signature scheme %s.', [$scheme]);
			return new JSONResponse(['errors' => [$schemeError], 'fieldErrors' => ['scheme' => $schemeError]], Http::STATUS_BAD_REQUEST);
		}

		$userId = trim((string)$this->request->getParam('userId', ''));
		$warnings = [];
		if ($userId !== '') {
			$fieldError = $this->accountError(profile: $profile, userId: $userId, warnings: $warnings);
			if ($fieldError !== null) {
				return new JSONResponse(['errors' => [$fieldError], 'fieldErrors' => ['userId' => $fieldError]], Http::STATUS_BAD_REQUEST);
			}
		}

		try {
			$consumer = $this->webhooks->findConsumer(profile: $profile);
		} catch (DsoConnectionUnavailableException) {
			return new JSONResponse(
				['errors' => [$this->l->t('More than one %s connection exists. Remove all but one on the Consumers page.', [$profile->label])]],
				Http::STATUS_CONFLICT
			);
		}

		$header = trim((string)$this->request->getParam('header', $profile->defaultHeader));
		if ($header === '') {
			$header = $profile->defaultHeader;
		}

		$trust = [
			'scheme' => $scheme,
			'secret' => (string)$this->request->getParam('secret', ''),
			'header' => $header,
			'toleranceSeconds' => max(1, (int)$this->request->getParam('toleranceSeconds', WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS)),
		];

		try {
			$this->objectService->saveObject(
				object: $this->consumerData(profile: $profile, consumer: $consumer, trust: $trust, userId: $userId),
				register: DsoConnection::REGISTER,
				schema: DsoConnection::SCHEMA_CONSUMER,
				uuid: $consumer?->getUuid()
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[WebhookConnectionsSettingsController] the ' . $profile->label . ' connection was not saved',
				['exception' => $exception->getMessage()]
			);
			return new JSONResponse(
				['errors' => [$this->l->t('The %1$s connection was not saved: %2$s', [$profile->label, $exception->getMessage()])]],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(['connection' => $this->describe(profile: $profile), 'warnings' => $warnings]);

	}//end setConfig()

	/**
	 * The state of one webhook connection, without its secret.
	 *
	 * @param WebhookProfile $profile The webhook.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(WebhookProfile $profile): array {
		$ambiguous = false;
		try {
			$consumer = $this->webhooks->findConsumer(profile: $profile);
		} catch (DsoConnectionUnavailableException) {
			$consumer = null;
			$ambiguous = true;
		}

		$data = ($consumer?->getObject() ?? []);
		$trust = ($data['authorizationConfiguration'] ?? []);
		if (is_array($trust) === false) {
			$trust = [];
		}

		$userId = (string)($data['userId'] ?? '');

		return [
			'authorizationType' => $profile->authorizationType,
			'label' => $profile->label,
			'schema' => $profile->schema,
			'configured' => ($consumer !== null),
			'ambiguous' => $ambiguous,
			'scheme' => (string)($trust['scheme'] ?? $profile->defaultScheme),
			'secretConfigured' => ((string)($trust['secret'] ?? '') !== ''),
			'header' => (string)($trust['header'] ?? $profile->defaultHeader),
			'toleranceSeconds' => (int)($trust['toleranceSeconds'] ?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS),
			'userId' => $userId,
			'account' => $this->describeAccount(profile: $profile, userId: $userId),
		];

	}//end describe()

	/**
	 * The consumer data to save, keeping the stored secret when none was sent.
	 *
	 * @param WebhookProfile       $profile  The webhook.
	 * @param ObjectEntity|null    $consumer The existing consumer, or null.
	 * @param array<string, mixed> $trust    The submitted trust.
	 * @param string               $userId   The chosen account, or ''.
	 *
	 * @return array<string, mixed>
	 */
	private function consumerData(WebhookProfile $profile, ?ObjectEntity $consumer, array $trust, string $userId): array {
		$data = [
			'name' => $profile->label . ' webhook',
			'description' => 'Signed deliveries of ' . $profile->label . '. Every delivery is stored as the account in userId.',
		];
		if ($consumer !== null) {
			$data = $consumer->getObject();
		}

		if ($trust['secret'] === '') {
			$trust['secret'] = (string)(((array)($data['authorizationConfiguration'] ?? []))['secret'] ?? '');
		}

		$data['authorizationType'] = $profile->authorizationType;
		$data['authorizationConfiguration'] = $trust;
		$data['userId'] = $userId;

		return $data;

	}//end consumerData()

	/**
	 * Why the account cannot be the webhook's account, or null when it can.
	 *
	 * @param WebhookProfile $profile  The webhook.
	 * @param string         $userId   The chosen uid.
	 * @param list<string>   $warnings Collects non-blocking warnings.
	 *
	 * @return string|null The field error, or null.
	 */
	private function accountError(WebhookProfile $profile, string $userId, array &$warnings): ?string {
		try {
			$this->webhooks->resolveAccount(profile: $profile, userId: $userId);
		} catch (DsoConnectionUnavailableException $exception) {
			if ($exception->getReason() === DsoConnectionUnavailableException::ACCOUNT_DISABLED) {
				return $this->l->t('Account %s is disabled.', [$userId]);
			}

			return $this->l->t('Account %s does not exist.', [$userId]);
		}

		$missing = $this->webhooks->missingRights(profile: $profile, userId: $userId);
		if ($missing === null) {
			$warnings[] = $this->l->t('The rights of account %s could not be checked. Deliveries are refused until they can be.', [$userId]);
		} elseif ($missing !== []) {
			return $this->l->t('Account %1$s lacks the %2$s right on %3$s.', [$userId, implode(', ', $missing), $profile->schema]);
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			$warnings[] = $this->l->t('Account %s is an administrator. A dedicated account keeps the audit trail readable.', [$userId]);
		}

		return null;

	}//end accountError()

	/**
	 * The one-line state of an account.
	 *
	 * @param WebhookProfile $profile The webhook.
	 * @param string         $userId  The uid, or ''.
	 *
	 * @return array{state: string, displayName: string}
	 */
	private function describeAccount(WebhookProfile $profile, string $userId): array {
		if ($userId === '') {
			return ['state' => 'none', 'displayName' => ''];
		}

		try {
			$account = $this->webhooks->resolveAccount(profile: $profile, userId: $userId);
		} catch (DsoConnectionUnavailableException $exception) {
			$state = 'unknown';
			if ($exception->getReason() === DsoConnectionUnavailableException::ACCOUNT_DISABLED) {
				$state = 'disabled';
			}

			return ['state' => $state, 'displayName' => $userId];
		}

		return ['state' => 'ok', 'displayName' => $account->getDisplayName()];

	}//end describeAccount()
}//end class
