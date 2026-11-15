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
 * @spec openspec/specs/events-cloudevents/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Broker;

use OCA\Integriq\Exception\CloudEventRefusedException;

/**
 * The CloudEvents HTTP protocol binding, both content modes.
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-cloudevents-travel-in-structured-or-binary-content-mode-req-015
 */
class CloudEventHttpBinding implements CloudEventHttpBindingInterface {

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
	 * The attributes every received event must carry, in the order they are checked.
	 *
	 * @var array<int,string>
	 */
	public const REQUIRED_ATTRIBUTES = ['specversion', 'id', 'source', 'type'];

	/**
	 * The attributes read from `ce-` headers in binary mode, besides the required ones.
	 * Extension attributes are not read: the request does not list its headers.
	 *
	 * @var array<int,string>
	 */
	private const OPTIONAL_ATTRIBUTES = ['subject', 'time', 'dataschema'];

	/**
	 * Render one CloudEvent for HTTP.
	 *
	 * @param array<string,mixed> $cloudEvent The event.
	 * @param string $contentMode `structured` or `binary`. Anything else is read as `structured`.
	 *
	 * @return array{headers: array<string,string>, body: string} The headers and the serialised body.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-cloudevents-travel-in-structured-or-binary-content-mode-req-015
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
	 * Read one received CloudEvent from its HTTP body and headers.
	 *
	 * A body sent as `application/cloudevents+json` is the whole event
	 * (structured mode). Any other body is the data alone, with the attributes
	 * in `ce-` headers (binary mode). Either way `specversion`, `id`,
	 * `source` and `type` must be present, and the version must be 1.0.
	 *
	 * @param string   $body     The raw request body.
	 * @param callable $headerOf Returns a request header by name, '' when absent.
	 *
	 * @return array<string,mixed> The event, its required attributes checked.
	 *
	 * @throws CloudEventRefusedException When the event cannot be read or misses a required attribute.
	 *
	 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
	 */
	public function decode(string $body, callable $headerOf): array {
		$contentType = trim((string)$headerOf('Content-Type'));
		if (str_starts_with(strtolower($contentType), self::CLOUDEVENTS_JSON) === true) {
			$cloudEvent = json_decode($body, true);
			if (is_array($cloudEvent) === false || array_is_list($cloudEvent) === true) {
				throw new CloudEventRefusedException(message: 'The body is not a CloudEvent in JSON.');
			}
		} else {
			$cloudEvent = $this->fromHeaders(body: $body, contentType: $contentType, headerOf: $headerOf);
		}

		foreach (self::REQUIRED_ATTRIBUTES as $attribute) {
			$value = ($cloudEvent[$attribute] ?? null);
			if (is_string($value) === false || trim($value) === '') {
				throw new CloudEventRefusedException(message: sprintf('The CloudEvent has no %s.', $attribute));
			}
		}

		if ($cloudEvent['specversion'] !== '1.0') {
			throw new CloudEventRefusedException(message: sprintf('CloudEvents version %s is not supported; send 1.0.', $cloudEvent['specversion']));
		}

		return $cloudEvent;

	}//end decode()

	/**
	 * A binary-mode event: the attributes from `ce-` headers, the body as its data.
	 *
	 * @param string   $body        The raw request body, the event's data.
	 * @param string   $contentType The body's content type, '' when absent.
	 * @param callable $headerOf    Returns a request header by name, '' when absent.
	 *
	 * @return array<string,mixed> The event, unchecked.
	 */
	private function fromHeaders(string $body, string $contentType, callable $headerOf): array {
		$cloudEvent = [];
		foreach (array_merge(self::REQUIRED_ATTRIBUTES, self::OPTIONAL_ATTRIBUTES) as $attribute) {
			$value = trim((string)$headerOf('ce-' . $attribute));
			if ($value !== '') {
				$cloudEvent[$attribute] = $value;
			}
		}

		if ($contentType !== '') {
			$cloudEvent['datacontenttype'] = $contentType;
		}

		if ($body === '') {
			return $cloudEvent;
		}

		$decoded = null;
		if ($contentType === '' || preg_match('#[/+]json\b#i', $contentType) === 1) {
			$decoded = json_decode($body, true);
		}

		if ($decoded !== null) {
			$cloudEvent['data'] = $decoded;
			return $cloudEvent;
		}

		if (mb_check_encoding($body, 'UTF-8') === true) {
			$cloudEvent['data'] = $body;
			return $cloudEvent;
		}

		$cloudEvent['data_base64'] = base64_encode($body);

		return $cloudEvent;

	}//end fromHeaders()

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
