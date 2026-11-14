<?php

/**
 * DSO Status Service
 *
 * Handles pushing zaak status updates back to the DSO-LV API,
 * including mapping, payload construction, and retry logic.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Service for pushing zaak status updates to the DSO-LV API.
 *
 * Maps internal zaak statuses to DSO status codes and pushes them via
 * authenticated HTTP POST with exponential-backoff retry.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
 */
class DSOStatusService {

	/**
	 * Maximum number of push retry attempts.
	 *
	 * @var int
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Base sleep duration in seconds for exponential backoff.
	 *
	 * @var int
	 */
	private const BACKOFF_BASE_SECONDS = 2;

	/**
	 * Mapping of internal zaak statuses to DSO status codes.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'ontvangen' => 'ontvangen',
		'in_behandeling' => 'in behandeling',
		'besluit_genomen' => 'besluit genomen',
		'afgerond' => 'afgerond',
		'buiten_behandeling' => 'buiten behandeling',
	];

	/**
	 * DSOStatusService constructor.
	 *
	 * @param LoggerInterface $logger PSR logger.
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IClientService $clientService,
	) {

	}//end __construct()

	/**
	 * Push a zaak status update to the DSO-LV API.
	 *
	 * Maps the internal zaak status, builds the payload, and posts it to
	 * $dsoApiUrl. Retries up to MAX_RETRIES times with exponential backoff
	 * (2 s, 4 s, 8 s). Returns true on success, false on persistent failure.
	 *
	 * @param string $requestId The DSO verzoek identifier.
	 * @param string $caseStatus The internal zaak status to push.
	 * @param string $dsoApiUrl Base URL of the DSO-LV status endpoint.
	 * @param string|null $certPath Optional client certificate path for mTLS.
	 *
	 * @return bool True on successful push, false if all retries fail.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	public function pushStatusToDSO(
		string $requestId,
		string $caseStatus,
		string $dsoApiUrl,
		?string $certPath = null,
	): bool {
		$dsoStatus = $this->mapCaseStatusToDsoStatus(caseStatus: $caseStatus);
		$payload = $this->buildStatusPayload(requestId: $requestId, dsoStatus: $dsoStatus);
		$post = new MtlsJsonPost(clientService: $this->clientService, certPath: $certPath);

		return $this->pushWithRetries(run: new StatusPushRun(uri: $dsoApiUrl . '/statussen', payload: $payload), post: $post);
	}//end pushStatusToDSO()

	/**
	 * Attempt the push up to MAX_RETRIES times, backing off between attempts.
	 *
	 * @param StatusPushRun $run  The push.
	 * @param MtlsJsonPost  $post The POST over the configured certificate.
	 *
	 * @return bool True on successful push, false if all retries fail.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	private function pushWithRetries(StatusPushRun $run, MtlsJsonPost $post): bool {
		while ($run->attempt < self::MAX_RETRIES) {
			$run->attempt++;

			if ($this->attempt(run: $run, post: $post) === true) {
				return true;
			}

			if ($run->attempt < self::MAX_RETRIES) {
				$sleepSeconds = (self::BACKOFF_BASE_SECONDS ** $run->attempt);
				sleep(seconds: $sleepSeconds);
			}
		}

		return $this->giveUp(run: $run);
	}//end pushWithRetries()

	/**
	 * Log a push that failed every attempt.
	 *
	 * @param StatusPushRun $run The push.
	 *
	 * @return bool Always false.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	private function giveUp(StatusPushRun $run): bool {
		$this->logger->error(
			'DSO: status push failed after all retries',
			[
				'verzoekId' => $run->requestId(),
				'dsoStatus' => $run->dsoStatus(),
			]
		);

		return false;
	}//end giveUp()

	/**
	 * One attempt: POST the payload and log how it went.
	 *
	 * @param StatusPushRun $run  The push, with the attempt number.
	 * @param MtlsJsonPost  $post The POST over the configured certificate.
	 *
	 * @return bool True when DSO-LV accepted the status.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	private function attempt(StatusPushRun $run, MtlsJsonPost $post): bool {
		try {
			$response = $post->post(uri: $run->uri, payload: $run->payload);
			return $this->answered(run: $run, statusCode: $response->getStatusCode());
		} catch (\Exception $e) {
			$this->logger->warning(
				'DSO: status push attempt failed',
				[
					'verzoekId' => $run->requestId(),
					'attempt' => $run->attempt,
					'error' => $e->getMessage(),
				]
			);
		}

		return false;
	}//end attempt()

	/**
	 * Log the answer DSO-LV gave to one attempt.
	 *
	 * @param StatusPushRun $run        The push, with the attempt number.
	 * @param int           $statusCode The HTTP status DSO-LV answered with.
	 *
	 * @return bool True for a 2xx answer.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	private function answered(StatusPushRun $run, int $statusCode): bool {
		if ($statusCode >= 200 && $statusCode < 300) {
			return $this->pushed(run: $run);
		}

		$this->logger->warning(
			'DSO: status push returned non-2xx',
			[
				'verzoekId' => $run->requestId(),
				'statusCode' => $statusCode,
				'attempt' => $run->attempt,
			]
		);

		return false;
	}//end answered()

	/**
	 * Log an accepted push.
	 *
	 * @param StatusPushRun $run The push, with the attempt number.
	 *
	 * @return bool Always true.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	private function pushed(StatusPushRun $run): bool {
		$this->logger->info(
			'DSO: status pushed successfully',
			[
				'verzoekId' => $run->requestId(),
				'dsoStatus' => $run->dsoStatus(),
				'attempt' => $run->attempt,
			]
		);

		return true;
	}//end pushed()

	/**
	 * Map an internal zaak status to its DSO-LV equivalent.
	 *
	 * Uses the STATUS_MAP constant. Returns 'onbekend' for any status that
	 * has no mapping entry.
	 *
	 * @param string $caseStatus The internal zaak status string.
	 *
	 * @return string The corresponding DSO status string, or 'onbekend'.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	public function mapCaseStatusToDsoStatus(string $caseStatus): string {
		if (isset(self::STATUS_MAP[$caseStatus]) === false) {
			return 'onbekend';
		}

		return self::STATUS_MAP[$caseStatus];
	}//end mapCaseStatusToDsoStatus()

	/**
	 * Build the DSO-LV status update payload.
	 *
	 * @param string $requestId The DSO verzoek identifier.
	 * @param string $dsoStatus The mapped DSO status string.
	 *
	 * @return array Payload array with 'verzoekId', 'status', and 'timestamp' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	public function buildStatusPayload(string $requestId, string $dsoStatus): array {
		return [
			'verzoekId' => $requestId,
			'status' => $dsoStatus,
			'timestamp' => date('c'),
		];

	}//end buildStatusPayload()
}//end class
