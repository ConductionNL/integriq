<?php

/**
 * Integriq exchange gate client.
 *
 * Asks the app that owns an exchange job whether the job may run. Fails
 * closed on every path that is not an explicit allow.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Exchange;

use DateTime;
use OCA\Integriq\Event\ExchangeGateRequestedEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * The gate hook (design D2).
 *
 * Duck-typed on the owning app's id: the app is asked only when it is
 * enabled, and only through ExchangeGateRequestedEvent. Never over HTTP: a
 * scheduled run has no session, so a server-side call to the owning app's
 * `/api/exchange-gates/{jobId}` route would be refused and every job would
 * stay refused (ADR-041).
 *
 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
 */
class ExchangeGateClient {

	public const DECISION_ALLOW = 'allow';

	public const DECISION_REFUSE = 'refuse';

	public const CODE_OWNER_MISSING = 'gate-owner-missing';

	public const CODE_APP_ABSENT = 'gate-app-absent';

	public const CODE_UNANSWERED = 'gate-unanswered';

	public const CODE_ERROR = 'gate-error';

	public const CODE_REFUSED = 'gate-refused';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 * @param IAppManager      $appManager To check the owning app is enabled.
	 * @param LoggerInterface  $logger     Logger for gate failures.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Ask the owning app whether a job may run.
	 *
	 * @param string              $jobId The job's uuid.
	 * @param array<string,mixed> $job   The job's data.
	 *
	 * @return array{decision: string, code: string, reason: string, checkedAt: string, records: array<int, array<string, mixed>>}
	 *     The decision; `records` is what may leave and is never persisted.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function ask(string $jobId, array $job): array {
		$ownerApp = (string)($job['ownerApp'] ?? '');
		if ($ownerApp === '') {
			return $this->refusal(code: self::CODE_OWNER_MISSING, reason: 'The job names no owning app.');
		}

		if ($this->appManager->isEnabledForUser($ownerApp) === false) {
			return $this->refusal(
				code: self::CODE_APP_ABSENT,
				reason: sprintf('The owning app "%s" is not installed or not enabled.', $ownerApp)
			);
		}

		$scope = $job['exchangeScope'] ?? [];
		if (is_array($scope) === false) {
			$scope = [];
		}

		$event = new ExchangeGateRequestedEvent(
			jobId: $jobId,
			ownerApp: $ownerApp,
			target: (string)($job['exchangeTarget'] ?? ''),
			direction: (string)($job['exchangeDirection'] ?? ''),
			ownerRef: (string)($job['ownerRef'] ?? ''),
			scope: $scope
		);

		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				'[ExchangeGateClient] the gate of ' . $ownerApp . ' failed for job ' . $jobId . ': ' . $exception->getMessage()
			);
			return $this->refusal(
				code: self::CODE_ERROR,
				reason: sprintf('The gate of "%s" failed: %s', $ownerApp, $exception->getMessage())
			);
		}

		if ($event->isAnswered() === false) {
			return $this->refusal(
				code: self::CODE_UNANSWERED,
				reason: sprintf('The owning app "%s" did not answer the gate.', $ownerApp)
			);
		}

		if ($event->isAllowed() === false) {
			$refusal = ($event->getRefusal() ?? []);
			$code = (string)($refusal['code'] ?? '');
			if ($code === '') {
				$code = self::CODE_REFUSED;
			}

			return $this->refusal(code: $code, reason: (string)($refusal['reason'] ?? ''));
		}

		return [
			'decision' => self::DECISION_ALLOW,
			'code' => '',
			'reason' => '',
			'checkedAt' => (new DateTime())->format('c'),
			'records' => $event->getRecords(),
		];

	}//end ask()

	/**
	 * Build a refusal.
	 *
	 * @param string $code   The refusal code.
	 * @param string $reason The reason.
	 *
	 * @return array{decision: string, code: string, reason: string, checkedAt: string, records: array<int, array<string, mixed>>}
	 */
	private function refusal(string $code, string $reason): array {
		return [
			'decision' => self::DECISION_REFUSE,
			'code' => $code,
			'reason' => $reason,
			'checkedAt' => (new DateTime())->format('c'),
			'records' => [],
		];

	}//end refusal()
}//end class
