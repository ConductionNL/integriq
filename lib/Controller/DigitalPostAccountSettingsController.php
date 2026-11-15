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

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccountInterface;
use OCA\Integriq\Service\Portal\PortalConnectionInterface;
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
 * Reads and sets the digital post service account.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- an admin setting wires the account, groups, connection, l10n and log
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */
class DigitalPostAccountSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request      The request.
	 * @param DigitalPostAccountInterface $account      Finds and checks the account.
	 * @param PortalConnectionInterface      $consumers    Writes the consumer.
	 * @param IntakeGroupsInterface       $groups       Enrols the account in the digital post group.
	 * @param IGroupManager      $groupManager Says whether the account is an administrator.
	 * @param IL10N              $l            Field errors and warnings.
	 * @param LoggerInterface    $logger       Diagnostics.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function __construct(
		IRequest $request,
		private readonly DigitalPostAccountInterface $account,
		private readonly PortalConnectionInterface $consumers,
		private readonly IntakeGroupsInterface $groups,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
		$consumer = $this->currentConsumer();
		if ($consumer instanceof JSONResponse) {
			return $consumer;
		}

		$change = new DigitalPostAccountChange(userId: $userId, consumer: $consumer);
		$refused = $this->refusedAccount(change: $change);
		if ($refused !== null) {
			return $refused;
		}

		return $this->saveAccount(change: $change);

	}//end setConfig()

	/**
	 * The one digital post connection, if any.
	 *
	 * @return ObjectEntity|JSONResponse|null The connection, null when there is none yet,
	 *                                        or 409 when there is more than one.
	 */
	private function currentConsumer(): ObjectEntity|JSONResponse|null {
		try {
			return $this->account->findConsumer();
		} catch (DsoConnectionUnavailableException) {
			return new JSONResponse(
				['errors' => [$this->l->t('More than one digital post connection exists. Remove all but one on the Consumers page.')]],
				Http::STATUS_CONFLICT
			);
		}

	}//end currentConsumer()

	/**
	 * The answer for an account that cannot be the digital post account.
	 *
	 * @param DigitalPostAccountChange $change The request; collects its warnings.
	 *
	 * @return JSONResponse|null 400 with the field error, or null when the account may be saved
	 *                           (an empty uid, which clears the account, always may).
	 */
	private function refusedAccount(DigitalPostAccountChange $change): JSONResponse|null {
		if ($change->userId === '') {
			return null;
		}

		$fieldError = $this->accountError(change: $change);
		if ($fieldError === null) {
			return null;
		}

		return new JSONResponse(['errors' => [$fieldError], 'fieldErrors' => ['userId' => $fieldError]], Http::STATUS_BAD_REQUEST);

	}//end refusedAccount()

	/**
	 * Save the account on the connection, and take a replaced account out of the group.
	 *
	 * @param DigitalPostAccountChange $change The checked request.
	 *
	 * @return JSONResponse The saved state with the warnings, or 400 when the connection was not saved.
	 */
	private function saveAccount(DigitalPostAccountChange $change): JSONResponse {
		$data = $this->connectionData(change: $change);
		$previous = (string)($change->consumer?->getObject()['userId'] ?? '');
		try {
			$this->consumers->saveConsumer(data: $data, uuid: $change->consumer?->getUuid());
		} catch (Throwable $exception) {
			return $this->notSaved(exception: $exception);
		}

		if ($previous !== '' && $previous !== $change->userId) {
			$this->groups->withdraw(groupId: IntakeGroupsInterface::DIGITAL_POST_SENDERS, userId: $previous);
		}

		return new JSONResponse(['account' => $this->describe(), 'warnings' => $change->warnings]);

	}//end saveAccount()

	/**
	 * The connection as it is saved: its current data (or a new one's name and
	 * description), with the authorization type and the chosen uid.
	 *
	 * @param DigitalPostAccountChange $change The checked request.
	 *
	 * @return array<string, mixed> The connection data.
	 */
	private function connectionData(DigitalPostAccountChange $change): array {
		$data = ($change->consumer?->getObject() ?? [
			'name' => 'Digital post',
			'description' => 'The account every digital post letter is stored as.',
		]);
		$data['authorizationType'] = DigitalPostAccountInterface::AUTHORIZATION_TYPE;
		$data['userId'] = $change->userId;
		return $data;

	}//end connectionData()

	/**
	 * Log a connection that was not saved and answer it.
	 *
	 * @param Throwable $exception Why the save failed.
	 *
	 * @return JSONResponse The 400 error.
	 */
	private function notSaved(Throwable $exception): JSONResponse {
		$this->logger->error(
			'[DigitalPostAccountSettingsController] the digital post connection was not saved',
			['exception' => $exception->getMessage()]
		);
		return new JSONResponse(
			['errors' => [$this->l->t('The digital post connection was not saved: %s', [$exception->getMessage()])]],
			Http::STATUS_BAD_REQUEST
		);

	}//end notSaved()

	/**
	 * The account's state, with the group it belongs to.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(): array {
		return $this->account->describe() + ['group' => IntakeGroupsInterface::DIGITAL_POST_SENDERS];

	}//end describe()

	/**
	 * Why the account cannot be the digital post account, or null when it can.
	 *
	 * @param DigitalPostAccountChange $change The request; collects its warnings.
	 *
	 * @return string|null The field error, or null.
	 */
	private function accountError(DigitalPostAccountChange $change): ?string {
		try {
			$this->account->account(userId: $change->userId);
		} catch (DsoConnectionUnavailableException $exception) {
			return $this->unusableAccount(exception: $exception, userId: $change->userId);
		}

		$missing = $this->missingRightsInGroup(userId: $change->userId);
		if ($missing !== null && $missing !== []) {
			return $this->l->t('Account %1$s lacks the %2$s right on %3$s.', [$change->userId, implode(', ', $missing), 'digitalPostMessage']);
		}

		$this->warn(change: $change, rightsUnchecked: $missing === null);
		return null;

	}//end accountError()

	/**
	 * The field error for an account that does not exist or is disabled.
	 *
	 * @param DsoConnectionUnavailableException $exception Why the account cannot be used.
	 * @param string                            $userId    The chosen uid.
	 *
	 * @return string The field error.
	 */
	private function unusableAccount(DsoConnectionUnavailableException $exception, string $userId): string {
		if ($exception->getReason() === DsoConnectionUnavailableException::ACCOUNT_DISABLED) {
			return $this->l->t('Account %s is disabled.', [$userId]);
		}

		return $this->l->t('Account %s does not exist.', [$userId]);

	}//end unusableAccount()

	/**
	 * The rights the account lacks once it is in the digital post group.
	 *
	 * The schema grants the group, so the account joins it before its rights
	 * are checked, and leaves again when it still lacks them and was not a
	 * member before.
	 *
	 * @param string $userId The chosen uid.
	 *
	 * @return list<string>|null The missing rights, or null when they could not be checked.
	 */
	private function missingRightsInGroup(string $userId): ?array {
		$wasMember = $this->groups->isMember(groupId: IntakeGroupsInterface::DIGITAL_POST_SENDERS, userId: $userId);
		$this->groups->enrol(groupId: IntakeGroupsInterface::DIGITAL_POST_SENDERS, userId: $userId);
		$missing = $this->account->missingRights(userId: $userId);
		if ($missing !== null && $missing !== [] && $wasMember === false) {
			$this->groups->withdraw(groupId: IntakeGroupsInterface::DIGITAL_POST_SENDERS, userId: $userId);
		}

		return $missing;

	}//end missingRightsInGroup()

	/**
	 * Add the non-blocking warnings for a usable account.
	 *
	 * @param DigitalPostAccountChange $change          The request; collects its warnings.
	 * @param bool                     $rightsUnchecked Whether the account's rights could not be checked.
	 *
	 * @return void
	 */
	private function warn(DigitalPostAccountChange $change, bool $rightsUnchecked): void {
		if ($rightsUnchecked === true) {
			$change->warnings[] = $this->l->t('The rights of account %s could not be checked. Digital post is refused until they can be.', [$change->userId]);
		}

		if ($this->groupManager->isAdmin($change->userId) === true) {
			$change->warnings[] = $this->l->t('Account %s is an administrator. A dedicated account keeps the audit trail readable.', [$change->userId]);
		}

	}//end warn()
}//end class
