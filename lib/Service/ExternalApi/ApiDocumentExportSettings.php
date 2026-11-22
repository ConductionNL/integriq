<?php

/**
 * Integriq ApiDocumentExportSettings.
 *
 * What one document export run fetches, read from its job's arguments.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ExternalApi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ExternalApi;

use InvalidArgumentException;

/**
 * The gateway, the export path and where the document sits in the answer.
 *
 * `exportEndpoint` is a path below the gateway source's location with two
 * placeholders: `{apiId}`, the API's `gatewayApiId`, and `{apiName}`, the last
 * path segment of it (an Azure resource id ends in the API's name). Both are
 * encoded as one path segment.
 *
 * The options are the job's other arguments: `query` (the export call's
 * query), `documentPosition` (where the document sits in the answer; empty for
 * the whole answer), `linkPosition` (where a link to the document sits; empty
 * when there is none), `limit` (the most APIs one run reads) and `force`
 * (fetch an unchanged API again).
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
 */
final class ApiDocumentExportSettings {

	/**
	 * The discovery source's slug, as `external_api.gateway` names it.
	 *
	 * @var string
	 */
	public readonly string $gateway;

	/**
	 * The export path, with `{apiId}` or `{apiName}`.
	 *
	 * @var string
	 */
	public readonly string $exportEndpoint;

	/**
	 * The export call's query.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $query;

	/**
	 * Where the document sits in the answer; empty for the whole answer.
	 *
	 * @var string
	 */
	public readonly string $documentPosition;

	/**
	 * Where a link to the document sits in the answer; empty when there is none.
	 *
	 * @var string
	 */
	public readonly string $linkPosition;

	/**
	 * The most APIs one run reads.
	 *
	 * @var int
	 */
	public readonly int $limit;

	/**
	 * Whether an unchanged API is fetched again.
	 *
	 * @var bool
	 */
	public readonly bool $force;

	/**
	 * Constructor.
	 *
	 * @param string               $gateway        The discovery source's slug.
	 * @param string               $exportEndpoint The export path, with `{apiId}` or `{apiName}`.
	 * @param array<string, mixed> $options        `query`, `documentPosition`, `linkPosition`, `limit`, `force`.
	 *
	 * @throws InvalidArgumentException When the gateway or the export path is missing.
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
	 */
	public function __construct(string $gateway, string $exportEndpoint, array $options = []) {
		$this->gateway        = trim($gateway);
		$this->exportEndpoint = trim($exportEndpoint);
		if ($this->gateway === '') {
			throw new InvalidArgumentException('A document export needs `gateway`: the slug of the discovery source.');
		}

		if (str_contains($this->exportEndpoint, '{apiId}') === false && str_contains($this->exportEndpoint, '{apiName}') === false) {
			throw new InvalidArgumentException('A document export needs `exportEndpoint` with {apiId} or {apiName} in it.');
		}

		$query = ($options['query'] ?? []);
		if (is_array($query) === false) {
			$query = [];
		}

		$limit = (int)($options['limit'] ?? ApiDocumentExport::DEFAULT_LIMIT);
		if ($limit < 1) {
			$limit = ApiDocumentExport::DEFAULT_LIMIT;
		}

		$this->query            = $query;
		$this->limit            = $limit;
		$this->documentPosition = trim((string)($options['documentPosition'] ?? ''));
		$this->linkPosition     = trim((string)($options['linkPosition'] ?? ''));
		$this->force            = filter_var(($options['force'] ?? false), FILTER_VALIDATE_BOOLEAN);
	}//end __construct()

	/**
	 * The export path of one API.
	 *
	 * @param string $apiId The API's gateway id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
	 */
	public function endpointFor(string $apiId): string {
		$segments = explode('/', rtrim($apiId, '/'));

		return strtr(
			$this->exportEndpoint,
			['{apiId}' => self::segment(value: $apiId), '{apiName}' => self::segment(value: (string)end($segments))]
		);
	}//end endpointFor()

	/**
	 * A value encoded as one path segment, keeping the delimiters a segment may carry.
	 *
	 * An Azure revision is addressed as `afval;rev=3`, so `;` and `=` stay as they are.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string
	 */
	private static function segment(string $value): string {
		return strtr(rawurlencode($value), ['%3B' => ';', '%3D' => '=', '%2C' => ',', '%3A' => ':', '%40' => '@']);
	}//end segment()
}//end class
