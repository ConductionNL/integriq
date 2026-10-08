<?php

/**
 * Integriq Digital Post Account Settings Controller.
 *
 * Admin endpoints for the account digital post is stored as: the `userId` of
 * the one consumer with `authorizationType` `digital-post`. The endpoints are
 * gated at the middleware layer via #[AuthorizedAdminSetting], and the
 * consumer is written as the administrator, with RBAC on, as the webhook
 * connection settings do.
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
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccount;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Intake\IntakeGroups;
use OCA\Integriq\Settings\IntegriqAdmin;
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
 * Reads and sets the digital post service account.
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */
class DigitalPostAccountSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request      The request.
	 * @param DigitalPostAccount $account      Finds and checks the account.
	 * @param DsoConnection      $consumers    Writes the consumer.
	 * @param IntakeGroups       $groups       Enrols the account in the digital post group.
	 * @param IGroupManager      $groupManager Says whether the account is an administrator.
	 * @param IL10N              $l            Field errors and warnings.
	 * @param LoggerInterface    $logger       Diagnostics.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function __construct(
		IRequest $request,
		private readonly DigitalPostAccount $account,
		private readonly DsoConnection $consumers,
		private readonly IntakeGroups $groups,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The account and its state.
	 *
	 * @return JSONResponse `{account: {...}}`.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getConfig(): JSONResponse {
		return new JSONResponse(['account' => $this->describe()]);

	}//end getConfig()

	/**
	 * Set the account digital post is stored as.
	 *
	 * Refuses, with a field error, an account that does not exist, is disabled
	 * or lacks the rights after joining the group. Warns when the account is an
	 * administrator. An empty `userId` clears the account, and digital post is
	 * refused until a new one is set.
	 *
	 * @return JSONResponse The saved state, or 400/409 with errors.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setConfig(): JSONResponse {
		$userId = trim((string)$this->request->getParam('userId', ''));
		try {
			$consumer = $this->account->findConsumer();
		} catch (DsoConnectionUnavailableException) {
			return new JSONResponse(
				['errors' => [$this->l->t('More than one digital post connection exists. Remove all but one on the Consumers page.')]],
				Http::STATUS_CONFLICT
			);
		}

		$warnings = [];
		if ($userId !== '') {
			$fieldError = $this->accountError(userId: $userId, warnings: $warnings);
			if ($fieldError !== null) {
				return new JSONResponse(['errors' => [$fieldError], 'fieldErrors' => ['userId' => $fieldError]], Http::STATUS_BAD_REQUEST);
			}
		}

		$data = ($consumer?->getObject() ?? [
			'name' => 'Digital post',
			'description' => 'The account every digital post letter is stored as.',
		]);
		$previous = (string)($data['userId'] ?? '');
		$data['authorizationType'] = DigitalPostAccount::AUTHORIZATION_TYPE;
		$data['userId'] = $userId;

		try {
			$this->consumers->saveConsumer(data: $data, uuid: $consumer?->getUuid());
		} catch (Throwable $exception) {
			$this->logger->error(
				'[DigitalPostAccountSettingsController] the digital post connection was not saved',
				['exception' => $exception->getMessage()]
			);
			return new JSONResponse(
				['errors' => [$this->l->t('The digital post connection was not saved: %s', [$exception->getMessage()])]],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($previous !== '' && $previous !== $userId) {
			$this->groups->withdraw(groupId: IntakeGroups::DIGITAL_POST_SENDERS, userId: $previous);
		}

		return new JSONResponse(['account' => $this->describe(), 'warnings' => $warnings]);

	}//end setConfig()

	/**
	 * The account's state, with the group it belongs to.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(): array {
		return $this->account->describe() + ['group' => IntakeGroups::DIGITAL_POST_SENDERS];

	}//end describe()

	/**
	 * Why the account cannot be the digital post account, or null when it can.
	 *
	 * The schema grants the group, so the account joins it before its rights
	 * are checked, and leaves again when it still lacks them and was not a
	 * member before.
	 *
	 * @param string       $userId   The chosen uid.
	 * @param list<string> $warnings Collects non-blocking warnings.
	 *
	 * @return string|null The field error, or null.
	 */
	private function accountError(string $userId, array &$warnings): ?string {
		try {
			$this->account->account(userId: $userId);
		} catch (DsoConnectionUnavailableException $exception) {
			if ($exception->getReason() === DsoConnectionUnavailableException::ACCOUNT_DISABLED) {
				return $this->l->t('Account %s is disabled.', [$userId]);
			}

			return $this->l->t('Account %s does not exist.', [$userId]);
		}

		$wasMember = $this->groups->isMember(groupId: IntakeGroups::DIGITAL_POST_SENDERS, userId: $userId);
		$this->groups->enrol(groupId: IntakeGroups::DIGITAL_POST_SENDERS, userId: $userId);
		$missing = $this->account->missingRights(userId: $userId);
		if ($missing !== null && $missing !== []) {
			if ($wasMember === false) {
				$this->groups->withdraw(groupId: IntakeGroups::DIGITAL_POST_SENDERS, userId: $userId);
			}

			return $this->l->t('Account %1$s lacks the %2$s right on %3$s.', [$userId, implode(', ', $missing), 'digitalPostMessage']);
		}

		if ($missing === null) {
			$warnings[] = $this->l->t('The rights of account %s could not be checked. Digital post is refused until they can be.', [$userId]);
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			$warnings[] = $this->l->t('Account %s is an administrator. A dedicated account keeps the audit trail readable.', [$userId]);
		}

		return null;

	}//end accountError()
}//end class
