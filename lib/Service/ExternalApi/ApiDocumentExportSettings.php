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
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
 */
final class ApiDocumentExportSettings {

	/**
	 * Constructor.
	 *
	 * @param string               $gateway          The discovery source's slug, as `external_api.gateway` names it.
	 * @param string               $exportEndpoint   The export path, with `{apiId}` or `{apiName}`.
	 * @param array<string, mixed> $query            The export call's query.
	 * @param string               $documentPosition Where the document sits in the answer; empty for the whole answer.
	 * @param string               $linkPosition     Where a link to the document sits in the answer; empty when there is none.
	 * @param int                  $limit            The most APIs one run reads.
	 * @param bool                 $force            True to fetch an unchanged API again.
	 */
	public function __construct(
		public readonly string $gateway,
		public readonly string $exportEndpoint,
		public readonly array $query = [],
		public readonly string $documentPosition = '',
		public readonly string $linkPosition = '',
		public readonly int $limit = ApiDocumentExport::DEFAULT_LIMIT,
		public readonly bool $force = false,
	) {
	}//end __construct()

	/**
	 * Read the settings from a job's arguments.
	 *
	 * @param array<string, mixed> $arguments The job's arguments.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When the gateway or the export path is missing.
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
	 */
	public static function fromArguments(array $arguments): self {
		$gateway  = trim((string)($arguments['gateway'] ?? ''));
		$endpoint = trim((string)($arguments['exportEndpoint'] ?? ''));
		if ($gateway === '') {
			throw new InvalidArgumentException('A document export needs `gateway`: the slug of the discovery source.');
		}

		if (str_contains($endpoint, '{apiId}') === false && str_contains($endpoint, '{apiName}') === false) {
			throw new InvalidArgumentException('A document export needs `exportEndpoint` with {apiId} or {apiName} in it.');
		}

		$query = ($arguments['query'] ?? []);
		$limit = (int)($arguments['limit'] ?? ApiDocumentExport::DEFAULT_LIMIT);

		return new self(
			gateway: $gateway,
			exportEndpoint: $endpoint,
			query: (is_array($query) === true ? $query : []),
			documentPosition: trim((string)($arguments['documentPosition'] ?? '')),
			linkPosition: trim((string)($arguments['linkPosition'] ?? '')),
			limit: ($limit > 0 ? $limit : ApiDocumentExport::DEFAULT_LIMIT),
			force: filter_var(($arguments['force'] ?? false), FILTER_VALIDATE_BOOLEAN)
		);
	}//end fromArguments()

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
