<?php

/**
 * Integriq NotuBiz Connector Service
 *
 * Service for bidirectional integration with the NotuBiz raadsinformatiesysteem.
 * Pushes vergaderstukken to NotuBiz and supports OAuth2 token management.
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
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * Service for NotuBiz RIS integration.
 *
 * Handles vergaderstuk push with OAuth2 authentication, token refresh,
 * and multiple vergadertype support (college, raad, commissie).
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-6
 */
class NotuBizConnectorService {

	/**
	 * Supported vergadertype codes and their NotuBiz API names.
	 *
	 * @var array<string,string>
	 */
	private const VERGADERTYPE_MAP = [
		'college' => 'collegevergadering',
		'raad' => 'raadsvergadering',
		'commissie' => 'commissievergadering',
		// Aliases.
		'collegevergadering' => 'collegevergadering',
		'raadsvergadering' => 'raadsvergadering',
		'commissievergadering' => 'commissievergadering',
	];

	/**
	 * NotuBizConnectorService constructor.
	 *
	 * @param CallService $callService The call service for API requests.
	 * @param AuthenticationService $authenticationService Service for OAuth2 token management.
	 * @param LoggerInterface $logger Logger for error handling.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-6
	 */
	public function __construct(
		private readonly CallService $callService,
		private readonly AuthenticationService $authenticationService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Test the connection to a NotuBiz API source.
	 *
	 * Makes a lightweight API call to fetch organisatie details from NotuBiz.
	 *
	 * @param ObjectEntity $source The NotuBiz source configuration.
	 *
	 * @return array{success: bool, message: string} Result with success flag and message.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-6
	 */
	public function testConnection(ObjectEntity $source): array {
		$config = (new CallLogReader())->sourceConfig(source: $source);
		$organisationId = $config['organisatieId'] ?? null;
		if ($organisationId === null) {
			return [
				'success' => false,
				'message' => 'Organisation ID not configured',
			];
		}

		try {
			$endpoint = '/api/v1/organisations/' . $organisationId;
			$callLog = $this->callService->call(
				source: $source,
				endpoint: $endpoint,
				method: 'GET'
			);

			$statusCode = (new CallLogReader())->statusCode(callLog: $callLog);
			if ($statusCode === 200) {
				$body = (new CallLogReader())->responseBody(callLog: $callLog);
				$name = 'unknown';
				if (is_array($body) === true) {
					$name = $body['naam'] ?? $body['name'] ?? 'unknown';
				}

				return [
					'success' => true,
					'message' => 'Connection successful — organisation: ' . $name,
				];
			}

			if ($statusCode === 401) {
				return [
					'success' => false,
					'message' => 'Authentication failed (HTTP 401) — check OAuth2 credentials',
				];
			}

			return [
				'success' => false,
				'message' => 'API returned status ' . $statusCode,
			];
		} catch (\Exception $e) {
			$this->logger->warning('NotuBiz connection test failed', ['exception' => $e->getMessage()]);
			return [
				'success' => false,
				'message' => 'Connection failed: ' . $e->getMessage(),
			];
		}//end try

	}//end testConnection()

	/**
	 * Refresh the OAuth2 access token for a NotuBiz source.
	 *
	 * Delegates token refresh to the existing AuthenticationService
	 * client_credentials flow.
	 *
	 * @param ObjectEntity $source The NotuBiz source configuration.
	 *
	 * @return bool True if token was refreshed successfully.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-6
	 */
	public function refreshToken(ObjectEntity $source): bool {
		$config = (new CallLogReader())->sourceConfig(source: $source);

		$requiredKeys = ['clientId', 'clientSecret', 'tokenEndpoint'];
		foreach ($requiredKeys as $key) {
			if (empty($config[$key]) === true) {
				$this->logger->warning(
					'NotuBiz: OAuth2 refresh skipped — missing config key',
					['key' => $key]
				);
				return false;
			}
		}

		try {
			$tokenConfig = [
				'grant_type' => 'client_credentials',
				'clientId' => $config['clientId'],
				'clientSecret' => $config['clientSecret'],
				'tokenUrl' => $config['tokenEndpoint'],
			];

			$token = $this->authenticationService->fetchOAuthTokens(configuration: $tokenConfig);
			return empty($token) === false;
		} catch (\Exception $e) {
			$this->logger->error('NotuBiz: token refresh failed', ['exception' => $e->getMessage()]);
			return false;
		}//end try

	}//end refreshToken()

	/**
	 * Push vergaderstukken to NotuBiz for vergaderbehandeling.
	 *
	 * Uploads the voorstel and bijlagen with the correct vergadertype
	 * (collegevergadering, raadsvergadering, or commissievergadering).
	 *
	 * @param ObjectEntity $source The NotuBiz source configuration.
	 * @param array $vergaderstuk Stuk data with keys: onderwerp, vergadertype,
	 *                            zaaktype, bijlagen (array), geheimhouding (bool).
	 * @param string|null $meetingId Target vergadering ID, or null to use default.
	 *
	 * @return array{success: bool, message: string, vergaderstukId: string|null} Upload result.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-7
	 */
	public function pushVergaderstuk(
		ObjectEntity $source,
		array $vergaderstuk,
		?string $meetingId = null,
	): array {
		$config = (new CallLogReader())->sourceConfig(source: $source);
		if (($config['organisatieId'] ?? null) === null) {
			return $this->pushFailure(message: 'Organisation ID not configured');
		}

		$payload = $this->vergaderstukPayload(vergaderstuk: $vergaderstuk, config: $config) + ['vergaderingId' => $meetingId];

		return $this->upload(source: $source, payload: $payload);

	}//end pushVergaderstuk()

	/**
	 * Upload the vergaderstuk and turn NotuBiz's answer, or the exception, into the result.
	 *
	 * @param ObjectEntity        $source  The NotuBiz source configuration, with an `organisatieId`.
	 * @param array<string,mixed> $payload The vergaderstuk as NotuBiz receives it.
	 *
	 * @return array{success: bool, message: string, vergaderstukId: mixed} The upload result.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-7
	 */
	private function upload(ObjectEntity $source, array $payload): array {
		$this->logger->info(
			'NotuBiz: Pushing vergaderstuk',
			['onderwerp' => $payload['onderwerp'], 'vergadertype' => $payload['vergadertype']]
		);

		try {
			$callLog = $this->postVergaderstuk(source: $source, payload: $payload);
			return $this->pushResult(callLog: $callLog, onderwerp: $payload['onderwerp']);
		} catch (\Exception $e) {
			$this->logger->error('NotuBiz: push exception', ['exception' => $e->getMessage()]);
			return $this->pushFailure(message: 'Push failed: ' . $e->getMessage());
		}//end try

	}//end upload()

	/**
	 * POST the vergaderstuk to the organisation's vergaderstukken endpoint.
	 *
	 * @param ObjectEntity        $source  The NotuBiz source configuration, with an `organisatieId`.
	 * @param array<string,mixed> $payload The vergaderstuk as NotuBiz receives it.
	 *
	 * @return ObjectEntity The call log.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-7
	 */
	private function postVergaderstuk(ObjectEntity $source, array $payload): ObjectEntity {
		$organisationId = (new CallLogReader())->sourceConfig(source: $source)['organisatieId'];
		$endpoint = '/api/v1/organisations/' . $organisationId . '/vergaderstukken';

		return $this->callService->call(
			source: $source,
			endpoint: $endpoint,
			method: 'POST',
			config: ['json' => $payload]
		);

	}//end postVergaderstuk()

	/**
	 * The vergaderstuk as NotuBiz receives it, before the target meeting is added.
	 *
	 * @param array<string,mixed> $vergaderstuk The stuk data: onderwerp, vergadertype, zaaktype, geheimhouding.
	 * @param array<string,mixed> $config       The source configuration, for `defaultVergaderType`.
	 *
	 * @return array{onderwerp: mixed, vergadertype: string, zaaktype: mixed, vertrouwelijk: bool} The payload.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-7
	 */
	private function vergaderstukPayload(array $vergaderstuk, array $config): array {
		$vergadertype = $this->mapVergadertype(
			caseType: $vergaderstuk['vergadertype'] ?? $config['defaultVergaderType'] ?? 'college'
		);

		return [
			'onderwerp' => $vergaderstuk['onderwerp'] ?? '',
			'vergadertype' => $vergadertype,
			'zaaktype' => $vergaderstuk['zaaktype'] ?? '',
			'vertrouwelijk' => (bool)($vergaderstuk['geheimhouding'] ?? false),
		];

	}//end vergaderstukPayload()

	/**
	 * The push result for the call log NotuBiz answered with.
	 *
	 * @param ObjectEntity $callLog   The call log of the upload.
	 * @param mixed        $onderwerp The onderwerp pushed, for the failure log line.
	 *
	 * @return array{success: bool, message: string, vergaderstukId: mixed} The upload result.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-7
	 */
	private function pushResult(ObjectEntity $callLog, mixed $onderwerp): array {
		$statusCode = (new CallLogReader())->statusCode(callLog: $callLog);
		if ($statusCode < 200 || $statusCode >= 300) {
			$message = $this->pushFailureMessage(statusCode: $statusCode);

			$this->logger->error(
				'NotuBiz: vergaderstuk push failed',
				['status' => $statusCode, 'onderwerp' => $onderwerp]
			);

			return $this->pushFailure(message: $message);
		}

		$body = (new CallLogReader())->responseBody(callLog: $callLog);
		$vergaderstukId = null;
		if (is_array($body) === true) {
			$vergaderstukId = $body['id'] ?? $body['stukId'] ?? null;
		}

		return [
			'success' => true,
			'message' => 'Vergaderstuk pushed to NotuBiz',
			'vergaderstukId' => $vergaderstukId,
		];

	}//end pushResult()

	/**
	 * The message of a refused vergaderstuk upload.
	 *
	 * @param int $statusCode The HTTP status NotuBiz answered with (0 when there was no response).
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-7
	 */
	private function pushFailureMessage(int $statusCode): string {
		return match ($statusCode) {
			0 => 'API call failed — no response',
			401 => 'Authentication failed — token may need refresh',
			413 => 'Vergaderstuk too large',
			default => 'Upload failed with status ' . $statusCode,
		};

	}//end pushFailureMessage()

	/**
	 * A failed push: the message and no vergaderstuk id.
	 *
	 * @param string $message Why the push failed.
	 *
	 * @return array{success: false, message: string, vergaderstukId: null} The upload result.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-7
	 */
	private function pushFailure(string $message): array {
		return [
			'success' => false,
			'message' => $message,
			'vergaderstukId' => null,
		];

	}//end pushFailure()

	/**
	 * Map a zaaktype or vergadertype string to the NotuBiz API vergadertype code.
	 *
	 * @param string $caseType The zaaktype or vergadertype to map.
	 *
	 * @return string The NotuBiz vergadertype code.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-7
	 */
	public function mapVergadertype(string $caseType): string {
		$key = strtolower($caseType);
		return self::VERGADERTYPE_MAP[$key] ?? 'collegevergadering';
	}//end mapVergadertype()
}//end class
