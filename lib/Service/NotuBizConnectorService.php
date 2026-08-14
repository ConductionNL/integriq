<?php

/**
 * OpenConnector NotuBiz Connector Service
 *
 * Service for bidirectional integration with the NotuBiz raadsinformatiesysteem.
 * Pushes vergaderstukken to NotuBiz and supports OAuth2 token management.
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
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

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
	 * Extract and normalise the source configuration array.
	 *
	 * @param ObjectEntity $source The source entity.
	 *
	 * @return array<string,mixed> Normalised configuration.
	 */
	private function extractConfig(ObjectEntity $source): array {
		$sourceData = $source->getObject();
		$config = ($sourceData['configuration'] ?? []);
		if (is_string($config) === true) {
			$config = json_decode($config, true) ?? [];
		}

		if (is_array($config) === false) {
			return [];
		}

		return $config;
	}//end extractConfig()

	/**
	 * Get the HTTP status code from a call log ObjectEntity.
	 *
	 * @param ObjectEntity|null $callLog The entity returned by CallService::call().
	 *
	 * @return int HTTP status code, or 0 if absent.
	 */
	private function getStatusCode(?ObjectEntity $callLog): int {
		if ($callLog === null) {
			return 0;
		}

		$data = $callLog->getObject();
		return (int)($data['statusCode'] ?? 0);
	}//end getStatusCode()

	/**
	 * Get the decoded response body from a call log ObjectEntity.
	 *
	 * @param ObjectEntity|null $callLog The entity returned by CallService::call().
	 *
	 * @return mixed The decoded response body, or null.
	 */
	private function getResponseBody(?ObjectEntity $callLog): mixed {
		if ($callLog === null) {
			return null;
		}

		$data = $callLog->getObject();
		$body = $data['response']['body'] ?? null;
		if (is_string($body) === true) {
			$decoded = json_decode($body, true);
			if ($decoded !== null) {
				return $decoded;
			}

			return $body;
		}

		return $body;
	}//end getResponseBody()

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
		$config = $this->extractConfig(source: $source);
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

			$statusCode = $this->getStatusCode(callLog: $callLog);
			if ($statusCode === 200) {
				$body = $this->getResponseBody(callLog: $callLog);
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
		$config = $this->extractConfig(source: $source);

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
		$config = $this->extractConfig(source: $source);
		$organisationId = $config['organisatieId'] ?? null;
		if ($organisationId === null) {
			return [
				'success' => false,
				'message' => 'Organisation ID not configured',
				'vergaderstukId' => null,
			];
		}

		$vergadertype = $this->mapVergadertype(
			caseType: $vergaderstuk['vergadertype'] ?? $config['defaultVergaderType'] ?? 'college'
		);

		$payload = [
			'onderwerp' => $vergaderstuk['onderwerp'] ?? '',
			'vergadertype' => $vergadertype,
			'zaaktype' => $vergaderstuk['zaaktype'] ?? '',
			'vertrouwelijk' => (bool)($vergaderstuk['geheimhouding'] ?? false),
			'vergaderingId' => $meetingId,
		];

		$this->logger->info(
			'NotuBiz: Pushing vergaderstuk',
			['onderwerp' => $payload['onderwerp'], 'vergadertype' => $vergadertype]
		);

		try {
			$endpoint = '/api/v1/organisations/' . $organisationId . '/vergaderstukken';
			$callLog = $this->callService->call(
				source: $source,
				endpoint: $endpoint,
				method: 'POST',
				config: ['json' => $payload]
			);

			$statusCode = $this->getStatusCode(callLog: $callLog);
			if ($statusCode < 200 || $statusCode >= 300) {
				$message = match ($statusCode) {
					0 => 'API call failed — no response',
					401 => 'Authentication failed — token may need refresh',
					413 => 'Vergaderstuk too large',
					default => 'Upload failed with status ' . $statusCode,
				};

				$this->logger->error(
					'NotuBiz: vergaderstuk push failed',
					['status' => $statusCode, 'onderwerp' => $payload['onderwerp']]
				);

				return [
					'success' => false,
					'message' => $message,
					'vergaderstukId' => null,
				];
			}//end if

			$body = $this->getResponseBody(callLog: $callLog);
			$vergaderstukId = null;
			if (is_array($body) === true) {
				$vergaderstukId = $body['id'] ?? $body['stukId'] ?? null;
			}

			return [
				'success' => true,
				'message' => 'Vergaderstuk pushed to NotuBiz',
				'vergaderstukId' => $vergaderstukId,
			];
		} catch (\Exception $e) {
			$this->logger->error('NotuBiz: push exception', ['exception' => $e->getMessage()]);
			return [
				'success' => false,
				'message' => 'Push failed: ' . $e->getMessage(),
				'vergaderstukId' => null,
			];
		}//end try

	}//end pushVergaderstuk()

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
