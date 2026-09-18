<?php

/**
 * The Objecttypen API v2 read paths: list, read, and one version.
 *
 * 🔑 THE `jsonSchema` IS RENDERED FROM THE OPENREGISTER SCHEMA AT READ TIME,
 * never stored beside the objecttype. A stored copy is a second source of truth
 * for the same fact, and the failure is the quiet kind: an administrator edits
 * the schema, the API keeps serving last month's shape, and a counterparty
 * validates against it and writes something the register then refuses.
 *
 * 🔴 AN UNLISTED VERSION IS A 404, NOT THE NEWEST ONE. Answering with a newer
 * version answers a different question than the consumer asked, and they have
 * no way to tell: the body looks like a valid objecttype.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
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
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Objecten;

use Throwable;

/**
 * Serves the three Objecttypen API read routes.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
class ObjecttypeEndpointHandler {

	/**
	 * Constructor.
	 *
	 * @param ObjecttypeRegistry $objecttypes The declared mappings.
	 * @param callable|null      $schemaRead  Resolves (register, schema) to the stored schema.
	 */
	public function __construct(
		private readonly ObjecttypeRegistry $objecttypes,
		private $schemaRead = null,
	) {
	}//end __construct()

	/**
	 * `GET /api/v2/objecttypes`.
	 *
	 * @param string $baseUrl The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function index(string $baseUrl = ''): array {
		$results = [];
		foreach ($this->objecttypes->all() as $declaration) {
			$results[] = $this->render(declaration: $declaration, baseUrl: $baseUrl);
		}

		return [
			'status' => 200,
			// The standard's list envelope. `count` is the total and not the
			// page size: a consumer paging through reads it to know when to
			// stop, and answering the page size makes every list look like one
			// page.
			'body' => ['count' => count($results), 'next' => null, 'previous' => null, 'results' => $results],
		];
	}//end index()

	/**
	 * `GET /api/v2/objecttypes/{uuid}`.
	 *
	 * @param string $uuid    The objecttype.
	 * @param string $baseUrl The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function show(string $uuid, string $baseUrl = ''): array {
		$declaration = $this->objecttypes->find(uuid: $uuid);
		if ($declaration === null) {
			return $this->notFound(detail: sprintf('No objecttype with uuid "%s" is published here.', $uuid));
		}

		return ['status' => 200, 'body' => $this->render(declaration: $declaration, baseUrl: $baseUrl)];
	}//end show()

	/**
	 * `GET /api/v2/objecttypes/{uuid}/versions/{version}`.
	 *
	 * @param string $uuid    The objecttype.
	 * @param string $version The version.
	 * @param string $baseUrl The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function version(string $uuid, string $version, string $baseUrl = ''): array {
		$declaration = $this->objecttypes->find(uuid: $uuid);
		if ($declaration === null) {
			return $this->notFound(detail: sprintf('No objecttype with uuid "%s" is published here.', $uuid));
		}

		if ($this->objecttypes->allowsVersion(uuid: $uuid, version: $version) === false) {
			return $this->notFound(
				detail: sprintf(
					'Version "%s" of objecttype "%s" is not published. The published versions are: %s.',
					$version,
					$uuid,
					implode(', ', $declaration['versions'])
				)
			);
		}

		$schema = $this->schemaFor(declaration: $declaration);

		return [
			'status' => 200,
			'body' => [
				'url' => $this->urlFor(uuid: $uuid, baseUrl: $baseUrl) . '/versions/' . $version,
				'version' => $version,
				'objectType' => $this->urlFor(uuid: $uuid, baseUrl: $baseUrl),
				'status' => 'published',
				'jsonSchema' => $schema,
				'createdAt' => null,
				'modifiedAt' => null,
				'publishedAt' => null,
			],
		];
	}//end version()

	/**
	 * One objecttype in the standard's shape.
	 *
	 * 🔴 THE REGISTER AND SCHEMA NAMES DO NOT GO OUT. They are OpenRegister's
	 * internal addressing, and a VNG consumer neither expects them nor should
	 * learn them: the published uuid is the whole of what they address.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 * @param string               $baseUrl     The API base.
	 *
	 * @return array<string, mixed> The objecttype.
	 */
	private function render(array $declaration, string $baseUrl): array {
		$uuid = (string)$declaration['uuid'];
		$versions = $declaration['versions'];

		return [
			'url' => $this->urlFor(uuid: $uuid, baseUrl: $baseUrl),
			'uuid' => $uuid,
			'name' => (string)$declaration['name'],
			'namePlural' => (string)$declaration['name'],
			'dataClassification' => 'open',
			'versions' => array_map(
				fn (string $version): string => ($this->urlFor(uuid: $uuid, baseUrl: $baseUrl) . '/versions/' . $version),
				$versions
			),
		];
	}//end render()

	/**
	 * The stored schema for a declaration, or an empty schema.
	 *
	 * An unreadable schema answers an EMPTY object rather than a fabricated
	 * one: a consumer validating against `{}` accepts everything and finds out
	 * at the write, which is a refusal they can act on. A fabricated schema
	 * would have them validate against something this instance never promised.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schemaFor(array $declaration): array {
		if ($this->schemaRead === null) {
			return [];
		}

		try {
			$schema = ($this->schemaRead)((string)$declaration['register'], (string)$declaration['schema']);
		} catch (Throwable $e) {
			return [];
		}

		if (is_array($schema) === false) {
			return [];
		}

		return $schema;
	}//end schemaFor()

	/**
	 * The canonical url of one objecttype.
	 *
	 * @param string $uuid    The uuid.
	 * @param string $baseUrl The base.
	 *
	 * @return string The url.
	 */
	private function urlFor(string $uuid, string $baseUrl): string {
		if ($baseUrl === '') {
			return '';
		}

		return (rtrim($baseUrl, '/') . '/objecttypes/' . $uuid);
	}//end urlFor()

	/**
	 * A 404 in the standard's problem shape.
	 *
	 * @param string $detail What was not found.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 */
	private function notFound(string $detail): array {
		return [
			'status' => 404,
			'body' => ['type' => 'about:blank', 'title' => 'Not found', 'status' => 404, 'detail' => $detail],
		];
	}//end notFound()
}//end class
