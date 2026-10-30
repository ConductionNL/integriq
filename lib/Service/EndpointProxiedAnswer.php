<?php

/**
 * What an inbound caller of a proxied endpoint receives of the upstream's answer.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/specs/endpoint-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Filters the upstream answer of an `api` endpoint before it leaves integriq.
 *
 * Only headers that describe the content or its pagination pass. Everything
 * else (cookies, credentials, authentication challenges, server and proxy
 * internals) stays inside, and so does the upstream's network address.
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 */
class EndpointProxiedAnswer {

	/**
	 * Header names an inbound caller may receive, lower case.
	 *
	 * @var string[]
	 */
	private const ALLOWED_HEADERS = [
		'content-type',
		'content-disposition',
		'content-language',
		'content-length',
		'etag',
		'last-modified',
		'cache-control',
		'expires',
		'link',
		'location',
		'api-version',
		'x-total-count',
		'x-total',
		'x-page',
		'x-per-page',
		'x-next-page',
		'x-prev-page',
	];

	/**
	 * The upstream answer as the inbound caller may see it.
	 *
	 * @param array $response The `response` of the call log CallService handed back.
	 *
	 * @return array The answer without its address and with only the allowed headers.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function forCaller(array $response): array {
		unset($response['remoteIp']);

		if (is_array($response['headers'] ?? null) === true) {
			$response['headers'] = array_filter(
				$response['headers'],
				fn (int|string $name): bool => in_array(strtolower((string)$name), self::ALLOWED_HEADERS, true),
				ARRAY_FILTER_USE_KEY
			);
		}

		return $response;
	}//end forCaller()
}//end class
