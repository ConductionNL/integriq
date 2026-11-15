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

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\Integriq\Service\WebhookSignatureServiceInterface;
use OCA\Integriq\Service\Intake\IntakeGroupsInterface;
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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) the connection, its signature or group helpers, the
 * admin check, l10n, logger and the HTTP and OpenRegister types it answers with; splitting would
 * spread one admin form over several classes.
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
	 * @param IntakeGroupsInterface              $groups       Puts the chosen account in the openformulieren-intake group.
	 * @param IL10N                     $l            Field errors and warnings.
	 * @param LoggerInterface           $logger       Diagnostics.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
	 */
	public function __construct(
		IRequest $request,
		private readonly OpenFormulierenConnection $connection,
		private readonly IGroupManager $groupManager,
		private readonly IntakeGroupsInterface $groups,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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

		return $this->configResponse(stored: new StoredConsumer(consumer: $consumer), configured: ($consumer !== null));

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
		$submitted = $this->submitted();
		if ($submitted instanceof JSONResponse) {
			return $submitted;
		}

		$warnings = [];
		$refusal = $this->accountRefusal(submitted: $submitted, warnings: $warnings);
		if ($refusal !== null) {
			return $refusal;
		}

		$failure = $this->save(submitted: $submitted);
		if ($failure !== null) {
			return $failure;
		}

		return $this->saved(submitted: $submitted, warnings: $warnings);

	}//end setConfig()

	/**
	 * The Open Formulieren connection as the admin form reads it. Never returns the secret.
	 *
	 * @param StoredConsumer $stored     The consumer's trust configuration and account.
	 * @param bool           $configured Whether the consumer exists.
	 *
	 * @return JSONResponse The connection state.
	 */
	private function configResponse(StoredConsumer $stored, bool $configured): JSONResponse {
		$trust = $stored->trust;

		return new JSONResponse(
			[
				'configured' => $configured,
				'scheme' => (string)($trust['scheme'] ?? OpenFormulierenConnection::DEFAULT_SCHEME),
				'secretConfigured' => ((string)($trust['secret'] ?? '') !== ''),
				'header' => (string)($trust['header'] ?? OpenFormulierenConnection::DEFAULT_HEADER),
				'toleranceSeconds' => (int)($trust['toleranceSeconds'] ?? WebhookSignatureServiceInterface::DEFAULT_TOLERANCE_SECONDS),
				'userId' => $stored->userId,
				'account' => $this->accountState()->describe(userId: $stored->userId),
				'handlerGroup' => $this->groups->describe(groupId: IntakeGroupsInterface::OPEN_FORMULIEREN_HANDLERS),
			]
		);
	}//end configResponse()

	/**
	 * The trust and account the request submits, or the 400 answer for an unknown scheme.
	 *
	 * @return SubmittedConnection|JSONResponse
	 */
	private function submitted(): SubmittedConnection|JSONResponse {
		$scheme = (string)$this->request->getParam('scheme', OpenFormulierenConnection::DEFAULT_SCHEME);
		if (in_array($scheme, self::SCHEMES, true) === false) {
			$schemeError = $this->l->t('Unknown signature scheme %s.', [$scheme]);
			return new JSONResponse(
				['errors' => [$schemeError], 'fieldErrors' => ['scheme' => $schemeError]],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new SubmittedConnection(
			trust: $this->submittedTrust(scheme: $scheme),
			userId: trim((string)$this->request->getParam('userId', ''))
		);
	}//end submitted()

	/**
	 * The submitted trust: scheme, secret, header (default when blank) and tolerance (at least 1).
	 *
	 * @param string $scheme The known signature scheme.
	 *
	 * @return array<string, mixed>
	 */
	private function submittedTrust(string $scheme): array {
		$header = trim((string)$this->request->getParam('header', OpenFormulierenConnection::DEFAULT_HEADER));
		if ($header === '') {
			$header = OpenFormulierenConnection::DEFAULT_HEADER;
		}

		return [
			'scheme' => $scheme,
			'secret' => (string)$this->request->getParam('secret', ''),
			'header' => $header,
			'toleranceSeconds' => max(1, (int)$this->request->getParam('toleranceSeconds', WebhookSignatureServiceInterface::DEFAULT_TOLERANCE_SECONDS)),
		];
	}//end submittedTrust()

	/**
	 * The 400 answer for a chosen account that may not be the intake account, or null.
	 *
	 * @param SubmittedConnection $submitted The submitted trust and account.
	 * @param list<string>        $warnings  Collects non-blocking warnings.
	 *
	 * @return JSONResponse|null
	 */
	private function accountRefusal(SubmittedConnection $submitted, array &$warnings): ?JSONResponse {
		if ($submitted->userId === '') {
			return null;
		}

		return $this->accountCheck()->refusal(fieldError: $this->accountError(userId: $submitted->userId, warnings: $warnings));
	}//end accountRefusal()

	/**
	 * Save the consumer, creating it when there is none, and release the previous account.
	 *
	 * @param SubmittedConnection $submitted The submitted trust and account.
	 *
	 * @return JSONResponse|null The 409 or 400 answer when it was not saved, or null.
	 */
	private function save(SubmittedConnection $submitted): ?JSONResponse {
		try {
			$consumer = $this->connection->findConsumer();
		} catch (DsoConnectionUnavailableException) {
			return $this->ambiguous();
		}

		try {
			$this->connection->saveConsumer(data: $this->consumerData(consumer: $consumer, submitted: $submitted), uuid: $consumer?->getUuid());
		} catch (Throwable $exception) {
			return $this->saveFailed(exception: $exception);
		}

		$this->accountCheck()->withdrawPrevious(consumer: $consumer, userId: $submitted->userId);
		return null;
	}//end save()

	/**
	 * The 400 answer, logged, when the consumer could not be saved.
	 *
	 * @param Throwable $exception Why.
	 *
	 * @return JSONResponse
	 */
	private function saveFailed(Throwable $exception): JSONResponse {
		$this->logger->error(
			'[OpenFormulierenSettingsController] the Open Formulieren connection was not saved',
			['exception' => $exception->getMessage()]
		);
		return new JSONResponse(
			['errors' => [$this->l->t('The Open Formulieren connection was not saved: %s', [$exception->getMessage()])]],
			Http::STATUS_BAD_REQUEST
		);
	}//end saveFailed()

	/**
	 * The answer to a saved connection: the scheme and account, with warnings.
	 *
	 * @param SubmittedConnection $submitted The saved trust and account.
	 * @param list<string>        $warnings  The warnings.
	 *
	 * @return JSONResponse
	 */
	private function saved(SubmittedConnection $submitted, array $warnings): JSONResponse {
		return new JSONResponse(
			[
				'scheme' => $submitted->trust['scheme'],
				'userId' => $submitted->userId,
				'account' => $this->accountState()->describe(userId: $submitted->userId),
				'warnings' => $warnings,
			]
		);
	}//end saved()

	/**
	 * The state of the connection's account.
	 *
	 * @return IntakeAccountState
	 */
	private function accountState(): IntakeAccountState {
		return new IntakeAccountState(connection: $this->connection, l: $this->l);
	}//end accountState()

	/**
	 * The checks on the connection's account.
	 *
	 * @return IntakeAccountCheck
	 */
	private function accountCheck(): IntakeAccountCheck {
		return new IntakeAccountCheck(
			connection: $this->connection,
			groups: $this->groups,
			groupManager: $this->groupManager,
			l: $this->l,
			intakeGroup: IntakeGroupsInterface::OPEN_FORMULIEREN_INTAKE
		);
	}//end accountCheck()

	/**
	 * The consumer data to save, keeping the stored secret when none was sent.
	 *
	 * @param ObjectEntity|null   $consumer  The existing consumer, or null.
	 * @param SubmittedConnection $submitted The submitted trust and account.
	 *
	 * @return array<string, mixed> The consumer data.
	 */
	private function consumerData(?ObjectEntity $consumer, SubmittedConnection $submitted): array {
		$trust = $submitted->trust;
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
		$data['userId'] = $submitted->userId;

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
	 * Why the account cannot be the intake account, or null when it can.
	 *
	 * @param string       $userId   The chosen uid.
	 * @param list<string> $warnings Collects non-blocking warnings.
	 *
	 * @return string|null The field error, or null.
	 */
	private function accountError(string $userId, array &$warnings): ?string {
		$unresolvable = $this->accountState()->unresolvable(userId: $userId);
		if ($unresolvable !== null) {
			return $unresolvable;
		}

		$missing = $this->accountCheck()->missingRights(userId: $userId);

		if ($missing === null) {
			$warnings[] = $this->l->t('The rights of account %s could not be checked. Submissions are refused until they can be.', [$userId]);
		} elseif ($missing !== []) {
			return $this->l->t('Account %1$s lacks the %2$s right on Open Formulieren submissions.', [$userId, implode(', ', $missing)]);
		}

		$this->accountCheck()->warnAdministrator(userId: $userId, warnings: $warnings);

		return null;

	}//end accountError()

}//end class
