<?php

/**
 * Integriq ProblemResponse: an RFC 9457 problem document for gateway errors.
 *
 * @category Http
 * @package  OCA\Integriq\Http
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * A problem document (RFC 9457) with content type application/problem+json.
 *
 * Members: `type` a URI under {@see self::TYPE_BASE}, `title` a short
 * sentence per type, `status`, `detail` the specific message and `instance`
 * the request id. Validation problems carry `invalid-params`, a list of
 * `{name, reason}`. The old `error` and `details` members stay as extensions
 * for one release, so existing clients keep reading them; they are deprecated.
 *
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002
 */
class ProblemResponse extends JSONResponse {

	/**
	 * The media type of a problem document.
	 *
	 * @var string
	 */
	public const CONTENT_TYPE = 'application/problem+json';

	/**
	 * Where the problem types are documented.
	 *
	 * @var string
	 */
	public const TYPE_BASE = 'https://integriq.nl/problems/';

	/**
	 * The problem slug when no more specific one applies.
	 *
	 * @var string
	 */
	public const GENERIC = 'gateway-error';

	/**
	 * The title of each problem type.
	 *
	 * @var array<string,string>
	 */
	public const TITLES = [
		'authentication-failed' => 'The caller could not be authenticated',
		'forbidden' => 'The caller may not make this call',
		'rate-limited' => 'Too many requests',
		'validation-failed' => 'The request is not valid',
		'not-found' => 'The resource was not found',
		'method-not-allowed' => 'The method is not allowed on this endpoint',
		'not-implemented' => 'This is not supported',
		'upstream-unavailable' => 'The source behind this endpoint did not answer correctly',
		'gateway-error' => 'The gateway could not handle the request',
	];

	/**
	 * The problem type for a status that names no type itself.
	 *
	 * @var array<int,string>
	 */
	private const STATUS_TYPES = [
		Http::STATUS_BAD_REQUEST => 'validation-failed',
		Http::STATUS_UNAUTHORIZED => 'authentication-failed',
		Http::STATUS_FORBIDDEN => 'forbidden',
		Http::STATUS_NOT_FOUND => 'not-found',
		Http::STATUS_METHOD_NOT_ALLOWED => 'method-not-allowed',
		Http::STATUS_UNPROCESSABLE_ENTITY => 'validation-failed',
		Http::STATUS_TOO_MANY_REQUESTS => 'rate-limited',
		Http::STATUS_NOT_IMPLEMENTED => 'not-implemented',
		Http::STATUS_BAD_GATEWAY => 'upstream-unavailable',
		Http::STATUS_SERVICE_UNAVAILABLE => 'upstream-unavailable',
		Http::STATUS_GATEWAY_TIMEOUT => 'upstream-unavailable',
	];

	/**
	 * Constructor.
	 *
	 * @param string                           $slug          The problem type slug, a key of TITLES.
	 * @param int                              $status        The HTTP status.
	 * @param string                           $detail        The specific message.
	 * @param string|null                      $instance      The request id, or null when there is none.
	 * @param array<int,array{name: string, reason: string}> $invalidParams The invalid fields of a validation problem.
	 * @param array<string,mixed>              $extensions    More members, such as the deprecated `error` and `details`.
	 * @param array<string,string>             $headers       More headers.
	 *
	 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002
	 */
	public function __construct(
		string $slug,
		int $status,
		string $detail,
		?string $instance = null,
		array $invalidParams = [],
		array $extensions = [],
		array $headers = [],
	) {
		if (isset(self::TITLES[$slug]) === false) {
			$slug = self::GENERIC;
		}

		$document = [
			'type' => self::TYPE_BASE . $slug,
			'title' => self::TITLES[$slug],
			'status' => $status,
			'detail' => $detail,
		];
		if ($instance !== null && $instance !== '') {
			$document['instance'] = $instance;
		}

		if ($invalidParams !== []) {
			$document['invalid-params'] = array_values($invalidParams);
		}

		foreach ($extensions as $name => $value) {
			if (array_key_exists(key: $name, array: $document) === false) {
				$document[$name] = $value;
			}
		}

		// The status is any HTTP error status the gateway answers; OCP narrows it to a literal union.
		// @phpstan-ignore argument.type
		parent::__construct(data: $document, statusCode: $status, headers: $headers);
		$this->addHeader(name: 'Content-Type', value: self::CONTENT_TYPE);
	}//end __construct()

	/**
	 * The problem type slug for a status.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002
	 */
	public static function slugForStatus(int $status): string {
		if (isset(self::STATUS_TYPES[$status]) === true) {
			return self::STATUS_TYPES[$status];
		}

		if ($status >= 500) {
			return 'upstream-unavailable';
		}

		return self::GENERIC;
	}//end slugForStatus()

	/**
	 * A problem document from an error answer in the old `{error, details}` shape.
	 *
	 * The old members stay on the document; the error becomes the detail
	 * unless the old answer carries a string `details` and no error.
	 *
	 * @param array<string,mixed> $data     The old answer body.
	 * @param int                 $status   The HTTP status.
	 * @param string|null         $instance The request id.
	 * @param array<string,string> $headers The old answer's headers to keep.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002
	 */
	public static function fromLegacy(array $data, int $status, ?string $instance = null, array $headers = []): self {
		$detail = '';
		foreach (['detail', 'error', 'message', 'details', 'title'] as $member) {
			if (is_string(($data[$member] ?? null)) === true && $data[$member] !== '') {
				$detail = $data[$member];
				break;
			}
		}

		return new self(
			slug: self::slugForStatus(status: $status),
			status: $status,
			detail: $detail,
			instance: $instance,
			invalidParams: self::invalidParamsOf(data: $data),
			extensions: $data,
			headers: $headers
		);
	}//end fromLegacy()

	/**
	 * The `invalid-params` of an old answer that listed invalid fields.
	 *
	 * Reads `invalidParams` (`{property, reason}`) and `errors` (`{property|name|path, message|reason}`).
	 *
	 * @param array<string,mixed> $data The old answer body.
	 *
	 * @return array<int,array{name: string, reason: string}>
	 */
	private static function invalidParamsOf(array $data): array {
		$entries = [];
		foreach (['invalid-params', 'invalidParams', 'errors'] as $member) {
			if (is_array(($data[$member] ?? null)) === true) {
				$entries = $data[$member];
				break;
			}
		}

		$params = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$name = self::firstString(entry: $entry, members: ['name', 'property', 'path', 'field']);
			$reason = self::firstString(entry: $entry, members: ['reason', 'message']);
			if ($name !== '' || $reason !== '') {
				$params[] = ['name' => $name, 'reason' => $reason];
			}
		}

		return $params;
	}//end invalidParamsOf()

	/**
	 * The first non-empty string member of an entry.
	 *
	 * @param array<string,mixed> $entry   The entry.
	 * @param string[]            $members The members to try, in order.
	 *
	 * @return string
	 */
	private static function firstString(array $entry, array $members): string {
		foreach ($members as $member) {
			if (is_string(($entry[$member] ?? null)) === true && $entry[$member] !== '') {
				return $entry[$member];
			}
		}

		return '';
	}//end firstString()
}//end class
