<?php

/**
 * Integriq ZGW document delivery.
 *
 * Creates one new EnkelvoudigInformatieObject on a Documenten API for a
 * delivery (filinq `caseSystemDelivery`, a redacted `externalDocument`),
 * uploads the file in the `bestandsdelen` the API hands out and unlocks the
 * object, and relates it to a case on the Zaken API when a `zaakUrl` is set.
 * It never updates or replaces a document: the push that calls it creates
 * once and reuses the address it got back (REQ-CSD-002).
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\CaseSystem;

use Throwable;

/**
 * Delivers one document to a ZGW Documenten API, in parts when it asks for them.
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
class ZgwDocumentDelivery {

	/**
	 * The metadata a delivery may carry onto the EnkelvoudigInformatieObject.
	 */
	public const DOCUMENT_FIELDS = [
		'bronorganisatie',
		'creatiedatum',
		'titel',
		'auteur',
		'taal',
		'formaat',
		'bestandsnaam',
		'informatieobjecttype',
		'vertrouwelijkheidaanduiding',
		'beschrijving',
	];

	/**
	 * Constructor.
	 *
	 * @param CallServiceCaseSystemTransport $transport Sends each request on a named source.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	public function __construct(
		private readonly CallServiceCaseSystemTransport $transport,
	) {
	}//end __construct()

	/**
	 * Create the document, upload its parts, unlock it and relate it to the case.
	 *
	 * Settings: `documentenSource` (required), `zakenSource` and `zaakUrl`
	 * (both needed for the case relation), `inline` (true for a Documenten API
	 * 1.0, which has no parts: the content goes in the create as base64).
	 * Anything that fails after the create removes the new document again, so
	 * a retry starts clean instead of leaving a locked, empty object behind.
	 *
	 * @param array<string,mixed> $document The delivery's metadata (DOCUMENT_FIELDS).
	 * @param string              $content  The file's bytes.
	 * @param array<string,mixed> $settings documentenSource, zakenSource, zaakUrl, inline.
	 *
	 * @return array{url:string,zaakinformatieobject:?string,document:array<string,mixed>} The document, its case relation, the create answer.
	 *
	 * @throws CaseSystemRefusal When an API refuses a step; its status and detail.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	public function deliver(array $document, string $content, array $settings): array {
		$run = new DocumentDeliveryRun(document: $document, settings: $settings);
		$run->content = $content;

		$created = $this->createDocument(run: $run);
		$relation = $this->completeDocument(run: $run, created: $created);

		return ['url' => (string)$created['url'], 'zaakinformatieobject' => $relation, 'document' => $created];
	}//end deliver()

	/**
	 * Create the document in the Documenten API.
	 *
	 * @param DocumentDeliveryRun $run The delivery.
	 *
	 * @return array<string,mixed> The create answer, which carries the new document's `url`.
	 *
	 * @throws CaseSystemRefusal When no Documenten source is named, the API refuses, or the answer has no address.
	 */
	private function createDocument(DocumentDeliveryRun $run): array {
		$documenten = $run->setting(name: 'documentenSource');
		if ($documenten === '') {
			throw new CaseSystemRefusal(status: 409, message: 'The delivery names no Documenten API source (documentenSource).');
		}

		$created = (array)self::accepted(answer: $this->sendCreate(run: $run, documenten: $documenten), api: 'Documenten API');
		if ((string)($created['url'] ?? '') === '') {
			throw new CaseSystemRefusal(status: 502, message: 'The Documenten API created the document without an address.');
		}

		return $created;
	}//end createDocument()

	/**
	 * POST the new document, with its metadata and size, and its content when inline.
	 *
	 * @param DocumentDeliveryRun $run The delivery.
	 * @param string $documenten The Documenten API source.
	 *
	 * @return array{status:int,data:mixed,raw:string} The answer.
	 */
	private function sendCreate(DocumentDeliveryRun $run, string $documenten): array {
		return $this->transport->send(
			sourceId: $documenten,
			method: 'POST',
			address: '/enkelvoudiginformatieobjecten',
			options: ['json' => $this->createPayload(document: $run->document, content: $run->content, inline: $run->inline())]
		);
	}//end sendCreate()

	/**
	 * Upload the parts and relate the document to its case; any refusal removes the new document again.
	 *
	 * @param DocumentDeliveryRun $run The delivery.
	 * @param array<string,mixed> $created The create answer.
	 *
	 * @return string|null The ZaakInformatieObject's address, null without a case.
	 *
	 * @throws CaseSystemRefusal When a part upload or the case relation is refused.
	 */
	private function completeDocument(DocumentDeliveryRun $run, array $created): ?string {
		$documenten = $run->setting(name: 'documentenSource');
		try {
			$this->uploadParts(
				created: $created,
				file: new DocumentFile(sourceId: $documenten, content: $run->content, filename: (string)($run->document['bestandsnaam'] ?? 'document'))
			);
			return $this->relateToCase(run: $run, url: (string)$created['url']);
		} catch (CaseSystemRefusal $refusal) {
			$this->removeOrphan(documenten: $documenten, url: (string)$created['url']);
			throw $refusal;
		}
	}//end completeDocument()

	/**
	 * The create body: the delivery's metadata, and either the content inline
	 * or only its size, which makes the API hand out parts and a lock.
	 *
	 * @param array<string,mixed> $document The delivery's metadata.
	 * @param string              $content  The file's bytes.
	 * @param bool                $inline   Whether to send the content in the create.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	public function createPayload(array $document, string $content, bool $inline): array {
		$payload = [];
		foreach (self::DOCUMENT_FIELDS as $field) {
			if (isset($document[$field]) === true && $document[$field] !== '') {
				$payload[$field] = $document[$field];
			}
		}

		if (isset($payload['titel']) === true) {
			$payload['titel'] = mb_substr((string)$payload['titel'], 0, 200);
		}

		$payload['bestandsomvang'] = strlen($content);
		$payload['inhoud']         = null;
		if ($inline === true) {
			$payload['inhoud'] = base64_encode($content);
		}

		return $payload;
	}//end createPayload()

	/**
	 * Upload the content in the parts the create answer named, in volgnummer
	 * order, then unlock the document. No parts means nothing to do.
	 *
	 * @param array<string,mixed> $created The create answer (url, lock, bestandsdelen).
	 * @param DocumentFile        $file    The file, its source and its name.
	 *
	 * @return void
	 *
	 * @throws CaseSystemRefusal When a part or the unlock is refused.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	private function uploadParts(array $created, DocumentFile $file): void {
		$parts = self::orderedParts(created: $created);
		if ($parts === []) {
			return;
		}

		$upload = new PartUpload(file: $file, lock: self::lockOf(created: $created));
		$offset = 0;
		foreach ($parts as $part) {
			$size = (int)($part['omvang'] ?? 0);
			$this->sendPart(upload: $upload, slice: ['address' => (string)($part['url'] ?? ''), 'contents' => substr($file->content, $offset, $size)]);
			$offset += $size;
		}

		if ($offset !== strlen($file->content)) {
			throw new CaseSystemRefusal(
				status: 502,
				message: 'The Documenten API parts add up to ' . $offset . ' bytes, the file has ' . strlen($file->content) . '.'
			);
		}

		$this->unlock(upload: $upload, documentUrl: (string)$created['url']);
	}//end uploadParts()

	/**
	 * The parts the create answer asks for, in volgnummer order.
	 *
	 * @param array<string,mixed> $created The create answer.
	 *
	 * @return array<int, array<string,mixed>> The parts.
	 */
	private static function orderedParts(array $created): array {
		$parts = array_values(array_filter((array)($created['bestandsdelen'] ?? []), 'is_array'));
		usort($parts, static fn (array $one, array $other): int => ((int)($one['volgnummer'] ?? 0)) <=> ((int)($other['volgnummer'] ?? 0)));
		return $parts;
	}//end orderedParts()

	/**
	 * The lock the create answer hands out for a part upload.
	 *
	 * @param array<string,mixed> $created The create answer.
	 *
	 * @return string The lock.
	 *
	 * @throws CaseSystemRefusal When the answer has parts but no lock.
	 */
	private static function lockOf(array $created): string {
		$lock = (string)($created['lock'] ?? '');
		if ($lock === '') {
			throw new CaseSystemRefusal(status: 502, message: 'The Documenten API asked for the file in parts but sent no lock.');
		}

		return $lock;
	}//end lockOf()

	/**
	 * PUT one part: its slice of the file and the lock.
	 *
	 * @param PartUpload                               $upload The file and its lock.
	 * @param array{address: string, contents: string} $slice  The part's address and bytes.
	 *
	 * @return void
	 *
	 * @throws CaseSystemRefusal When the API refuses the part.
	 */
	private function sendPart(PartUpload $upload, array $slice): void {
		self::accepted(
			answer: $this->transport->send(
				sourceId: $upload->file->sourceId,
				method: 'PUT',
				address: $slice['address'],
				options: [
					'multipart' => [
						['name' => 'inhoud', 'contents' => $slice['contents'], 'filename' => $upload->file->filename],
						['name' => 'lock', 'contents' => $upload->lock],
					],
				]
			),
			api: 'Documenten API'
		);
	}//end sendPart()

	/**
	 * Unlock the document once every part is in.
	 *
	 * @param PartUpload $upload      The file and its lock.
	 * @param string     $documentUrl The document's address.
	 *
	 * @return void
	 *
	 * @throws CaseSystemRefusal When the API refuses the unlock.
	 */
	private function unlock(PartUpload $upload, string $documentUrl): void {
		self::accepted(
			answer: $this->transport->send(
				sourceId: $upload->file->sourceId,
				method: 'POST',
				address: rtrim($documentUrl, '/') . '/unlock',
				options: ['json' => ['lock' => $upload->lock]]
			),
			api: 'Documenten API'
		);
	}//end unlock()

	/**
	 * Relate the document to its case, when the delivery names one.
	 *
	 * @param DocumentDeliveryRun $run The delivery: zakenSource and zaakUrl, and the title for the relation.
	 * @param string              $url The document's address.
	 *
	 * @return string|null The ZaakInformatieObject's address, null without a case.
	 *
	 * @throws CaseSystemRefusal When the Zaken API refuses, or a case is named without a Zaken source.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	private function relateToCase(DocumentDeliveryRun $run, string $url): ?string {
		$case = $run->setting(name: 'zaakUrl');
		if ($case === '') {
			return null;
		}

		$zaken = $run->setting(name: 'zakenSource');
		if ($zaken === '') {
			throw new CaseSystemRefusal(status: 409, message: 'The delivery names a case but no Zaken API source (zakenSource).');
		}

		$link = (array)self::accepted(answer: $this->sendRelation(run: $run, url: $url), api: 'Zaken API');

		return (string)($link['url'] ?? '');
	}//end relateToCase()

	/**
	 * POST the ZaakInformatieObject to the Zaken API source.
	 *
	 * @param DocumentDeliveryRun $run The delivery.
	 * @param string              $url The document's address.
	 *
	 * @return array{status:int,data:mixed,raw:string} The answer.
	 */
	private function sendRelation(DocumentDeliveryRun $run, string $url): array {
		return $this->transport->send(
			sourceId: $run->setting(name: 'zakenSource'),
			method: 'POST',
			address: '/zaakinformatieobjecten',
			options: ['json' => $this->relationBody(run: $run, url: $url)]
		);
	}//end sendRelation()

	/**
	 * The ZaakInformatieObject to create: the document, the case and, when the document has one, its title.
	 *
	 * @param DocumentDeliveryRun $run The delivery.
	 * @param string              $url The document's address.
	 *
	 * @return array<string,string> The relation body.
	 */
	private function relationBody(DocumentDeliveryRun $run, string $url): array {
		$body = ['informatieobject' => $url, 'zaak' => $run->setting(name: 'zaakUrl')];
		$titel = (string)($run->document['titel'] ?? '');
		if ($titel !== '') {
			$body['titel'] = mb_substr($titel, 0, 200);
		}

		return $body;
	}//end relationBody()

	/**
	 * Delete a document whose upload or case relation failed.
	 *
	 * @param string $documenten The Documenten source uuid.
	 * @param string $url        The document's address.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	private function removeOrphan(string $documenten, string $url): void {
		try {
			$this->transport->send(sourceId: $documenten, method: 'DELETE', address: $url);
		} catch (Throwable) {
			// The refusal that got us here is the answer; CallService logs the delete attempt.
			return;
		}
	}//end removeOrphan()

	/**
	 * The answer's data when the API accepted the request.
	 *
	 * @param array{status:int,data:mixed,raw:string} $answer The transport's answer.
	 * @param string                                  $api    The API's name, for the refusal.
	 *
	 * @return mixed The decoded body.
	 *
	 * @throws CaseSystemRefusal With the API's status and its ZGW detail.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	private static function accepted(array $answer, string $api): mixed {
		if ($answer['status'] < 300) {
			return $answer['data'];
		}

		$data   = $answer['data'];
		$detail = '';
		if (is_array($data) === true) {
			$detail = (string)($data['detail'] ?? ($data['title'] ?? ''));
			foreach ((array)($data['invalidParams'] ?? []) as $param) {
				if (is_array($param) === true && isset($param['reason']) === true) {
					$detail .= ' ' . ($param['name'] ?? '') . ': ' . $param['reason'];
				}
			}
		}

		$message = $api . ' answered ' . $answer['status'];
		if (trim($detail) !== '') {
			$message .= ': ' . trim($detail);
		}

		throw new CaseSystemRefusal(status: $answer['status'], message: $message);
	}//end accepted()
}//end class
