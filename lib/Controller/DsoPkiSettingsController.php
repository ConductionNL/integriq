<?php

/**
 * DsoPkiSettingsController
 *
 * Admin-only API for the DSO connection: the instance's one `dso-stam`
 * consumer. It holds the STAM signature trust (signing mode, HMAC secret,
 * PKIoverheid certificate chain) and the Nextcloud account the intake acts
 * as. Both endpoints are gated at the middleware layer via
 * #[AuthorizedAdminSetting], so no in-body authorization is required.
 *
 * Until dso-intake-through-an-integriq-connection this read and wrote app
 * config keys (`dso_pki_*`). Those are now read once, by the
 * MigrateDsoStamConnection repair step.
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
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\DSOSignatureVerifierService;
use OCA\Integriq\Service\Intake\IntakeGroups;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
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
 * Admin-only controller for the DSO connection (`dso-stam` consumer).
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#requirement-the-dso-connections-account-is-chosen-and-checked-by-an-administrator-req-dso-071
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) the connection, its signature or group helpers, the
 * admin check, l10n, logger and the HTTP and OpenRegister types it answers with; splitting would
 * spread one admin form over several classes.
 */
class DsoPkiSettingsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request           The request.
	 * @param DsoConnection               $connection        Finds, checks and saves the consumer.
	 * @param DSOSignatureVerifierService $signatureVerifier Chain-validation helper.
	 * @param IGroupManager               $groupManager      Tells an administrator account apart.
	 * @param IntakeGroups                $groups            Puts the chosen account in the dso-intake group.
	 * @param IL10N                       $l                 Field errors and warnings.
	 * @param LoggerInterface             $logger            Diagnostics.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
	 */
	public function __construct(
		IRequest $request,
		private readonly DsoConnection $connection,
		private readonly DSOSignatureVerifierService $signatureVerifier,
		private readonly IGroupManager $groupManager,
		private readonly IntakeGroups $groups,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Read the DSO connection. Never returns the HMAC secret.
	 *
	 * @return JSONResponse The mode, which trust is set, the PEM chain, and the account.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getConfig(): JSONResponse {
		try {
			$consumer = $this->connection->findConsumer();
		} catch (DsoConnectionUnavailableException $exception) {
			return $this->ambiguous();
		}

		$data = [];
		if ($consumer !== null) {
			$data = $consumer->getObject();
		}

		$trust = $data['authorizationConfiguration'] ?? [];
		if (is_array($trust) === false) {
			$trust = [];
		}

		$userId = (string)($data['userId'] ?? '');

		return new JSONResponse(
			[
				'configured' => ($consumer !== null),
				'mode' => $this->signatureVerifier->normalizeMode(mode: ($trust['mode'] ?? null)),
				'hmacSecretConfigured' => ((string)($trust['hmacSecret'] ?? '') !== ''),
				'signingCertificate' => (string)($trust['signingCertificate'] ?? ''),
				'intermediateChain' => (string)($trust['intermediateChain'] ?? ''),
				'rootCa' => (string)($trust['rootCa'] ?? ''),
				'userId' => $userId,
				'account' => $this->describeAccount(userId: $userId),
				'handlerGroup' => $this->groups->describe(groupId: IntakeGroups::DSO_HANDLERS),
			]
		);

	}//end getConfig()

	/**
	 * Write the DSO connection, creating the `dso-stam` consumer when there is none.
	 *
	 * Refuses an account that does not exist, is disabled, or lacks `create`
	 * or `update` on `dso_verzoek`, with a field error. Warns, without
	 * refusing, for an administrator account or when the rights cannot be checked.
	 *
	 * @return JSONResponse The saved mode and account, with warnings; 400 with errors.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setConfig(): JSONResponse {
		$mode = $this->signatureVerifier->normalizeMode(mode: $this->request->getParam('mode', DSOSignatureVerifierService::MODE_HMAC));
		$trust = [
			'mode' => $mode,
			'hmacSecret' => (string)$this->request->getParam('hmacSecret', ''),
			'signingCertificate' => (string)$this->request->getParam('signingCertificate', ''),
			'intermediateChain' => (string)$this->request->getParam('intermediateChain', ''),
			'rootCa' => (string)$this->request->getParam('rootCa', ''),
		];
		$userId = trim((string)$this->request->getParam('userId', ''));

		$warnings = [];
		$refusal = $this->refusal(trust: $trust, userId: $userId, warnings: $warnings);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$consumer = $this->connection->findConsumer();
		} catch (DsoConnectionUnavailableException $exception) {
			return $this->ambiguous();
		}

		try {
			$this->connection->saveConsumer(
				data: $this->consumerData(consumer: $consumer, trust: $trust, userId: $userId),
				uuid: $consumer?->getUuid()
			);
		} catch (Throwable $exception) {
			$this->logger->error('[DsoPkiSettingsController] the DSO connection was not saved', ['exception' => $exception->getMessage()]);
			return new JSONResponse(
				['errors' => [$this->l->t('The DSO connection was not saved: %s', [$exception->getMessage()])]],
				Http::STATUS_BAD_REQUEST
			);
		}

		$this->withdrawPrevious(consumer: $consumer, userId: $userId);

		return new JSONResponse(
			[
				'mode' => $mode,
				'userId' => $userId,
				'account' => $this->describeAccount(userId: $userId),
				'warnings' => $warnings,
			]
		);

	}//end setConfig()

	/**
	 * The 400 answer for a trust chain or account that may not be saved, or null.
	 *
	 * @param array<string, string> $trust    The submitted trust configuration.
	 * @param string                $userId   The chosen uid; empty clears the account.
	 * @param list<string>          $warnings Warnings to add to; passed by reference.
	 *
	 * @return JSONResponse|null The refusal.
	 */
	private function refusal(array $trust, string $userId, array &$warnings): ?JSONResponse {
		if ($trust['mode'] === DSOSignatureVerifierService::MODE_PKIOVERHEID) {
			$errors = $this->signatureVerifier->validateChainConfig(
				certPem: $trust['signingCertificate'],
				rootPem: $trust['rootCa'],
				intermediatePem: $trust['intermediateChain']
			);

			if (empty($errors) === false) {
				return new JSONResponse(['errors' => $errors], Http::STATUS_BAD_REQUEST);
			}
		}

		if ($userId === '') {
			return null;
		}

		$fieldError = $this->accountError(userId: $userId, warnings: $warnings);
		if ($fieldError === null) {
			return null;
		}

		return new JSONResponse(
			['errors' => [$fieldError], 'fieldErrors' => ['userId' => $fieldError]],
			Http::STATUS_BAD_REQUEST
		);

	}//end refusal()

	/**
	 * The consumer as it will be saved.
	 *
	 * @param ObjectEntity|null     $consumer The existing consumer, or null for a new one.
	 * @param array<string, string> $trust    The submitted trust configuration.
	 * @param string                $userId   The chosen account.
	 *
	 * @return array<string, mixed> The consumer data.
	 */
	private function consumerData(?ObjectEntity $consumer, array $trust, string $userId): array {
		$data = [
			'name' => 'DSO-LV (STAM)',
			'description' => 'The STAM koppelvlak of the Omgevingsloket (DSO-LV). Every push is stored as the account in userId.',
		];
		if ($consumer !== null) {
			$data = $consumer->getObject();
		}

		// Only overwrite the HMAC secret when a non-empty value was submitted,
		// so the admin form can save other fields without re-typing (and
		// re-exposing) the secret every time.
		if ($trust['hmacSecret'] === '') {
			$trust['hmacSecret'] = (string)(((array)($data['authorizationConfiguration'] ?? []))['hmacSecret'] ?? '');
		}

		$data['authorizationType'] = DsoConnection::AUTHORIZATION_TYPE;
		$data['authorizationConfiguration'] = $trust;
		$data['userId'] = $userId;

		return $data;

	}//end consumerData()

	/**
	 * The 409 answer when more than one dso-stam consumer exists.
	 *
	 * @return JSONResponse
	 */
	private function ambiguous(): JSONResponse {
		return new JSONResponse(
			['errors' => [$this->l->t('More than one DSO connection exists. Remove all but one on the Consumers page.')]],
			Http::STATUS_CONFLICT
		);

	}//end ambiguous()

	/**
	 * Take the account the connection used before out of the intake group.
	 *
	 * @param ObjectEntity|null $consumer The consumer as it was before the save.
	 * @param string            $userId   The account it has now, or ''.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/dso-omgevingsloket/spec.md#requirement-verzoeken-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-dso-072
	 */
	private function withdrawPrevious(?ObjectEntity $consumer, string $userId): void {
		$previous = (string)(($consumer?->getObject() ?? [])['userId'] ?? '');
		if ($previous !== '' && $previous !== $userId) {
			$this->groups->withdraw(groupId: IntakeGroups::DSO_INTAKE, userId: $previous);
		}

	}//end withdrawPrevious()

	/**
	 * The field error for an account, or null when it may be saved.
	 *
	 * @param string       $userId   The chosen uid.
	 * @param list<string> $warnings Warnings to add to; passed by reference.
	 *
	 * @return string|null The field error.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-an-account-without-rights-is-refused
	 */
	private function accountError(string $userId, array &$warnings): ?string {
		try {
			$this->connection->resolveAccount(userId: $userId);
		} catch (DsoConnectionUnavailableException $exception) {
			if ($exception->getReason() === DsoConnectionUnavailableException::ACCOUNT_DISABLED) {
				return $this->l->t('Account %s is disabled.', [$userId]);
			}

			return $this->l->t('Account %s does not exist.', [$userId]);
		}

		// The authorization block grants the intake group, so the chosen
		// account joins it before its rights are checked. It leaves again when
		// the check still refuses it and it was not a member before.
		$wasMember = $this->groups->isMember(groupId: IntakeGroups::DSO_INTAKE, userId: $userId);
		$this->groups->enrol(groupId: IntakeGroups::DSO_INTAKE, userId: $userId);

		$missing = $this->connection->missingRights(userId: $userId);
		if ($missing !== null && $missing !== [] && $wasMember === false) {
			$this->groups->withdraw(groupId: IntakeGroups::DSO_INTAKE, userId: $userId);
		}

		if ($missing === null) {
			$warnings[] = $this->l->t('The rights of account %s could not be checked. Pushes are refused until they can be.', [$userId]);
		} elseif ($missing !== []) {
			return $this->l->t('Account %1$s lacks the %2$s right on DSO verzoeken.', [$userId, implode(', ', $missing)]);
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			$warnings[] = $this->l->t('Account %s is an administrator. A dedicated account keeps the audit trail readable.', [$userId]);
		}

		return null;

	}//end accountError()

	/**
	 * Describe the account for the admin section's one-line state.
	 *
	 * @param string $userId The uid on the consumer.
	 *
	 * @return array{state: string, displayName: string} `ok`, `none`, `unknown` or `disabled`.
	 */
	private function describeAccount(string $userId): array {
		if ($userId === '') {
			return ['state' => 'none', 'displayName' => ''];
		}

		try {
			$account = $this->connection->resolveAccount(userId: $userId);
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
