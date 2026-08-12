<?php

/**
 * DSO Status Service
 *
 * Handles pushing zaak status updates back to the DSO-LV API,
 * including mapping, payload construction, and retry logic.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

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
	 * @param string $verzoekId The DSO verzoek identifier.
	 * @param string $zaakStatus The internal zaak status to push.
	 * @param string $dsoApiUrl Base URL of the DSO-LV status endpoint.
	 * @param string|null $certPath Optional client certificate path for mTLS.
	 *
	 * @return bool True on successful push, false if all retries fail.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	public function pushStatusToDSO(
		string $verzoekId,
		string $zaakStatus,
		string $dsoApiUrl,
		?string $certPath = null,
	): bool {
		$dsoStatus = $this->mapZaakStatusToDSOStatus(zaakStatus: $zaakStatus);
		$payload = $this->buildStatusPayload(verzoekId: $verzoekId, dsoStatus: $dsoStatus);

		$attempt = 0;
		$clientOptions = [
			'json' => $payload,
			'headers' => ['Content-Type' => 'application/json'],
		];

		if ($certPath !== null) {
			$clientOptions['cert'] = $certPath;
		}

		while ($attempt < self::MAX_RETRIES) {
			$attempt++;

			try {
				$client = $this->clientService->newClient();
				$response = $client->post(
					uri: $dsoApiUrl . '/statussen',
					options: $clientOptions
				);

				$statusCode = $response->getStatusCode();

				if ($statusCode >= 200 && $statusCode < 300) {
					$this->logger->info(
						'DSO: status pushed successfully',
						[
							'verzoekId' => $verzoekId,
							'dsoStatus' => $dsoStatus,
							'attempt' => $attempt,
						]
					);

					return true;
				}

				$this->logger->warning(
					'DSO: status push returned non-2xx',
					[
						'verzoekId' => $verzoekId,
						'statusCode' => $statusCode,
						'attempt' => $attempt,
					]
				);
			} catch (\Exception $e) {
				$this->logger->warning(
					'DSO: status push attempt failed',
					[
						'verzoekId' => $verzoekId,
						'attempt' => $attempt,
						'error' => $e->getMessage(),
					]
				);
			}//end try

			if ($attempt < self::MAX_RETRIES) {
				$sleepSeconds = (self::BACKOFF_BASE_SECONDS ** $attempt);
				sleep(seconds: $sleepSeconds);
			}
		}//end while

		$this->logger->error(
			'DSO: status push failed after all retries',
			[
				'verzoekId' => $verzoekId,
				'dsoStatus' => $dsoStatus,
			]
		);

		return false;
	}//end pushStatusToDSO()

	/**
	 * Map an internal zaak status to its DSO-LV equivalent.
	 *
	 * Uses the STATUS_MAP constant. Returns 'onbekend' for any status that
	 * has no mapping entry.
	 *
	 * @param string $zaakStatus The internal zaak status string.
	 *
	 * @return string The corresponding DSO status string, or 'onbekend'.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	public function mapZaakStatusToDSOStatus(string $zaakStatus): string {
		if (isset(self::STATUS_MAP[$zaakStatus]) === false) {
			return 'onbekend';
		}

		return self::STATUS_MAP[$zaakStatus];
	}//end mapZaakStatusToDSOStatus()

	/**
	 * Build the DSO-LV status update payload.
	 *
	 * @param string $verzoekId The DSO verzoek identifier.
	 * @param string $dsoStatus The mapped DSO status string.
	 *
	 * @return array Payload array with 'verzoekId', 'status', and 'timestamp' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	public function buildStatusPayload(string $verzoekId, string $dsoStatus): array {
		return [
			'verzoekId' => $verzoekId,
			'status' => $dsoStatus,
			'timestamp' => date('c'),
		];

	}//end buildStatusPayload()
}//end class
