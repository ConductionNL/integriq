<?php

/**
 * DSO Samenwerking Service
 *
 * Handles inter-organisational cooperation via the DSO-SWF (Samenwerken en
 * Werk Faciliteren) koppelvlak: sending and receiving adviesverzoeken.
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

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
	 * @param array $case The zaak for which advice is requested.
	 * @param string $partnerOin OIN of the partner organisation to consult.
	 * @param string $term ISO 8601 deadline for the partner's response.
	 * @param string $dsoSwfUrl Base URL of the DSO-SWF endpoint.
	 * @param string|null $certPath Optional client certificate path for mTLS.
	 *
	 * @return array Array with 'success', 'adviesverzoekId', and 'message' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 *
	 * @orphaned-write-capability exclude Deferred, NOT dismissed — tracked in
	 * ConductionNL/integriq#1536. Zero production callers, verified on both
	 * `origin/development` and the app-id rename branch, so the finding is
	 * pre-existing rather than rename damage. This is a fully-implemented,
	 * spec'd OUTBOUND integration (builds an adviesverzoek and POSTs it to a
	 * partner OIN endpoint over DSO-SWF) that nothing reaches, so if the flow is
	 * meant to be live today the request is silently never sent. Wiring or
	 * removing it is unrelated behaviour change; #1536 carries what to check.
	 */
	public function sendAdviesverzoek(
		array $case,
		string $partnerOin,
		string $term,
		string $dsoSwfUrl,
		?string $certPath = null,
	): array {
		$payload = $this->buildAdviesverzoekPayload(case: $case, partnerOin: $partnerOin, term: $term);
		$run = new AdviesverzoekRun(uri: $dsoSwfUrl . '/adviesverzoeken', payload: $payload);

		return $this->deliver(run: $run, post: new MtlsJsonPost(clientService: $this->clientService, certPath: $certPath));

	}//end sendAdviesverzoek()

	/**
	 * POST the adviesverzoek and turn the answer, or the exception, into the result.
	 *
	 * @param AdviesverzoekRun $run  The adviesverzoek.
	 * @param MtlsJsonPost     $post The POST over the configured certificate.
	 *
	 * @return array Array with 'success', 'adviesverzoekId', and 'message' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	private function deliver(AdviesverzoekRun $run, MtlsJsonPost $post): array {
		try {
			$response = $post->post(uri: $run->uri, payload: $run->payload);
			return $this->answered(run: $run, statusCode: $response->getStatusCode());
		} catch (\Exception $e) {
			return $this->failed(run: $run, exception: $e);
		}

	}//end deliver()

	/**
	 * Log and answer an adviesverzoek that could not be sent.
	 *
	 * @param AdviesverzoekRun $run       The adviesverzoek.
	 * @param \Exception       $exception Why it could not be sent.
	 *
	 * @return array Array with 'success', 'adviesverzoekId', and 'message' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	private function failed(AdviesverzoekRun $run, \Exception $exception): array {
		$this->logger->error(
			'DSO-SWF: failed to send adviesverzoek',
			[
				'adviesverzoekId' => $run->adviesverzoekId,
				'partnerOin' => $run->partnerOin(),
				'error' => $exception->getMessage(),
			]
		);

		return [
			'success' => false,
			'adviesverzoekId' => $run->adviesverzoekId,
			'message' => 'Send failed: ' . $exception->getMessage(),
		];

	}//end failed()

	/**
	 * The result for the HTTP status DSO-SWF answered with.
	 *
	 * @param AdviesverzoekRun $run        The adviesverzoek.
	 * @param int              $statusCode The HTTP status.
	 *
	 * @return array Array with 'success', 'adviesverzoekId', and 'message' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	private function answered(AdviesverzoekRun $run, int $statusCode): array {
		if ($statusCode >= 200 && $statusCode < 300) {
			return $this->accepted(run: $run);
		}

		$this->logger->warning(
			'DSO-SWF: adviesverzoek post returned non-2xx',
			[
				'adviesverzoekId' => $run->adviesverzoekId,
				'statusCode' => $statusCode,
			]
		);

		return [
			'success' => false,
			'adviesverzoekId' => $run->adviesverzoekId,
			'message' => 'DSO-SWF responded with HTTP ' . $statusCode,
		];

	}//end answered()

	/**
	 * Log and answer an adviesverzoek DSO-SWF accepted.
	 *
	 * @param AdviesverzoekRun $run The adviesverzoek.
	 *
	 * @return array Array with 'success', 'adviesverzoekId', and 'message' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	private function accepted(AdviesverzoekRun $run): array {
		$this->logger->info(
			'DSO-SWF: adviesverzoek sent successfully',
			[
				'adviesverzoekId' => $run->adviesverzoekId,
				'partnerOin' => $run->partnerOin(),
				'caseId' => $run->caseId(),
			]
		);

		return [
			'success' => true,
			'adviesverzoekId' => $run->adviesverzoekId,
			'message' => 'Adviesverzoek succesvol verzonden naar ' . $run->partnerOin(),
		];

	}//end accepted()

	/**
	 * Receive and store an advies from a partner organisation.
	 *
	 * Validates that the advies payload contains the required fields
	 * (adviesId, organisatieOin, advies) and stores it linked to the given zaakId.
	 *
	 * @param array $adviesPayload The incoming advies payload from the partner.
	 * @param string $caseId The zaak identifier to link this advies to.
	 *
	 * @return array Array with 'stored', 'adviesId', and 'caseId' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	public function receiveAdvies(array $adviesPayload, string $caseId): array {
		$field = $this->missingAdviesField(adviesPayload: $adviesPayload);
		if ($field !== null) {
			return $this->refuseAdvies(caseId: $caseId, field: $field);
		}

		return $this->storeAdvies(adviesPayload: $adviesPayload, caseId: $caseId);

	}//end receiveAdvies()

	/**
	 * The first required advies field that is absent or empty, or null.
	 *
	 * @param array $adviesPayload The incoming advies payload.
	 *
	 * @return string|null The field.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	private function missingAdviesField(array $adviesPayload): ?string {
		foreach (self::REQUIRED_ADVIES_FIELDS as $field) {
			if (isset($adviesPayload[$field]) === false || $adviesPayload[$field] === '') {
				return $field;
			}
		}

		return null;

	}//end missingAdviesField()

	/**
	 * Log and answer an advies that lacks a required field.
	 *
	 * @param string $caseId The zaak identifier.
	 * @param string $field  The missing field.
	 *
	 * @return array Array with 'stored', 'adviesId', 'caseId' and 'error' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	private function refuseAdvies(string $caseId, string $field): array {
		$this->logger->warning(
			'DSO-SWF: received advies missing required field',
			[
				'caseId' => $caseId,
				'field' => $field,
			]
		);

		return [
			'stored' => false,
			'adviesId' => null,
			'caseId' => $caseId,
			'error' => 'required_field_missing: ' . $field,
		];

	}//end refuseAdvies()

	/**
	 * Log and answer a complete advies, linked to its zaak.
	 *
	 * @param array  $adviesPayload The advies payload.
	 * @param string $caseId        The zaak identifier.
	 *
	 * @return array Array with 'stored', 'adviesId', and 'caseId' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	private function storeAdvies(array $adviesPayload, string $caseId): array {
		$adviesId = $adviesPayload['adviesId'];

		$this->logger->info(
			'DSO-SWF: advies received and stored',
			[
				'adviesId' => $adviesId,
				'organisatieOin' => ($adviesPayload['organisatieOin'] ?? null),
				'caseId' => $caseId,
			]
		);

		return [
			'stored' => true,
			'adviesId' => $adviesId,
			'caseId' => $caseId,
		];

	}//end storeAdvies()

	/**
	 * Build the adviesverzoek payload for DSO-SWF.
	 *
	 * Assembles the structured payload from zaak data, partner OIN, and termijn.
	 *
	 * @param array $case The zaak for which advice is requested.
	 * @param string $partnerOin OIN of the partner organisation.
	 * @param string $term ISO 8601 deadline for the response.
	 *
	 * @return array Payload array with 'adviesverzoekId', 'caseId', 'partnerOin',
	 *               'termijn', and 'zaakDocumenten' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	public function buildAdviesverzoekPayload(array $case, string $partnerOin, string $term): array {
		$adviesverzoekId = uniqid(prefix: 'avz-', more_entropy: true);
		$caseDocumenten = ($case['documenten'] ?? []);

		return [
			'adviesverzoekId' => $adviesverzoekId,
			'caseId' => ($case['id'] ?? null),
			'partnerOin' => $partnerOin,
			'termijn' => $term,
			'zaakDocumenten' => $caseDocumenten,
			'aangemaakt' => date('c'),
		];

	}//end buildAdviesverzoekPayload()
}//end class
