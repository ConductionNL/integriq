<?php

/**
 * Unit tests for CloudEventHttpBinding::decode().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Broker
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Broker;

use OCA\Integriq\Broker\CloudEventHttpBinding;
use OCA\Integriq\Exception\CloudEventRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * Reads received CloudEvents in both content modes and checks the envelope.
 *
 * @covers \OCA\Integriq\Broker\CloudEventHttpBinding
 *
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 */
class CloudEventHttpBindingDecodeTest extends TestCase {

	/**
	 * A header lookup over a fixed set of headers, case-insensitive like a request.
	 *
	 * @param array<string,string> $headers The headers.
	 *
	 * @return callable(string): string
	 */
	private function headers(array $headers): callable {
		$lower = array_change_key_case($headers, CASE_LOWER);

		return static fn (string $name): string => ($lower[strtolower($name)] ?? '');
	}//end headers()

	/**
	 * A structured event is read whole from the body.
	 *
	 * @return void
	 */
	public function testAStructuredEventIsReadFromTheBody(): void {
		$event = [
			'specversion' => '1.0',
			'id' => 'a1',
			'source' => '/shop',
			'type' => 'nl.example.order.created',
			'data' => ['order' => 42],
		];

		$decoded = (new CloudEventHttpBinding())->decode(
			body: (string)json_encode($event),
			headerOf: $this->headers(['Content-Type' => 'application/cloudevents+json; charset=utf-8'])
		);

		$this->assertSame($event, $decoded);
	}//end testAStructuredEventIsReadFromTheBody()

	/**
	 * A binary event is read from its ce- headers, with the body as its data.
	 *
	 * @return void
	 */
	public function testABinaryEventIsReadFromItsHeaders(): void {
		$decoded = (new CloudEventHttpBinding())->decode(
			body: '{"order":42}',
			headerOf: $this->headers(
				[
					'Content-Type' => 'application/json',
					'ce-specversion' => '1.0',
					'ce-id' => 'a1',
					'ce-source' => '/shop',
					'ce-type' => 'nl.example.order.created',
					'ce-subject' => 'order-42',
				]
			)
		);

		$this->assertSame(
			[
				'specversion' => '1.0',
				'id' => 'a1',
				'source' => '/shop',
				'type' => 'nl.example.order.created',
				'subject' => 'order-42',
				'datacontenttype' => 'application/json',
				'data' => ['order' => 42],
			],
			$decoded
		);
	}//end testABinaryEventIsReadFromItsHeaders()

	/**
	 * Binary data that is not JSON is kept as text, or as base64 when it is not text.
	 *
	 * @return void
	 */
	public function testBinaryDataThatIsNotJsonIsKeptAsTextOrBase64(): void {
		$headers = [
			'ce-specversion' => '1.0',
			'ce-id' => 'a1',
			'ce-source' => '/shop',
			'ce-type' => 'nl.example.order.created',
		];
		$binding = new CloudEventHttpBinding();

		$text = $binding->decode(body: 'order 42', headerOf: $this->headers($headers + ['Content-Type' => 'text/plain']));
		$this->assertSame('order 42', $text['data']);

		$bytes = $binding->decode(body: "\xff\xfe\x00", headerOf: $this->headers($headers + ['Content-Type' => 'application/octet-stream']));
		$this->assertSame(base64_encode("\xff\xfe\x00"), $bytes['data_base64']);
		$this->assertArrayNotHasKey('data', $bytes);
	}//end testBinaryDataThatIsNotJsonIsKeptAsTextOrBase64()

	/**
	 * An envelope without a type is refused, naming the missing attribute.
	 *
	 * @return void
	 */
	public function testAnEnvelopeWithoutATypeIsRefused(): void {
		$this->expectException(CloudEventRefusedException::class);
		$this->expectExceptionMessage('The CloudEvent has no type.');

		(new CloudEventHttpBinding())->decode(
			body: '{"specversion":"1.0","id":"a1","source":"/shop"}',
			headerOf: $this->headers(['Content-Type' => 'application/cloudevents+json'])
		);
	}//end testAnEnvelopeWithoutATypeIsRefused()

	/**
	 * A binary event without a ce-id header is refused, naming the id.
	 *
	 * @return void
	 */
	public function testABinaryEventWithoutAnIdIsRefused(): void {
		$this->expectException(CloudEventRefusedException::class);
		$this->expectExceptionMessage('The CloudEvent has no id.');

		(new CloudEventHttpBinding())->decode(
			body: '{}',
			headerOf: $this->headers(['ce-specversion' => '1.0', 'ce-source' => '/shop', 'ce-type' => 'x'])
		);
	}//end testABinaryEventWithoutAnIdIsRefused()

	/**
	 * Another CloudEvents version is refused.
	 *
	 * @return void
	 */
	public function testAnotherSpecVersionIsRefused(): void {
		$this->expectException(CloudEventRefusedException::class);
		$this->expectExceptionMessage('CloudEvents version 0.3 is not supported; send 1.0.');

		(new CloudEventHttpBinding())->decode(
			body: '{"specversion":"0.3","id":"a1","source":"/shop","type":"x"}',
			headerOf: $this->headers(['Content-Type' => 'application/cloudevents+json'])
		);
	}//end testAnotherSpecVersionIsRefused()

	/**
	 * A structured body that is not a JSON object is refused.
	 *
	 * @return void
	 */
	public function testAStructuredBodyThatIsNotAnObjectIsRefused(): void {
		$this->expectException(CloudEventRefusedException::class);
		$this->expectExceptionMessage('The body is not a CloudEvent in JSON.');

		(new CloudEventHttpBinding())->decode(
			body: '[1,2]',
			headerOf: $this->headers(['Content-Type' => 'application/cloudevents+json'])
		);
	}//end testAStructuredBodyThatIsNotAnObjectIsRefused()
}//end class
