<?php

/**
 * OpenFormulierenSettingsController
 *
 * Admin-only API for the Open Formulieren connection: the instance's one
 * `open-formulieren` consumer. It holds the webhook signature trust (scheme,
 * secret, header, tolerance) and the Nextcloud account the intake acts as.
 * Both endpoints are gated at the middleware layer via
 * #[AuthorizedAdminSetting], so no in-body authorization is required.
 * It mirrors DsoPkiSettingsController.
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
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\Integriq\Service\WebhookSignatureService;
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
 * Admin-only controller for the Open Formulieren connection (`open-formulieren` consumer).
 *
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#requirement-the-open-formulieren-connections-account-is-chosen-and-checked-by-an-administrator-req-007
 */
class OpenFormulierenSettingsController extends Controller {

	/**
	 * The signature schemes WebhookSignatureService verifies.
	 *
	 * @var list<string>
	 */
	public const SCHEMES = ['openconnector', 'stripe', 'github', 'teams'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request      The request.
	 * @param OpenFormulierenConnection $connection   Finds, checks and saves the consumer.
	 * @param IGroupManager             $groupManager Tells an administrator account apart.
	 * @param IntakeGroups              $groups       Puts the chosen account in the openformulieren-intake group.
	 * @param IL10N                     $l            Field errors and warnings.
	 * @param LoggerInterface           $logger       Diagnostics.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
	 */
	public function __construct(
		IRequest $request,
		private readonly OpenFormulierenConnection $connection,
		private readonly IGroupManager $groupManager,
		private readonly IntakeGroups $groups,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Read the Open Formulieren connection. Never returns the secret.
	 *
	 * @return JSONResponse The connection state.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#requirement-the-open-formulieren-connections-account-is-chosen-and-checked-by-an-administrator-req-007
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getConfig(): JSONResponse {
		try {
			$consumer = $this->connection->findConsumer();
		} catch (DsoConnectionUnavailableException) {
			return $this->ambiguous();
		}

		$data = [];
		if ($consumer !== null) {
			$data = $consumer->getObject();
		}

		$trust = ($data['authorizationConfiguration'] ?? []);
		if (is_array($trust) === false) {
			$trust = [];
		}

		$userId = (string)($data['userId'] ?? '');

		return new JSONResponse(
			[
				'configured' => ($consumer !== null),
				'scheme' => (string)($trust['scheme'] ?? OpenFormulierenConnection::DEFAULT_SCHEME),
				'secretConfigured' => ((string)($trust['secret'] ?? '') !== ''),
				'header' => (string)($trust['header'] ?? OpenFormulierenConnection::DEFAULT_HEADER),
				'toleranceSeconds' => (int)($trust['toleranceSeconds'] ?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS),
				'userId' => $userId,
				'account' => $this->describeAccount(userId: $userId),
			]
		);

	}//end getConfig()

	/**
	 * Save the Open Formulieren connection.
	 *
	 * Refuses, with a field error, an account that does not exist, is
	 * disabled, or lacks `create` and `update` on `openformulieren_submission`.
	 * Warns, without refusing, when the account is an administrator. A blank
	 * secret keeps the stored one.
	 *
	 * @return JSONResponse The saved state, or 400/409 with errors.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#requirement-the-open-formulieren-connections-account-is-chosen-and-checked-by-an-administrator-req-007
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setConfig(): JSONResponse {
		$scheme = (string)$this->request->getParam('scheme', OpenFormulierenConnection::DEFAULT_SCHEME);
		if (in_array($scheme, self::SCHEMES, true) === false) {
			$schemeError = $this->l->t('Unknown signature scheme %s.', [$scheme]);
			return new JSONResponse(
				['errors' => [$schemeError], 'fieldErrors' => ['scheme' => $schemeError]],
				Http::STATUS_BAD_REQUEST
			);
		}

		$header = trim((string)$this->request->getParam('header', OpenFormulierenConnection::DEFAULT_HEADER));
		if ($header === '') {
			$header = OpenFormulierenConnection::DEFAULT_HEADER;
		}

		$trust = [
			'scheme' => $scheme,
			'secret' => (string)$this->request->getParam('secret', ''),
			'header' => $header,
			'toleranceSeconds' => max(1, (int)$this->request->getParam('toleranceSeconds', WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS)),
		];
		$userId = trim((string)$this->request->getParam('userId', ''));

		$warnings = [];
		if ($userId !== '') {
			$fieldError = $this->accountError(userId: $userId, warnings: $warnings);
			if ($fieldError !== null) {
				return new JSONResponse(
					['errors' => [$fieldError], 'fieldErrors' => ['userId' => $fieldError]],
					Http::STATUS_BAD_REQUEST
				);
			}
		}

		try {
			$consumer = $this->connection->findConsumer();
		} catch (DsoConnectionUnavailableException) {
			return $this->ambiguous();
		}

		try {
			$this->connection->saveConsumer(
				data: $this->consumerData(consumer: $consumer, trust: $trust, userId: $userId),
				uuid: $consumer?->getUuid()
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[OpenFormulierenSettingsController] the Open Formulieren connection was not saved',
				['exception' => $exception->getMessage()]
			);
			return new JSONResponse(
				['errors' => [$this->l->t('The Open Formulieren connection was not saved: %s', [$exception->getMessage()])]],
				Http::STATUS_BAD_REQUEST
			);
		}

		$this->withdrawPrevious(consumer: $consumer, userId: $userId);

		return new JSONResponse(
			[
				'scheme' => $scheme,
				'userId' => $userId,
				'account' => $this->describeAccount(userId: $userId),
				'warnings' => $warnings,
			]
		);

	}//end setConfig()

	/**
	 * The consumer data to save, keeping the stored secret when none was sent.
	 *
	 * @param ObjectEntity|null    $consumer The existing consumer, or null.
	 * @param array<string, mixed> $trust    The submitted trust.
	 * @param string               $userId   The chosen account, or ''.
	 *
	 * @return array<string, mixed> The consumer data.
	 */
	private function consumerData(?ObjectEntity $consumer, array $trust, string $userId): array {
		$data = [
			'name' => 'Open Formulieren',
			'description' => 'Signed submissions of Open Formulieren. Every submission is stored as the account in userId.',
		];
		if ($consumer !== null) {
			$data = $consumer->getObject();
		}

		if ($trust['secret'] === '') {
			$trust['secret'] = (string)(((array)($data['authorizationConfiguration'] ?? []))['secret'] ?? '');
		}

		$data['authorizationType'] = OpenFormulierenConnection::AUTHORIZATION_TYPE;
		$data['authorizationConfiguration'] = $trust;
		$data['userId'] = $userId;

		return $data;

	}//end consumerData()

	/**
	 * The 409 answer when more than one connection exists.
	 *
	 * @return JSONResponse The answer.
	 */
	private function ambiguous(): JSONResponse {
		return new JSONResponse(
			['errors' => [$this->l->t('More than one Open Formulieren connection exists. Remove all but one on the Consumers page.')]],
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
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#requirement-submissions-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-008
	 */
	private function withdrawPrevious(?ObjectEntity $consumer, string $userId): void {
		$previous = (string)(($consumer?->getObject() ?? [])['userId'] ?? '');
		if ($previous !== '' && $previous !== $userId) {
			$this->groups->withdraw(groupId: IntakeGroups::OPEN_FORMULIEREN_INTAKE, userId: $previous);
		}

	}//end withdrawPrevious()

	/**
	 * Why the account cannot be the intake account, or null when it can.
	 *
	 * @param string       $userId   The chosen uid.
	 * @param list<string> $warnings Collects non-blocking warnings.
	 *
	 * @return string|null The field error, or null.
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
		$wasMember = $this->groups->isMember(groupId: IntakeGroups::OPEN_FORMULIEREN_INTAKE, userId: $userId);
		$this->groups->enrol(groupId: IntakeGroups::OPEN_FORMULIEREN_INTAKE, userId: $userId);

		$missing = $this->connection->missingRights(userId: $userId);
		if ($missing !== null && $missing !== [] && $wasMember === false) {
			$this->groups->withdraw(groupId: IntakeGroups::OPEN_FORMULIEREN_INTAKE, userId: $userId);
		}

		if ($missing === null) {
			$warnings[] = $this->l->t('The rights of account %s could not be checked. Submissions are refused until they can be.', [$userId]);
		} elseif ($missing !== []) {
			return $this->l->t('Account %1$s lacks the %2$s right on Open Formulieren submissions.', [$userId, implode(', ', $missing)]);
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			$warnings[] = $this->l->t('Account %s is an administrator. A dedicated account keeps the audit trail readable.', [$userId]);
		}

		return null;

	}//end accountError()

	/**
	 * The one-line state of an account.
	 *
	 * @param string $userId The uid, or ''.
	 *
	 * @return array{state: string, displayName: string} The state.
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
