<?php

/**
 * Integriq VendorOperationCopy.
 *
 * Copies the vendor operation an imported endpoint proxies to into a
 * published document, with the vendor components it references renamed under
 * a per-source prefix so two vendors never collide.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenApi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenApi;

use OCA\Integriq\Service\Endpoint\UpstreamPathTemplate;

/**
 * Copies a vendor operation and its components into a published document.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
 */
class VendorOperationCopy {

	/**
	 * The component kinds a vendor operation may reference.
	 *
	 * @var array<int,string>
	 */
	private const COMPONENT_KINDS = ['schemas', 'parameters', 'responses', 'requestBodies', 'headers', 'examples'];

	/**
	 * The vendor operation an imported endpoint proxies to, its components copied into the document.
	 *
	 * @param array<string,mixed> $document The document being built; gains the vendor components.
	 * @param array<string,mixed> $data     The endpoint's object data.
	 * @param array<string,mixed> $vendor   The source's vendor document, OpenAPI 3.x shape.
	 * @param string              $sourceId The source id, for the component prefix.
	 *
	 * @return array<string,mixed>|null The operation, or null when the vendor document lacks it.
	 *
	 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
	 */
	public function copy(array &$document, array $data, array $vendor, string $sourceId): ?array {
		$method = strtolower((string) ($data['method'] ?? ''));
		// An imported endpoint keeps the vendor path in `upstreamPath`; its
		// `endpoint` carries the gateway prefix the vendor document lacks.
		$wanted    = self::shape(path: (new UpstreamPathTemplate())->forEndpoint(endpointData: $data));
		$operation = self::vendorOperation(vendor: $vendor, method: $method, wanted: $wanted);
		if ($operation === null) {
			return null;
		}

		$prefix = self::componentPrefix(sourceId: $sourceId);
		unset($operation['servers'], $operation['security'], $operation['operationId'], $operation['callbacks']);
		$this->copyComponents(document: $document, vendor: $vendor, prefix: $prefix);

		return self::prefixRefs(value: $operation, prefix: $prefix);
	}//end copy()

	/**
	 * The vendor document's operation for a method on a path shape, with its path item's shared parameters first.
	 *
	 * @param array<string,mixed> $vendor The vendor document.
	 * @param string              $method The lower-case HTTP method.
	 * @param string              $wanted The path shape.
	 *
	 * @return array<string,mixed>|null The operation, or null when the document has none.
	 */
	private static function vendorOperation(array $vendor, string $method, string $wanted): ?array {
		foreach ((array) ($vendor['paths'] ?? []) as $path => $item) {
			if (is_array($item) === false || is_array($item[$method] ?? null) === false || self::shape(path: (string) $path) !== $wanted) {
				continue;
			}

			$operation = $item[$method];
			$shared = array_values(array_filter((array) ($item['parameters'] ?? []), 'is_array'));
			if ($shared !== []) {
				$operation['parameters'] = array_merge($shared, (array) ($operation['parameters'] ?? []));
			}

			return $operation;
		}

		return null;
	}//end vendorOperation()

	/**
	 * Copy a vendor document's components into the published document, under the source's prefix.
	 *
	 * @param array<string,mixed> $document The document being built.
	 * @param array<string,mixed> $vendor   The vendor document.
	 * @param string              $prefix   The source's component prefix.
	 *
	 * @return void
	 */
	private function copyComponents(array &$document, array $vendor, string $prefix): void {
		foreach (self::COMPONENT_KINDS as $kind) {
			foreach ((array) ($vendor['components'][$kind] ?? []) as $name => $component) {
				$document['components'][$kind][$prefix.$name] = self::prefixRefs(value: $component, prefix: $prefix);
			}
		}
	}//end copyComponents()

	/**
	 * Point a vendor component reference at its prefixed copy.
	 *
	 * @param mixed  $value  Any part of a vendor document.
	 * @param string $prefix The source's component prefix.
	 *
	 * @return mixed The part with its references prefixed.
	 */
	private static function prefixRefs(mixed $value, string $prefix): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			if ($key === '$ref' && is_string($item) === true) {
				$value[$key] = (string) preg_replace('#^\#/components/([A-Za-z]+)/#', '#/components/$1/'.$prefix, $item);
				continue;
			}

			$value[$key] = self::prefixRefs(value: $item, prefix: $prefix);
		}

		return $value;
	}//end prefixRefs()

	/**
	 * The component prefix of a source's copied components.
	 *
	 * @param string $sourceId The source uuid.
	 *
	 * @return string The prefix.
	 */
	private static function componentPrefix(string $sourceId): string {
		return 'source-'.substr((string) preg_replace('/[^A-Za-z0-9]/', '', $sourceId), 0, 8).'.';
	}//end componentPrefix()

	/**
	 * A path's shape: no outer slashes, every placeholder the same.
	 *
	 * @param string $path The path.
	 *
	 * @return string The shape.
	 */
	private static function shape(string $path): string {
		return strtolower((string) preg_replace('#\{\{?\s*[^}]+\s*\}?\}#', '{}', trim($path, '/')));
	}//end shape()
}//end class
