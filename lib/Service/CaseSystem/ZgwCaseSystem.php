<?php

/**
 * The five case-system operations mapped onto the ZGW Zaken and Documenten APIs.
 *
 * @category Service
 * @package  OCA\Integriq\Service\CaseSystem
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\CaseSystem;

use DateTime;
use Throwable;

/**
 * The ZGW mapping (design D3).
 *
 * Requests follow the Zaken API 1.5 and Documenten API 1.4 request schemas;
 * the tests validate every recorded request against them. A ZGW error answers
 * its own status with its `detail`, never its body.
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
 */
class ZgwCaseSystem {

	/**
	 * The coordinate system headers the Zaken API requires on every /zaken request.
	 */
	private const CRS = ['Accept-Crs' => 'EPSG:4326', 'Content-Crs' => 'EPSG:4326'];

	/**
	 * Constructor.
	 *
	 * @param CallServiceCaseSystemTransport $transport Sends each request on a named source.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
	 */
	public function __construct(
		private readonly CallServiceCaseSystemTransport $transport,
	) {
	}//end __construct()

	/**
	 * Answer one operation.
	 *
	 * @param string              $operation     One of CaseSystemOperations::OPERATIONS.
	 * @param array<string,mixed> $body          The caller's body.
	 * @param array<string,mixed> $configuration The case-system source's configuration.
	 * @param string              $sourceName    The case-system source's name (the default author).
	 *
	 * @return array<string,mixed> The answer.
	 *
	 * @throws CaseSystemRefusal When the operation is refused.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
	 */
	public function run(string $operation, array $body, array $configuration, string $sourceName): array {
		return match ($operation) {
			'read-case' => $this->readCase(body: $body, configuration: $configuration),
			'list-documents' => $this->listDocuments(body: $body, configuration: $configuration),
			'read-document' => $this->readDocument(body: $body, configuration: $configuration),
			'add-document' => $this->addDocument(run: new DocumentAdditionRun(body: $body, configuration: $configuration, sourceName: $sourceName)),
			'create-case' => $this->createCase(body: $body, configuration: $configuration),
			default => throw new CaseSystemRefusal(status: 404, message: 'The case system has no operation "' . $operation . '".'),
		};
	}//end run()

	/**
	 * Read a case by its number or its address.
	 *
	 * @param array<string,mixed> $body          The body: reference.
	 * @param array<string,mixed> $configuration The source configuration.
	 *
	 * @return array{url:string,identification:string,title:string} An empty url when not found.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	private function readCase(array $body, array $configuration): array {
		$reference = self::text(body: $body, key: 'reference', operation: 'read-case');
		$zaken = self::setting(configuration: $configuration, key: 'zakenSource');

		if (str_contains($reference, '://') === true) {
			$answer = $this->transport->send(sourceId: $zaken, method: 'GET', address: $reference, options: ['headers' => self::CRS]);
			if ($answer['status'] === 404) {
				return ['url' => '', 'identification' => '', 'title' => ''];
			}

			return self::caseOf(zaak: (array)self::accepted(answer: $answer, api: 'Zaken API'));
		}

		$answer = $this->transport->send(
			sourceId: $zaken,
			method: 'GET',
			address: '/zaken',
			options: ['query' => ['identificatie' => $reference], 'headers' => self::CRS]
		);
		$found = self::listOf(data: self::accepted(answer: $answer, api: 'Zaken API'));
		if ($found === []) {
			return ['url' => '', 'identification' => '', 'title' => ''];
		}

		return self::caseOf(zaak: (array)$found[0]);
	}//end readCase()

	/**
	 * List the documents linked to a case.
	 *
	 * @param array<string,mixed> $body          The body: case.
	 * @param array<string,mixed> $configuration The source configuration.
	 *
	 * @return array{documents:list<array{url:string,name:string}>}
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	private function listDocuments(array $body, array $configuration): array {
		$case = self::text(body: $body, key: 'case', operation: 'list-documents');
		$zaken = self::setting(configuration: $configuration, key: 'zakenSource');
		$documenten = self::setting(configuration: $configuration, key: 'documentenSource');

		$links = self::listOf(
			data: self::accepted(
				answer: $this->transport->send(sourceId: $zaken, method: 'GET', address: '/zaakinformatieobjecten', options: ['query' => ['zaak' => $case]]),
				api: 'Zaken API'
			)
		);

		$documents = [];
		foreach ($links as $link) {
			$url = (string)(((array)$link)['informatieobject'] ?? '');
			if ($url === '') {
				continue;
			}

			$document = (array)self::accepted(
				answer: $this->transport->send(sourceId: $documenten, method: 'GET', address: $url),
				api: 'Documenten API'
			);
			$documents[] = ['url' => $url, 'name' => (string)($document['titel'] ?? (((array)$link)['titel'] ?? ''))];
		}

		return ['documents' => $documents];
	}//end listDocuments()

	/**
	 * Read one document with its content.
	 *
	 * @param array<string,mixed> $body          The body: document.
	 * @param array<string,mixed> $configuration The source configuration.
	 *
	 * @return array{name:string,content:string} The content in base64.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	private function readDocument(array $body, array $configuration): array {
		$url = self::text(body: $body, key: 'document', operation: 'read-document');
		$documenten = self::setting(configuration: $configuration, key: 'documentenSource');

		$document = (array)self::accepted(answer: $this->transport->send(sourceId: $documenten, method: 'GET', address: $url), api: 'Documenten API');
		$download = (string)($document['inhoud'] ?? '');
		if ($download === '') {
			throw new CaseSystemRefusal(status: 502, message: 'The document ' . $url . ' has no content to download.');
		}

		$content = $this->transport->send(sourceId: $documenten, method: 'GET', address: $download);
		self::accepted(answer: $content, api: 'Documenten API');

		return ['name' => (string)($document['titel'] ?? ''), 'content' => base64_encode($content['raw'])];
	}//end readDocument()

	/**
	 * Add a document to a case: create the informatieobject, then link it.
	 *
	 * @param DocumentAdditionRun $run The body (case, name, kind, content, confidential, ground), the source configuration and the default author.
	 *
	 * @return array{url:string} The document's address.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
	 */
	private function addDocument(DocumentAdditionRun $run): array {
		$run->case = self::text(body: $run->body, key: 'case', operation: 'add-document');
		$run->name = self::text(body: $run->body, key: 'name', operation: 'add-document');
		$run->type = $this->documentType(run: $run);
		if (base64_decode((string)($run->body['content'] ?? ''), true) === false) {
			throw new CaseSystemRefusal(status: 422, message: 'add-document needs its content in base64.');
		}

		$run->zaken = self::setting(configuration: $run->configuration, key: 'zakenSource');
		$run->documenten = self::setting(configuration: $run->configuration, key: 'documentenSource');
		$url = $this->createDocument(run: $run);
		$this->linkToCase(run: $run, url: $url);

		return ['url' => $url];
	}//end addDocument()

	/**
	 * The informatieobjecttype the document's kind maps to in the kinds table.
	 *
	 * @param DocumentAdditionRun $run The document being added.
	 *
	 * @return string The informatieobjecttype url.
	 *
	 * @throws CaseSystemRefusal When the kind has no document type (422).
	 */
	private function documentType(DocumentAdditionRun $run): string {
		$kind = (string)($run->body['kind'] ?? '');
		$type = (string)(((array)($run->configuration['kinds'] ?? []))[$kind] ?? '');
		if ($type === '') {
			throw new CaseSystemRefusal(status: 422, message: 'The kind "' . $kind . '" has no document type in this case system\'s kinds table.');
		}

		return $type;

	}//end documentType()

	/**
	 * Create the informatieobject in the Documenten API.
	 *
	 * @param DocumentAdditionRun $run The document being added.
	 *
	 * @return string The document's address.
	 *
	 * @throws CaseSystemRefusal When the Documenten API refuses or answers no address.
	 */
	private function createDocument(DocumentAdditionRun $run): string {
		$created = (array)self::accepted(
			answer: $this->transport->send(
				sourceId: $run->documenten,
				method: 'POST',
				address: '/enkelvoudiginformatieobjecten',
				options: ['json' => $this->documentPayload(run: $run)]
			),
			api: 'Documenten API'
		);
		$url = (string)($created['url'] ?? '');
		if ($url === '') {
			throw new CaseSystemRefusal(status: 502, message: 'The Documenten API created the document without an address.');
		}

		return $url;

	}//end createDocument()

	/**
	 * Link the document to its case; a refused link removes the document so no orphan stays behind.
	 *
	 * @param DocumentAdditionRun $run The document being added.
	 * @param string              $url The document's address.
	 *
	 * @return void
	 *
	 * @throws CaseSystemRefusal When the Zaken API refuses the link.
	 */
	private function linkToCase(DocumentAdditionRun $run, string $url): void {
		$link = $this->transport->send(
			sourceId: $run->zaken,
			method: 'POST',
			address: '/zaakinformatieobjecten',
			options: ['json' => ['informatieobject' => $url, 'zaak' => $run->case, 'titel' => mb_substr($run->name, 0, 200)]]
		);
		if ($link['status'] >= 300) {
			$this->removeOrphan(documenten: $run->documenten, url: $url);
		}

		self::accepted(answer: $link, api: 'Zaken API');

	}//end linkToCase()

	/**
	 * The Documenten API request body for a new document.
	 *
	 * @param DocumentAdditionRun $run The document being added.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
	 */
	private function documentPayload(DocumentAdditionRun $run): array {
		$payload = [
			'bronorganisatie' => self::setting(configuration: $run->configuration, key: 'bronorganisatie'),
			'creatiedatum' => (new DateTime())->format('Y-m-d'),
			'titel' => mb_substr($run->name, 0, 200),
			'auteur' => mb_substr($this->documentAuthor(run: $run), 0, 200),
			// ISO 639-2/B, as the Documenten API asks: Dutch is "dut".
			'taal' => 'dut',
			'informatieobjecttype' => $run->type,
			'vertrouwelijkheidaanduiding' => $this->confidentialityLevel(run: $run),
			'bestandsnaam' => mb_substr($run->name, 0, 255),
			'inhoud' => (string)($run->body['content'] ?? ''),
		];

		$ground = (string)($run->body['ground'] ?? '');
		if ($this->isConfidential(run: $run) === true && $ground !== '') {
			$payload['beschrijving'] = mb_substr($ground, 0, 1000);
		}

		return $payload;
	}//end documentPayload()

	/**
	 * Whether the caller marked the document confidential (strictly `true`).
	 *
	 * @param DocumentAdditionRun $run The document being added.
	 *
	 * @return bool True when confidential.
	 */
	private function isConfidential(DocumentAdditionRun $run): bool {
		return ($run->body['confidential'] ?? false) === true;

	}//end isConfidential()

	/**
	 * The vertrouwelijkheidaanduiding: the configured confidential level, else the configured public one.
	 *
	 * @param DocumentAdditionRun $run The document being added.
	 *
	 * @return string The level.
	 */
	private function confidentialityLevel(DocumentAdditionRun $run): string {
		if ($this->isConfidential(run: $run) === true) {
			return (string)($run->configuration['confidentialAs'] ?? 'vertrouwelijk');
		}

		return (string)($run->configuration['publicAs'] ?? 'openbaar');

	}//end confidentialityLevel()

	/**
	 * The author: the configured `auteur`, else the source's name, else `Integriq`.
	 *
	 * @param DocumentAdditionRun $run The document being added.
	 *
	 * @return string The author.
	 */
	private function documentAuthor(DocumentAdditionRun $run): string {
		$author = (string)($run->configuration['auteur'] ?? $run->sourceName);
		if ($author === '') {
			return 'Integriq';
		}

		return $author;

	}//end documentAuthor()

	/**
	 * Delete a document whose link to its case failed, so no orphan stays behind.
	 *
	 * @param string $documenten The Documenten source uuid.
	 * @param string $url        The document's address.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
	 */
	private function removeOrphan(string $documenten, string $url): void {
		try {
			$this->transport->send(sourceId: $documenten, method: 'DELETE', address: $url);
		} catch (Throwable) {
			// The link's refusal is the answer; the delete attempt is logged by CallService.
			return;
		}
	}//end removeOrphan()

	/**
	 * Create a case for a meeting.
	 *
	 * @param array<string,mixed> $body          The body: kind, title, date.
	 * @param array<string,mixed> $configuration The source configuration.
	 *
	 * @return array{url:string,identification:string}
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	private function createCase(array $body, array $configuration): array {
		if (($body['kind'] ?? '') !== 'meeting') {
			throw new CaseSystemRefusal(status: 422, message: 'create-case only creates cases of kind "meeting".');
		}

		$date = (string)($body['date'] ?? '');
		$parsed = date_create_immutable_from_format('!Y-m-d', $date);
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw new CaseSystemRefusal(status: 422, message: 'create-case needs its date as year-month-day, for example 2026-11-12.');
		}

		$organisation = self::setting(configuration: $configuration, key: 'bronorganisatie');
		$payload = [
			'bronorganisatie' => $organisation,
			'verantwoordelijkeOrganisatie' => $organisation,
			'zaaktype' => self::setting(configuration: $configuration, key: 'meetingZaaktype'),
			'omschrijving' => mb_substr((string)($body['title'] ?? ''), 0, 80),
			'startdatum' => $date,
		];

		$created = (array)self::accepted(
			answer: $this->transport->send(
				sourceId: self::setting(configuration: $configuration, key: 'zakenSource'),
				method: 'POST',
				address: '/zaken',
				options: ['json' => $payload, 'headers' => self::CRS]
			),
			api: 'Zaken API'
		);

		return ['url' => (string)($created['url'] ?? ''), 'identification' => (string)($created['identificatie'] ?? '')];
	}//end createCase()

	/**
	 * The answer's decoded body, or a refusal carrying its status and detail.
	 *
	 * @param array{status:int,data:mixed,raw:string} $answer The transport answer.
	 * @param string                                  $api    The API's name, for the message.
	 *
	 * @return mixed The decoded body.
	 *
	 * @throws CaseSystemRefusal When the API answered an error.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
	 */
	private static function accepted(array $answer, string $api): mixed {
		$status = $answer['status'];
		if ($status >= 200 && $status < 300) {
			return $answer['data'];
		}

		$data = $answer['data'];
		$detail = '';
		if (is_array($data) === true) {
			$detail = (string)($data['detail'] ?? ($data['title'] ?? ''));
		}

		$message = 'The ' . $api . ' answered ' . $status;
		if ($detail !== '') {
			$message = 'The ' . $api . ' refused: ' . $detail;
		}

		if ($status < 400) {
			$status = 502;
		}

		throw new CaseSystemRefusal(status: $status, message: $message);
	}//end accepted()

	/**
	 * The items of a ZGW list answer, paginated or plain.
	 *
	 * @param mixed $data The decoded body.
	 *
	 * @return list<mixed>
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	private static function listOf(mixed $data): array {
		if (is_array($data) === false) {
			return [];
		}

		if (array_key_exists('results', $data) === true) {
			return array_values((array)$data['results']);
		}

		return array_values($data);
	}//end listOf()

	/**
	 * The answer for a zaak.
	 *
	 * @param array<string,mixed> $zaak The zaak.
	 *
	 * @return array{url:string,identification:string,title:string}
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	private static function caseOf(array $zaak): array {
		return [
			'url' => (string)($zaak['url'] ?? ''),
			'identification' => (string)($zaak['identificatie'] ?? ''),
			'title' => (string)($zaak['omschrijving'] ?? ''),
		];
	}//end caseOf()

	/**
	 * A required, non-empty text field of the body.
	 *
	 * @param array<string,mixed> $body      The body.
	 * @param string              $key       The field.
	 * @param string              $operation The operation, for the message.
	 *
	 * @return string
	 *
	 * @throws CaseSystemRefusal When the field is missing.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	private static function text(array $body, string $key, string $operation): string {
		$value = trim((string)($body[$key] ?? ''));
		if ($value === '') {
			throw new CaseSystemRefusal(status: 422, message: $operation . ' needs "' . $key . '".');
		}

		return $value;
	}//end text()

	/**
	 * A required setting of the case-system source.
	 *
	 * @param array<string,mixed> $configuration The source configuration.
	 * @param string              $key           The setting.
	 *
	 * @return string
	 *
	 * @throws CaseSystemRefusal When the setting is empty (409: the administrator has to set it).
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	private static function setting(array $configuration, string $key): string {
		$value = trim((string)($configuration[$key] ?? ''));
		if ($value === '') {
			throw new CaseSystemRefusal(
				status: 409,
				message: 'The case system is not set up yet: an administrator has to fill in ' . $key . ' on its source.'
			);
		}

		return $value;
	}//end setting()
}//end class
