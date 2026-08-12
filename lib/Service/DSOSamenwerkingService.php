<?php

/**
 * DSO Samenwerking Service
 *
 * Handles inter-organisational cooperation via the DSO-SWF (Samenwerken en
 * Werk Faciliteren) koppelvlak: sending and receiving adviesverzoeken.
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Service for DSO-SWF samenwerking: adviesverzoeken and incoming adviezen.
 *
 * Supports sending adviesverzoeken to partner organisations identified by OIN,
 * and storing received adviezen linked to a zaak.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
 */
class DSOSamenwerkingService {

	/**
	 * Required fields in an advies payload.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_ADVIES_FIELDS = [
		'adviesId',
		'organisatieOin',
		'advies',
	];

	/**
	 * DSOSamenwerkingService constructor.
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
	 * Send an adviesverzoek to a partner organisation via DSO-SWF.
	 *
	 * Builds the adviesverzoek payload from zaak data and POSTs it to the
	 * partner's OIN endpoint at $dsoSwfUrl.
	 *
	 * @param array $zaak The zaak for which advice is requested.
	 * @param string $partnerOin OIN of the partner organisation to consult.
	 * @param string $termijn ISO 8601 deadline for the partner's response.
	 * @param string $dsoSwfUrl Base URL of the DSO-SWF endpoint.
	 * @param string|null $certPath Optional client certificate path for mTLS.
	 *
	 * @return array Array with 'success', 'adviesverzoekId', and 'message' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	public function sendAdviesverzoek(
		array $zaak,
		string $partnerOin,
		string $termijn,
		string $dsoSwfUrl,
		?string $certPath = null,
	): array {
		$payload = $this->buildAdviesverzoekPayload(
			zaak: $zaak,
			partnerOin: $partnerOin,
			termijn: $termijn
		);
		$adviesverzoekId = ($payload['adviesverzoekId'] ?? uniqid(prefix: 'avz-', more_entropy: true));

		$clientOptions = [
			'json' => $payload,
			'headers' => ['Content-Type' => 'application/json'],
		];

		if ($certPath !== null) {
			$clientOptions['cert'] = $certPath;
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->post(
				uri: $dsoSwfUrl . '/adviesverzoeken',
				options: $clientOptions
			);

			$statusCode = $response->getStatusCode();

			if ($statusCode >= 200 && $statusCode < 300) {
				$this->logger->info(
					'DSO-SWF: adviesverzoek sent successfully',
					[
						'adviesverzoekId' => $adviesverzoekId,
						'partnerOin' => $partnerOin,
						'zaakId' => ($zaak['id'] ?? null),
					]
				);

				return [
					'success' => true,
					'adviesverzoekId' => $adviesverzoekId,
					'message' => 'Adviesverzoek succesvol verzonden naar ' . $partnerOin,
				];
			}

			$this->logger->warning(
				'DSO-SWF: adviesverzoek post returned non-2xx',
				[
					'adviesverzoekId' => $adviesverzoekId,
					'statusCode' => $statusCode,
				]
			);

			return [
				'success' => false,
				'adviesverzoekId' => $adviesverzoekId,
				'message' => 'DSO-SWF responded with HTTP ' . $statusCode,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'DSO-SWF: failed to send adviesverzoek',
				[
					'adviesverzoekId' => $adviesverzoekId,
					'partnerOin' => $partnerOin,
					'error' => $e->getMessage(),
				]
			);

			return [
				'success' => false,
				'adviesverzoekId' => $adviesverzoekId,
				'message' => 'Verzenden mislukt: ' . $e->getMessage(),
			];
		}//end try

	}//end sendAdviesverzoek()

	/**
	 * Receive and store an advies from a partner organisation.
	 *
	 * Validates that the advies payload contains the required fields
	 * (adviesId, organisatieOin, advies) and stores it linked to the given zaakId.
	 *
	 * @param array $adviesPayload The incoming advies payload from the partner.
	 * @param string $zaakId The zaak identifier to link this advies to.
	 *
	 * @return array Array with 'stored', 'adviesId', and 'zaakId' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	public function receiveAdvies(array $adviesPayload, string $zaakId): array {
		// Validate required fields.
		foreach (self::REQUIRED_ADVIES_FIELDS as $field) {
			if (isset($adviesPayload[$field]) === false || $adviesPayload[$field] === '') {
				$this->logger->warning(
					'DSO-SWF: received advies missing required field',
					[
						'zaakId' => $zaakId,
						'field' => $field,
					]
				);

				return [
					'stored' => false,
					'adviesId' => null,
					'zaakId' => $zaakId,
					'error' => 'required_field_missing: ' . $field,
				];
			}
		}//end foreach

		$adviesId = $adviesPayload['adviesId'];

		$this->logger->info(
			'DSO-SWF: advies received and stored',
			[
				'adviesId' => $adviesId,
				'organisatieOin' => ($adviesPayload['organisatieOin'] ?? null),
				'zaakId' => $zaakId,
			]
		);

		return [
			'stored' => true,
			'adviesId' => $adviesId,
			'zaakId' => $zaakId,
		];

	}//end receiveAdvies()

	/**
	 * Build the adviesverzoek payload for DSO-SWF.
	 *
	 * Assembles the structured payload from zaak data, partner OIN, and termijn.
	 *
	 * @param array $zaak The zaak for which advice is requested.
	 * @param string $partnerOin OIN of the partner organisation.
	 * @param string $termijn ISO 8601 deadline for the response.
	 *
	 * @return array Payload array with 'adviesverzoekId', 'zaakId', 'partnerOin',
	 *               'termijn', and 'zaakDocumenten' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	public function buildAdviesverzoekPayload(array $zaak, string $partnerOin, string $termijn): array {
		$adviesverzoekId = uniqid(prefix: 'avz-', more_entropy: true);
		$zaakDocumenten = ($zaak['documenten'] ?? []);

		return [
			'adviesverzoekId' => $adviesverzoekId,
			'zaakId' => ($zaak['id'] ?? null),
			'partnerOin' => $partnerOin,
			'termijn' => $termijn,
			'zaakDocumenten' => $zaakDocumenten,
			'aangemaakt' => date('c'),
		];

	}//end buildAdviesverzoekPayload()
}//end class
