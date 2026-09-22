<?php

/**
 * Integriq CloudEventHttpBinding.
 *
 * Renders a CloudEvent for HTTP in either content mode of the CloudEvents HTTP
 * Protocol Binding. Structured puts the whole event in the body. Binary puts
 * the data alone in the body and the attributes in `ce-` headers, which is
 * what a broker ingress usually wants.
 *
 * @category Broker
 * @package  OCA\Integriq\Broker
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Broker;

/**
 * The CloudEvents HTTP protocol binding, both content modes.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-cloudevents-travel-in-structured-or-binary-content-mode-req-015
 */
class CloudEventHttpBinding {

	/**
	 * The whole event in the body.
	 *
	 * @var string
	 */
	public const MODE_STRUCTURED = 'structured';

	/**
	 * The data in the body, the attributes in headers.
	 *
	 * @var string
	 */
	public const MODE_BINARY = 'binary';

	/**
	 * The media type a structured CloudEvent travels under.
	 *
	 * @var string
	 */
	public const CLOUDEVENTS_JSON = 'application/cloudevents+json';

	/**
	 * The attributes that are never written as a `ce-` header.
	 *
	 * `data` is the body, `data_base64` is the body in another encoding, and
	 * `datacontenttype` becomes the body's own `Content-Type`.
	 *
	 * @var array<int,string>
	 */
	private const BODY_ATTRIBUTES = ['data', 'data_base64', 'datacontenttype'];

	/**
	 * Render one CloudEvent for HTTP.
	 *
	 * @param array<string,mixed> $cloudEvent The event.
	 * @param string $contentMode `structured` or `binary`. Anything else is read as `structured`.
	 *
	 * @return array{headers: array<string,string>, body: string} The headers and the serialised body.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-cloudevents-travel-in-structured-or-binary-content-mode-req-015
	 */
	public function render(array $cloudEvent, string $contentMode = self::MODE_STRUCTURED): array {
		if ($contentMode !== self::MODE_BINARY) {
			return [
				'headers' => ['Content-Type' => self::CLOUDEVENTS_JSON],
				'body' => (string)json_encode($cloudEvent),
			];
		}

		$headers = ['Content-Type' => $this->dataContentType(cloudEvent: $cloudEvent)];
		foreach ($cloudEvent as $attribute => $value) {
			if (in_array($attribute, self::BODY_ATTRIBUTES, true) === true) {
				continue;
			}

			$rendered = $this->headerValue(value: $value);
			if ($rendered === null) {
				// A header carrying `Array` is a lie the receiver cannot
				// detect, so a non-scalar attribute is left out rather than
				// cast. The whole event is still readable in structured mode.
				continue;
			}

			$headers['ce-' . strtolower((string)$attribute)] = $rendered;
		}

		return ['headers' => $headers, 'body' => $this->binaryBody(cloudEvent: $cloudEvent)];

	}//end render()

	/**
	 * The body's own content type in binary mode.
	 *
	 * @param array<string,mixed> $cloudEvent The event.
	 *
	 * @return string The content type.
	 */
	private function dataContentType(array $cloudEvent): string {
		$declared = trim((string)($cloudEvent['datacontenttype'] ?? ''));
		if ($declared !== '') {
			return $declared;
		}

		return 'application/json';

	}//end dataContentType()

	/**
	 * The body in binary mode: the event's data, alone.
	 *
	 * @param array<string,mixed> $cloudEvent The event.
	 *
	 * @return string The serialised data.
	 */
	private function binaryBody(array $cloudEvent): string {
		if (array_key_exists('data_base64', $cloudEvent) === true) {
			$decoded = base64_decode((string)$cloudEvent['data_base64'], true);
			if ($decoded !== false) {
				return $decoded;
			}

			return '';
		}

		$data = ($cloudEvent['data'] ?? null);
		if (is_string($data) === true) {
			return $data;
		}

		if ($data === null) {
			return '';
		}

		return (string)json_encode($data);

	}//end binaryBody()

	/**
	 * One attribute as a header value, or null when it cannot be one.
	 *
	 * @param mixed $value The attribute value.
	 *
	 * @return string|null The header value, or null.
	 */
	private function headerValue(mixed $value): ?string {
		if (is_string($value) === true) {
			return $value;
		}

		if (is_bool($value) === true) {
			if ($value === true) {
				return 'true';
			}

			return 'false';
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		return null;

	}//end headerValue()

}//end class
