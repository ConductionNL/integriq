<?php

/**
 * Integriq — builds a Berichtenbox letter to the official schema.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Berichtenbox;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;

/**
 * One batch with one letter, as `GLOBEBatchRequest.xsd` and the aansluithandleiding 1.6.4 describe it.
 *
 * The limits come from two places. The XSD: subject 1 to 50 characters, text up
 * to 4000, reference up to 25, BerichtType 1 to 8, at most two attachments. The
 * aansluithandleiding section 5.3: attachments together at most 500 kB before
 * base64, PDF only, an attachment description of at most 40 characters (the XSD
 * allows 128; the stricter one is applied, Q3), a line break written as the four
 * characters `\r\n`, and a URL with a space before and after. Nothing is cut to
 * fit: a letter that breaks a rule is refused, naming the field and the limit.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-letter-is-built-to-the-official-schema-and-its-limits-req-dpa-010
 */
class BerichtenboxLetterBuilder implements LetterBuilderInterface {
	public const NS_BATCH = 'http://schemas.rdw.nl/GEB/BerichtVerwerkService/Types/2009/01';

	public const NS_LETTER = 'http://schemas.rdw.nl/GEB/BerichtenProsessor/Bericht/Types/2009/01';

	public const MAX_SUBJECT = 50;

	public const MAX_TEXT = 4000;

	public const MAX_REFERENCE = 25;

	public const MAX_ATTACHMENTS = 2;

	public const MAX_ATTACHMENT_BYTES = 512000;

	public const MAX_DESCRIPTION = 40;

	/**
	 * The vendored request schema.
	 *
	 * @return string The path.
	 */
	public static function schemaPath(): string {
		return __DIR__ . '/Logius/BerichtVerwerkService/Request/GLOBEBatchRequest.xsd';
	}//end schemaPath()

	/**
	 * Build and check one letter.
	 *
	 * @param array<string,mixed> $message The digital post message: recipient, subject, body, attachments, caseRef.
	 * @param string $senderOin The sender's OIN.
	 * @param string $berichtType The BerichtType code.
	 * @param DateTimeImmutable|null $now The creation time, for tests.
	 * @param string|null $batchId A BatchID to reuse, for tests and for a resend.
	 * @param string|null $berichtId A BerichtID to reuse.
	 *
	 * @return BerichtenboxBatch The batch.
	 *
	 * @throws BerichtenboxException When the letter breaks a rule.
	 */
	public function build(
		array $message,
		string $senderOin,
		string $berichtType,
		?DateTimeImmutable $now = null,
		?string $batchId = null,
		?string $berichtId = null,
	): BerichtenboxBatch {
		$fields = new LetterFields(senderOin: $senderOin, berichtType: $berichtType);
		$fields->batchId = ($batchId ?? $this->guid());
		$fields->berichtId = ($berichtId ?? $this->guid());
		$fields->created = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

		return $this->assemble(fields: $fields, message: $message);
	}//end build()

	/**
	 * Check the message, write the batch document and check it against the schema.
	 *
	 * @param LetterFields        $fields  The letter's identity and sender.
	 * @param array<string,mixed> $message The digital post message.
	 *
	 * @return BerichtenboxBatch The batch.
	 *
	 * @throws BerichtenboxException When the letter breaks a rule.
	 */
	private function assemble(LetterFields $fields, array $message): BerichtenboxBatch {
		$this->readMessage(fields: $fields, message: $message);

		$document = $this->batchDocument(fields: $fields, attachments: (array)($message['attachments'] ?? []));

		$xml = (string)$document->saveXML();
		$this->assertValid(xml: $xml);

		return new BerichtenboxBatch(xml: $xml, batchId: $fields->batchId, berichtId: $fields->berichtId);
	}//end assemble()

	/**
	 * Take the recipient, subject, text and reference from the message, refusing what breaks a rule.
	 *
	 * The BSN is checked first, then the subject, the text and the BerichtType.
	 *
	 * @param LetterFields        $fields  The fields to fill.
	 * @param array<string,mixed> $message The digital post message.
	 *
	 * @return void
	 *
	 * @throws BerichtenboxException When a field breaks a rule.
	 */
	private function readMessage(LetterFields $fields, array $message): void {
		$fields->bsn = trim((string)($message['recipient'] ?? ''));
		if (preg_match('/^\d{1,9}$/', $fields->bsn) !== 1) {
			$this->refuse(reason: 'GebruikerID must be a BSN of at most 9 digits.');
		}

		$fields->subject = (string)($message['subject'] ?? '');
		$this->assertLength(field: 'Onderwerp', value: $fields->subject, min: 1, max: self::MAX_SUBJECT);

		$fields->text = $this->text(body: (string)($message['body'] ?? ''));
		$this->assertLength(field: 'BerichtTekst', value: $fields->text, min: 0, max: self::MAX_TEXT);

		$this->assertLength(field: 'BerichtType', value: $fields->berichtType, min: 1, max: 8);

		$fields->reference = (string)($message['caseRef'] ?? '');
	}//end readMessage()

	/**
	 * Write the batch document: the batch information, then the one letter with its attachments.
	 *
	 * @param LetterFields     $fields      The checked fields.
	 * @param array<int,mixed> $attachments The message attachments.
	 *
	 * @return DOMDocument The document.
	 *
	 * @throws BerichtenboxException When an attachment breaks a rule.
	 */
	private function batchDocument(LetterFields $fields, array $attachments): DOMDocument {
		$document = new DOMDocument('1.0', 'UTF-8');
		$root = $document->createElementNS(self::NS_BATCH, 'r:Berichten');
		$document->appendChild($root);
		$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:b', self::NS_LETTER);

		$this->appendBatchInformation(root: $root, fields: $fields);
		$letter = $this->appendLetter(root: $root, fields: $fields);
		$this->attachments(letter: $letter, attachments: $attachments);

		return $document;
	}//end batchDocument()

	/**
	 * Append the BatchInformatie element.
	 *
	 * @param DOMElement   $root   The Berichten element.
	 * @param LetterFields $fields The checked fields.
	 *
	 * @return void
	 */
	private function appendBatchInformation(DOMElement $root, LetterFields $fields): void {
		$info = $this->child(parent: $root, namespace: self::NS_BATCH, name: 'r:BatchInformatie');
		$this->child(parent: $info, namespace: self::NS_BATCH, name: 'r:BatchID', text: $fields->batchId);
		$this->child(parent: $info, namespace: self::NS_BATCH, name: 'r:AanmaakDatum', text: $fields->created);
		$this->child(parent: $info, namespace: self::NS_BATCH, name: 'r:BerichtLeverancierID', text: $fields->senderOin);
	}//end appendBatchInformation()

	/**
	 * Append the Bericht element with its BerichtInformatie.
	 *
	 * @param DOMElement   $root   The Berichten element.
	 * @param LetterFields $fields The checked fields.
	 *
	 * @return DOMElement The Bericht element.
	 */
	private function appendLetter(DOMElement $root, LetterFields $fields): DOMElement {
		$letter = $this->child(parent: $root, namespace: self::NS_LETTER, name: 'b:Bericht');
		$letterInfo = $this->child(parent: $letter, namespace: self::NS_LETTER, name: 'b:BerichtInformatie');
		$this->child(parent: $letterInfo, namespace: self::NS_LETTER, name: 'b:BatchID', text: $fields->batchId);
		$this->child(parent: $letterInfo, namespace: self::NS_LETTER, name: 'b:BerichtID', text: $fields->berichtId);
		$this->child(parent: $letterInfo, namespace: self::NS_LETTER, name: 'b:BerichtType', text: $fields->berichtType);
		$this->child(parent: $letterInfo, namespace: self::NS_LETTER, name: 'b:Onderwerp', text: $fields->subject);
		$this->child(parent: $letterInfo, namespace: self::NS_LETTER, name: 'b:BerichtTekst', text: $fields->text);

		// The reference is shown to the citizen. It carries the case reference
		// when that fits, and is left out when it does not.
		if ($fields->reference !== '' && mb_strlen($fields->reference) <= self::MAX_REFERENCE) {
			$this->child(parent: $letterInfo, namespace: self::NS_LETTER, name: 'b:Referentie', text: $fields->reference);
		}

		$this->child(parent: $letterInfo, namespace: self::NS_LETTER, name: 'b:GebruikerID', text: $fields->bsn);
		$this->child(parent: $letterInfo, namespace: self::NS_LETTER, name: 'b:SoortGebruiker', text: 'Burger');

		return $letter;
	}//end appendLetter()

	/**
	 * Check a batch document against the vendored schema.
	 *
	 * @param string $xml The document.
	 *
	 * @return void
	 *
	 * @throws BerichtenboxException When it does not validate, naming what failed.
	 */
	public function assertValid(string $xml): void {
		$errors = (new LogiusSchema())->errors(xml: $xml, schema: 'BerichtVerwerkService/Request/GLOBEBatchRequest.xsd');
		if ($errors !== []) {
			$this->refuse(reason: 'The letter does not validate against the Logius schema: ' . implode(' ', $errors));
		}
	}//end assertValid()

	/**
	 * The letter text, in the form section 5.7 asks for.
	 *
	 * @param string $body The composed body.
	 *
	 * @return string The text.
	 */
	private function text(string $body): string {
		// Every URL gets a space before and after (section 5.2), trailing
		// punctuation stays outside it, as in the handleiding's own example.
		$body = (string)preg_replace_callback(
			'~https?://[^\s<>"]+~u',
			static function (array $match): string {
				$url = rtrim($match[0], '.,;:!?)');
				$tail = substr($match[0], strlen($url));
				return ' ' . $url . ' ' . $tail;
			},
			$body
		);
		$body = (string)preg_replace('/[ \t]{2,}/', ' ', $body);

		// A line break is the four characters \r\n, not a control character.
		return str_replace(["\r\n", "\r", "\n"], ['\r\n', '\r\n', '\r\n'], trim($body));
	}//end text()

	/**
	 * Add the personal attachments, PDF only, within the size limit.
	 *
	 * @param DOMElement $letter The Bericht element.
	 * @param array<int,mixed> $attachments The message attachments.
	 *
	 * @return void
	 *
	 * @throws BerichtenboxException When an attachment breaks a rule.
	 */
	private function attachments(DOMElement $letter, array $attachments): void {
		$attachments = array_values(array_filter($attachments, 'is_array'));
		if ($attachments === []) {
			return;
		}

		if (count($attachments) > self::MAX_ATTACHMENTS) {
			$this->refuse(reason: sprintf('A letter carries at most %d attachments; this one has %d.', self::MAX_ATTACHMENTS, count($attachments)));
		}

		$total = $this->appendAttachments(letter: $letter, attachments: $attachments);

		if ($total > self::MAX_ATTACHMENT_BYTES) {
			$kilobytes = (int)ceil($total / 1024);
			$this->refuse(reason: sprintf('The attachments are %d kB together; the Berichtenbox takes at most 500 kB before base64.', $kilobytes));
		}
	}//end attachments()

	/**
	 * Append the Bijlagen element with one Bijlage per attachment, in order.
	 *
	 * @param DOMElement                       $letter      The Bericht element.
	 * @param array<int,array<string,mixed>>   $attachments The attachments, numbered from 0.
	 *
	 * @return int The attachments' size together, in bytes before base64.
	 *
	 * @throws BerichtenboxException When an attachment breaks a rule.
	 */
	private function appendAttachments(DOMElement $letter, array $attachments): int {
		$total = 0;
		$list = $this->child(parent: $letter, namespace: self::NS_LETTER, name: 'b:Bijlagen');
		foreach ($attachments as $attachment) {
			$total += $this->appendAttachment(list: $list, attachment: $attachment);
		}

		return $total;
	}//end appendAttachments()

	/**
	 * Append one Bijlage, numbered after the ones already in the list.
	 *
	 * @param DOMElement          $list       The Bijlagen element.
	 * @param array<string,mixed> $attachment The attachment.
	 *
	 * @return int Its size in bytes before base64.
	 *
	 * @throws BerichtenboxException When the attachment breaks a rule.
	 */
	private function appendAttachment(DOMElement $list, array $attachment): int {
		$position = ($list->childNodes->length + 1);
		$bytes = $this->attachmentBytes(attachment: $attachment, position: $position);
		$description = $this->attachmentDescription(attachment: $attachment, position: $position);

		$item = $this->child(parent: $list, namespace: self::NS_LETTER, name: 'b:Bijlage');
		$this->child(parent: $item, namespace: self::NS_LETTER, name: 'b:Inhoud', text: base64_encode($bytes));
		$this->child(parent: $item, namespace: self::NS_LETTER, name: 'b:BijlageType', text: 'Pdf');
		$this->child(parent: $item, namespace: self::NS_LETTER, name: 'b:Omschrijving', text: $description);
		$this->child(parent: $item, namespace: self::NS_LETTER, name: 'b:Volgorde', text: (string)$position);

		return strlen($bytes);
	}//end appendAttachment()

	/**
	 * The attachment's bytes, decoded when it is base64, refused unless it is a PDF.
	 *
	 * @param array<string,mixed> $attachment The attachment.
	 * @param int                 $position   Its position in the letter, from 1.
	 *
	 * @return string The bytes.
	 *
	 * @throws BerichtenboxException When it is not valid base64 or not a PDF.
	 */
	private function attachmentBytes(array $attachment, int $position): string {
		$content = (string)($attachment['content'] ?? '');
		$bytes = $content;
		if ((string)($attachment['encoding'] ?? '') === 'base64') {
			$bytes = base64_decode($content, true);
			if ($bytes === false) {
				$this->refuse(reason: sprintf('Attachment %d is not valid base64.', $position));
			}
		}

		if (str_starts_with((string)$bytes, '%PDF-') === false) {
			$this->refuse(reason: sprintf('Attachment %d is not a PDF. The Berichtenbox takes PDF attachments only.', $position));
		}

		return (string)$bytes;
	}//end attachmentBytes()

	/**
	 * The attachment's description, or its position when it has none, within the length limit.
	 *
	 * @param array<string,mixed> $attachment The attachment.
	 * @param int                 $position   Its position in the letter, from 1.
	 *
	 * @return string The description.
	 *
	 * @throws BerichtenboxException When the description is too long.
	 */
	private function attachmentDescription(array $attachment, int $position): string {
		$description = trim((string)($attachment['description'] ?? $attachment['filename'] ?? $attachment['name'] ?? ''));
		if ($description === '') {
			$description = 'Bijlage ' . $position;
		}

		$this->assertLength(field: 'Omschrijving', value: $description, min: 1, max: self::MAX_DESCRIPTION);

		return $description;
	}//end attachmentDescription()

	/**
	 * Refuse a value outside its length.
	 *
	 * @param string $field The element name.
	 * @param string $value The value.
	 * @param int $min The minimum length.
	 * @param int $max The maximum length.
	 *
	 * @return void
	 *
	 * @throws BerichtenboxException When the value is too short or too long.
	 */
	private function assertLength(string $field, string $value, int $min, int $max): void {
		$length = mb_strlen($value);
		if ($length < $min) {
			$this->refuse(reason: sprintf('%s is empty; the Berichtenbox needs one.', $field));
		}

		if ($length > $max) {
			$this->refuse(reason: sprintf('%s has %d characters; the Berichtenbox takes at most %d. Nothing was cut to fit.', $field, $length, $max));
		}
	}//end assertLength()

	/**
	 * Append a namespaced child element.
	 *
	 * @param DOMElement $parent The parent.
	 * @param string $namespace The namespace.
	 * @param string $name The qualified name.
	 * @param string|null $text The text content, or null for an element with children.
	 *
	 * @return DOMElement The child.
	 */
	private function child(DOMElement $parent, string $namespace, string $name, ?string $text = null): DOMElement {
		$document = $parent->ownerDocument;
		$element = $document->createElementNS($namespace, $name);
		if ($text !== null) {
			$element->appendChild($document->createTextNode($text));
		}

		$parent->appendChild($element);

		return $element;
	}//end child()

	/**
	 * A random GUID in registry format.
	 *
	 * @return string The GUID.
	 */
	private function guid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end guid()

	/**
	 * Throw the refusal.
	 *
	 * @param string $reason The reason.
	 *
	 * @return never
	 *
	 * @throws BerichtenboxException Always.
	 */
	private function refuse(string $reason): never {
		throw new BerichtenboxException($reason, BerichtenboxException::CODE_INVALID_LETTER);
	}//end refuse()
}//end class
