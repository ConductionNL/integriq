<?php

/**
 * Seed Fragments
 *
 * Reads the `register.d/*.json` seed fragments the connector catalog lists
 * its source templates from: which files there are, their decoded body, the
 * objects they ship, and one Source seed's raw payload.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * The seed fragments of one directory; built by CatalogRegistryService.
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
 */
final class SeedFragments {

	/**
	 * Constructor.
	 *
	 * @param string $directory The directory holding the fragments.
	 */
	public function __construct(
		private readonly string $directory,
	) {

	}//end __construct()

	/**
	 * The fragment files, or none when the directory or the glob fails.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	public function files(): array {
		if (is_dir($this->directory) === false) {
			return [];
		}

		$files = glob($this->directory . '/*.json');
		if ($files === false) {
			return [];
		}

		return $files;

	}//end files()

	/**
	 * A fragment's decoded body, or null when it is not a JSON object or array.
	 *
	 * @param string $raw The fragment's contents.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	public function decode(string $raw): ?array {
		$data = json_decode($raw, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
			return null;
		}

		return $data;

	}//end decode()

	/**
	 * The objects a fragment ships, or none.
	 *
	 * @param array<string,mixed> $data The decoded fragment.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	public function objects(array $data): array {
		$objects = ($data['components']['objects'] ?? []);
		if (is_array($objects) === false) {
			return [];
		}

		return $objects;

	}//end objects()

	/**
	 * The raw payload of the Source seed with this slug among a fragment's objects, or null.
	 *
	 * @param array<mixed> $objects The fragment's objects.
	 * @param string       $slug    The source template's slug.
	 *
	 * @return array<string,mixed>|null The payload, with its slug and without `@self`.
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#scenario-instantiate-action-creates-a-source-from-a-seeded-template
	 */
	public function sourcePayload(array $objects, string $slug): ?array {
		foreach ($objects as $object) {
			$self = ($object['@self'] ?? []);
			if (($self['schema'] ?? '') === 'source' && ($self['slug'] ?? '') === $slug) {
				$payload = $object;
				$payload['slug'] = $slug;
				unset($payload['@self']);
				return $payload;
			}
		}

		return null;

	}//end sourcePayload()
}//end class
