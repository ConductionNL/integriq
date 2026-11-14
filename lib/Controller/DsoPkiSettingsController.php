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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\Portal\PortalConnectionInterface;
use OCA\Integriq\Service\SignedPayloadVerifierInterface;
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
	 * @param PortalConnectionInterface               $connection        Finds, checks and saves the consumer.
	 * @param SignedPayloadVerifierInterface $signatureVerifier Chain-validation helper.
	 * @param IGroupManager               $groupManager      Tells an administrator account apart.
	 * @param IntakeGroupsInterface                $groups            Puts the chosen account in the dso-intake group.
	 * @param IL10N                       $l                 Field errors and warnings.
	 * @param LoggerInterface             $logger            Diagnostics.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalConnectionInterface $connection,
		private readonly SignedPayloadVerifierInterface $signatureVerifier,
		private readonly IGroupManager $groupManager,
		private readonly IntakeGroupsInterface $groups,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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

		return $this->configResponse(stored: new StoredConsumer(consumer: $consumer), configured: ($consumer !== null));

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
		$submitted = $this->submitted();

		$warnings = [];
		$refusal = $this->refusal(submitted: $submitted, warnings: $warnings);
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
	 * The DSO connection as the admin form reads it. Never returns the HMAC secret.
	 *
	 * @param StoredConsumer $stored     The consumer's trust configuration and account.
	 * @param bool           $configured Whether the consumer exists.
	 *
	 * @return JSONResponse The mode, which trust is set, the PEM chain, and the account.
	 */
	private function configResponse(StoredConsumer $stored, bool $configured): JSONResponse {
		$trust = $stored->trust;

		return new JSONResponse(
			[
				'configured' => $configured,
				'mode' => $this->signatureVerifier->normalizeMode(mode: ($trust['mode'] ?? null)),
				'hmacSecretConfigured' => ((string)($trust['hmacSecret'] ?? '') !== ''),
				'signingCertificate' => (string)($trust['signingCertificate'] ?? ''),
				'intermediateChain' => (string)($trust['intermediateChain'] ?? ''),
				'rootCa' => (string)($trust['rootCa'] ?? ''),
				'userId' => $stored->userId,
				'account' => $this->accountState()->describe(userId: $stored->userId),
				'handlerGroup' => $this->groups->describe(groupId: IntakeGroupsInterface::DSO_HANDLERS),
			]
		);
	}//end configResponse()

	/**
	 * The trust configuration and account the request submits.
	 *
	 * @return SubmittedConnection
	 */
	private function submitted(): SubmittedConnection {
		$mode = $this->signatureVerifier->normalizeMode(mode: $this->request->getParam('mode', SignedPayloadVerifierInterface::MODE_HMAC));

		return new SubmittedConnection(
			trust: [
				'mode' => $mode,
				'hmacSecret' => (string)$this->request->getParam('hmacSecret', ''),
				'signingCertificate' => (string)$this->request->getParam('signingCertificate', ''),
				'intermediateChain' => (string)$this->request->getParam('intermediateChain', ''),
				'rootCa' => (string)$this->request->getParam('rootCa', ''),
			],
			userId: trim((string)$this->request->getParam('userId', ''))
		);
	}//end submitted()

	/**
	 * Save the consumer, creating it when there is none, and release the previous account.
	 *
	 * @param SubmittedConnection $submitted The submitted trust configuration and account.
	 *
	 * @return JSONResponse|null The 409 or 400 answer when it was not saved, or null.
	 */
	private function save(SubmittedConnection $submitted): ?JSONResponse {
		try {
			$consumer = $this->connection->findConsumer();
		} catch (DsoConnectionUnavailableException $exception) {
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
		$this->logger->error('[DsoPkiSettingsController] the DSO connection was not saved', ['exception' => $exception->getMessage()]);
		return new JSONResponse(
			['errors' => [$this->l->t('The DSO connection was not saved: %s', [$exception->getMessage()])]],
			Http::STATUS_BAD_REQUEST
		);
	}//end saveFailed()

	/**
	 * The answer to a saved connection: the mode and account, with warnings.
	 *
	 * @param SubmittedConnection $submitted The saved trust configuration and account.
	 * @param list<string>        $warnings  The warnings.
	 *
	 * @return JSONResponse
	 */
	private function saved(SubmittedConnection $submitted, array $warnings): JSONResponse {
		return new JSONResponse(
			[
				'mode' => $submitted->trust['mode'],
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
			intakeGroup: IntakeGroupsInterface::DSO_INTAKE
		);
	}//end accountCheck()

	/**
	 * The 400 answer for a trust chain or account that may not be saved, or null.
	 *
	 * @param SubmittedConnection $submitted The submitted trust configuration and account.
	 * @param list<string>        $warnings  Warnings to add to; passed by reference.
	 *
	 * @return JSONResponse|null The refusal.
	 */
	private function refusal(SubmittedConnection $submitted, array &$warnings): ?JSONResponse {
		$trust = $submitted->trust;
		$userId = $submitted->userId;
		if ($trust['mode'] === SignedPayloadVerifierInterface::MODE_PKIOVERHEID) {
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

		return $this->accountCheck()->refusal(fieldError: $this->accountError(userId: $userId, warnings: $warnings));

	}//end refusal()

	/**
	 * The consumer as it will be saved.
	 *
	 * @param ObjectEntity|null   $consumer  The existing consumer, or null for a new one.
	 * @param SubmittedConnection $submitted The submitted trust configuration and account.
	 *
	 * @return array<string, mixed> The consumer data.
	 */
	private function consumerData(?ObjectEntity $consumer, SubmittedConnection $submitted): array {
		$trust = $submitted->trust;
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

		$data['authorizationType'] = PortalConnectionInterface::AUTHORIZATION_TYPE;
		$data['authorizationConfiguration'] = $trust;
		$data['userId'] = $submitted->userId;

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
		$unresolvable = $this->accountState()->unresolvable(userId: $userId);
		if ($unresolvable !== null) {
			return $unresolvable;
		}

		$missing = $this->accountCheck()->missingRights(userId: $userId);

		if ($missing === null) {
			$warnings[] = $this->l->t('The rights of account %s could not be checked. Pushes are refused until they can be.', [$userId]);
		} elseif ($missing !== []) {
			return $this->l->t('Account %1$s lacks the %2$s right on DSO verzoeken.', [$userId, implode(', ', $missing)]);
		}

		$this->accountCheck()->warnAdministrator(userId: $userId, warnings: $warnings);

		return null;

	}//end accountError()

}//end class
