<?php

/**
 * Unit tests for CloudEventHttpBinding.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Broker
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-cloudevents-travel-in-structured-or-binary-content-mode-req-015
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Broker;

use OCA\Integriq\Broker\CloudEventHttpBinding;
use PHPUnit\Framework\TestCase;

/**
 * Tests the CloudEvents HTTP protocol binding.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-cloudevents-travel-in-structured-or-binary-content-mode-req-015
 */
class CloudEventHttpBindingTest extends TestCase {

	/**
	 * One CloudEvent to render.
	 *
	 * @return array<string,mixed> The event.
	 */
	private function event(): array {
		return [
			'specversion' => '1.0',
			'id' => 'b9b6b0a2',
			'source' => '/integriq/zaken',
			'type' => 'nl.conduction.zaak.created',
			'time' => '2026-09-22T10:11:12+00:00',
			'subject' => 'ZAAK-2026-0042',
			'datacontenttype' => 'application/json',
			'data' => ['zaak' => 'ZAAK-2026-0042', 'status' => 'open'],
		];
	}//end event()

	/**
	 * Structured mode keeps the envelope in the body.
	 *
	 * @return void
	 */
	public function testStructuredModeKeepsTheEnvelopeInTheBody(): void {
		$rendered = (new CloudEventHttpBinding())->render(
			cloudEvent: $this->event(),
			contentMode: CloudEventHttpBinding::MODE_STRUCTURED
		);

		$this->assertSame('application/cloudevents+json', $rendered['headers']['Content-Type']);
		$this->assertSame($this->event(), json_decode($rendered['body'], true));

		foreach (array_keys($rendered['headers']) as $header) {
			$this->assertStringStartsNotWith('ce-', (string)$header);
		}
	}//end testStructuredModeKeepsTheEnvelopeInTheBody()

	/**
	 * Binary mode moves the attributes into headers and leaves the data alone
	 * in the body.
	 *
	 * @return void
	 */
	public function testBinaryModeMovesTheAttributesIntoHeaders(): void {
		$rendered = (new CloudEventHttpBinding())->render(
			cloudEvent: $this->event(),
			contentMode: CloudEventHttpBinding::MODE_BINARY
		);

		$this->assertSame('application/json', $rendered['headers']['Content-Type']);
		$this->assertSame('nl.conduction.zaak.created', $rendered['headers']['ce-type']);
		$this->assertSame('b9b6b0a2', $rendered['headers']['ce-id']);
		$this->assertSame('/integriq/zaken', $rendered['headers']['ce-source']);
		$this->assertSame('1.0', $rendered['headers']['ce-specversion']);
		$this->assertSame('ZAAK-2026-0042', $rendered['headers']['ce-subject']);
		$this->assertArrayNotHasKey('ce-data', $rendered['headers']);
		$this->assertArrayNotHasKey('ce-datacontenttype', $rendered['headers']);
		$this->assertSame(['zaak' => 'ZAAK-2026-0042', 'status' => 'open'], json_decode($rendered['body'], true));
	}//end testBinaryModeMovesTheAttributesIntoHeaders()

	/**
	 * An attribute that is not a scalar is left out of the headers rather than
	 * cast, because a header carrying `Array` is a lie the receiver cannot
	 * detect.
	 *
	 * @return void
	 */
	public function testANonScalarAttributeIsLeftOutOfTheHeaders(): void {
		$event = $this->event();
		$event['tags'] = ['bezwaar', 'spoed'];
		$event['priority'] = 3;
		$event['urgent'] = true;

		$rendered = (new CloudEventHttpBinding())->render(
			cloudEvent: $event,
			contentMode: CloudEventHttpBinding::MODE_BINARY
		);

		$this->assertArrayNotHasKey('ce-tags', $rendered['headers']);
		$this->assertSame('3', $rendered['headers']['ce-priority']);
		$this->assertSame('true', $rendered['headers']['ce-urgent']);
	}//end testANonScalarAttributeIsLeftOutOfTheHeaders()

	/**
	 * A binary-encoded payload travels as its decoded bytes.
	 *
	 * @return void
	 */
	public function testABase64PayloadTravelsDecoded(): void {
		$event = $this->event();
		unset($event['data']);
		$event['data_base64'] = base64_encode('raw-bytes');
		$event['datacontenttype'] = 'application/octet-stream';

		$rendered = (new CloudEventHttpBinding())->render(
			cloudEvent: $event,
			contentMode: CloudEventHttpBinding::MODE_BINARY
		);

		$this->assertSame('raw-bytes', $rendered['body']);
		$this->assertSame('application/octet-stream', $rendered['headers']['Content-Type']);
	}//end testABase64PayloadTravelsDecoded()

	/**
	 * An unrecognised content mode is read as structured, never as binary: a
	 * typo must not quietly drop the envelope.
	 *
	 * @return void
	 */
	public function testAnUnknownContentModeIsReadAsStructured(): void {
		$rendered = (new CloudEventHttpBinding())->render(
			cloudEvent: $this->event(),
			contentMode: 'binairy'
		);

		$this->assertSame('application/cloudevents+json', $rendered['headers']['Content-Type']);
	}//end testAnUnknownContentModeIsReadAsStructured()

}//end class
