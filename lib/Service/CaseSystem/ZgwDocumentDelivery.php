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
		$documenten = (string)($settings['documentenSource'] ?? '');
		if ($documenten === '') {
			throw new CaseSystemRefusal(status: 409, message: 'The delivery names no Documenten API source (documentenSource).');
		}

		$created = (array)self::accepted(
			answer: $this->transport->send(
				sourceId: $documenten,
				method: 'POST',
				address: '/enkelvoudiginformatieobjecten',
				options: ['json' => $this->createPayload(document: $document, content: $content, inline: (($settings['inline'] ?? false) === true))]
			),
			api: 'Documenten API'
		);
		$url = (string)($created['url'] ?? '');
		if ($url === '') {
			throw new CaseSystemRefusal(status: 502, message: 'The Documenten API created the document without an address.');
		}

		try {
			$this->uploadParts(created: $created, content: $content, filename: (string)($document['bestandsnaam'] ?? 'document'), documenten: $documenten);
			$relation = $this->relateToCase(url: $url, settings: $settings, titel: (string)($document['titel'] ?? ''));
		} catch (CaseSystemRefusal $refusal) {
			$this->removeOrphan(documenten: $documenten, url: $url);
			throw $refusal;
		}

		return ['url' => $url, 'zaakinformatieobject' => $relation, 'document' => $created];
	}//end deliver()

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
	 * @param array<string,mixed> $created    The create answer (bestandsdelen, lock, url).
	 * @param string              $content    The file's bytes.
	 * @param string              $filename   The file name for each part.
	 * @param string              $documenten The Documenten source uuid.
	 *
	 * @return void
	 *
	 * @throws CaseSystemRefusal When a part or the unlock is refused.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	private function uploadParts(array $created, string $content, string $filename, string $documenten): void {
		$parts = array_values(array_filter((array)($created['bestandsdelen'] ?? []), 'is_array'));
		if ($parts === []) {
			return;
		}

		$lock = (string)($created['lock'] ?? '');
		if ($lock === '') {
			throw new CaseSystemRefusal(status: 502, message: 'The Documenten API asked for the file in parts but sent no lock.');
		}

		usort($parts, static fn (array $one, array $other): int => ((int)($one['volgnummer'] ?? 0)) <=> ((int)($other['volgnummer'] ?? 0)));

		$offset = 0;
		foreach ($parts as $part) {
			$size = (int)($part['omvang'] ?? 0);
			self::accepted(
				answer: $this->transport->send(
					sourceId: $documenten,
					method: 'PUT',
					address: (string)($part['url'] ?? ''),
					options: [
						'multipart' => [
							['name' => 'inhoud', 'contents' => substr($content, $offset, $size), 'filename' => $filename],
							['name' => 'lock', 'contents' => $lock],
						],
					]
				),
				api: 'Documenten API'
			);
			$offset += $size;
		}

		if ($offset !== strlen($content)) {
			throw new CaseSystemRefusal(
				status: 502,
				message: 'The Documenten API parts add up to ' . $offset . ' bytes, the file has ' . strlen($content) . '.'
			);
		}

		self::accepted(
			answer: $this->transport->send(
				sourceId: $documenten,
				method: 'POST',
				address: rtrim((string)$created['url'], '/') . '/unlock',
				options: ['json' => ['lock' => $lock]]
			),
			api: 'Documenten API'
		);
	}//end uploadParts()

	/**
	 * Relate the document to its case, when the delivery names one.
	 *
	 * @param string              $url      The document's address.
	 * @param array<string,mixed> $settings zakenSource and zaakUrl.
	 * @param string              $titel    The document's title, for the relation.
	 *
	 * @return string|null The ZaakInformatieObject's address, null without a case.
	 *
	 * @throws CaseSystemRefusal When the Zaken API refuses, or a case is named without a Zaken source.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	private function relateToCase(string $url, array $settings, string $titel): ?string {
		$case = (string)($settings['zaakUrl'] ?? '');
		if ($case === '') {
			return null;
		}

		$zaken = (string)($settings['zakenSource'] ?? '');
		if ($zaken === '') {
			throw new CaseSystemRefusal(status: 409, message: 'The delivery names a case but no Zaken API source (zakenSource).');
		}

		$body = ['informatieobject' => $url, 'zaak' => $case];
		if ($titel !== '') {
			$body['titel'] = mb_substr($titel, 0, 200);
		}

		$link = (array)self::accepted(
			answer: $this->transport->send(sourceId: $zaken, method: 'POST', address: '/zaakinformatieobjecten', options: ['json' => $body]),
			api: 'Zaken API'
		);

		return (string)($link['url'] ?? '');
	}//end relateToCase()

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
