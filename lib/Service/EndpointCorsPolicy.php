<?php

/**
 * The CORS headers an endpoint declares for itself.
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
 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-an-endpoint-may-declare-its-own-cors-policy-req-ep-014
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IConfig;

/**
 * An endpoint's own CORS policy (REQ-EP-014).
 *
 * An endpoint that declares `cors` answers its preflight and its responses
 * with the origin, methods and headers it names instead of integriq's
 * default, which echoes any origin. `allowedOrigin` is `self` (the
 * instance's own origin, from overwrite.cli.url), `*` or one origin.
 * Credentials are never allowed.
 *
 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-an-endpoint-may-declare-its-own-cors-policy-req-ep-014
 */
class EndpointCorsPolicy {

	/**
	 * Methods allowed when the policy names none.
	 *
	 * @var string[]
	 */
	private const DEFAULT_METHODS = ['GET', 'OPTIONS'];

	/**
	 * Headers allowed when the policy names none.
	 *
	 * @var string[]
	 */
	private const DEFAULT_HEADERS = ['Authorization', 'Content-Type', 'X-Requested-With'];

	/**
	 * Constructor.
	 *
	 * @param IConfig $config System configuration, for the instance's own origin.
	 */
	public function __construct(
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * The headers for an endpoint's declared policy, or null when it declares none.
	 *
	 * @param ObjectEntity|null $endpoint The matched endpoint, if any.
	 *
	 * @return array<string,string>|null
	 *
	 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-an-endpoint-may-declare-its-own-cors-policy-req-ep-014
	 */
	public function headersFor(?ObjectEntity $endpoint): ?array {
		$cors = null;
		if ($endpoint !== null) {
			$cors = ($endpoint->getObject()['cors'] ?? null);
		}

		if (is_array($cors) === false || $cors === []) {
			return null;
		}

		$origin = trim((string) ($cors['allowedOrigin'] ?? 'self'));
		if ($origin === 'self' || $origin === '') {
			$origin = $this->ownOrigin();
		}

		$methods = $this->listOf(value: ($cors['allowedMethods'] ?? null), default: self::DEFAULT_METHODS);
		$headers = $this->listOf(value: ($cors['allowedHeaders'] ?? null), default: self::DEFAULT_HEADERS);

		return [
			'Access-Control-Allow-Origin'      => $origin,
			'Access-Control-Allow-Methods'     => implode(', ', array_map('strtoupper', $methods)),
			'Access-Control-Allow-Headers'     => implode(', ', $headers),
			'Access-Control-Allow-Credentials' => 'false',
			'Vary'                             => 'Origin',
		];
	}//end headersFor()

	/**
	 * The instance's own origin (scheme://host[:port]), or `*` when unknown.
	 *
	 * Like decidiq's OriController::applyCorsHeaders(), which answers overwrite.cli.url
	 * or `*` without it; an Origin never carries a path, so the path is cut.
	 *
	 * @return string
	 */
	private function ownOrigin(): string {
		$url   = trim($this->config->getSystemValueString('overwrite.cli.url', ''));
		$parts = parse_url($url);
		if ($url === '' || is_array($parts) === false || isset($parts['scheme'], $parts['host']) === false) {
			return '*';
		}

		$origin = $parts['scheme'].'://'.$parts['host'];
		if (isset($parts['port']) === true) {
			$origin .= ':'.$parts['port'];
		}

		return $origin;
	}//end ownOrigin()

	/**
	 * A non-empty list of strings, or the default.
	 *
	 * @param mixed    $value   The declared list.
	 * @param string[] $default The list when none is declared.
	 *
	 * @return string[]
	 */
	private function listOf(mixed $value, array $default): array {
		if (is_array($value) === false) {
			return $default;
		}

		$list = array_values(array_filter(array_map('strval', $value), fn (string $item) => trim($item) !== ''));
		if ($list === []) {
			return $default;
		}

		return $list;
	}//end listOf()
}//end class
