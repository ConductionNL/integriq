<?php

/**
 * Reads the connector template library the Store lists without installing.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Catalog
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Catalog;

/**
 * One JSON file per template under `<directory>/<set>/`: a `source` payload
 * plus an `x-template` block naming the vendor, the system, the standard,
 * where it was checked and its tier (connectors-catalogue-expansion D1).
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
 */
class ConnectorTemplateLibrary {

	/**
	 * Constructor.
	 *
	 * @param string $directory The library root.
	 */
	public function __construct(
		private readonly string $directory,
	) {
	}//end __construct()

	/**
	 * One Store card per template, with the source type for the icon.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	public function cards(): array {
		$cards = [];
		foreach ($this->templates() as $template) {
			$meta = $template['x-template'];
			$source = $template['source'];
			$slug = (string)$meta['slug'];

			$card = [
				'slug' => 'template:' . $slug,
				'name' => (string)($source['name'] ?? $meta['system'] ?? $slug),
				'description' => (string)($source['description'] ?? ''),
				'category' => (string)($meta['category'] ?? 'Integrations'),
				'kind' => 'source-template',
				'mechanism' => 'mock-seeded',
				'flagKey' => '',
				'sourceTemplateSlug' => $slug,
				'standards' => [(string)($meta['standard'] ?? 'REST API')],
				'sourceType' => (string)($source['type'] ?? 'api'),
				'tier' => (string)($meta['tier'] ?? 'curated'),
				'verifiedAgainst' => (string)($meta['verifiedAgainst'] ?? ''),
			];
			if (empty($meta['snapshotDate']) === false) {
				$card['snapshotDate'] = (string)$meta['snapshotDate'];
			}

			$cards[] = $card;
		}//end foreach

		return $cards;
	}//end cards()

	/**
	 * The source payload Instantiate creates for a template, or null.
	 *
	 * @param string $slug The template slug.
	 *
	 * @return array<string, mixed>|null The payload with its slug, without the template block.
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	public function payload(string $slug): ?array {
		foreach ($this->templates() as $template) {
			if ((string)$template['x-template']['slug'] === $slug) {
				$payload = $template['source'];
				$payload['slug'] = $slug;
				return $payload;
			}
		}

		return null;
	}//end payload()

	/**
	 * Every well-formed template, one folder deep, in file order.
	 *
	 * @return array<int, array{x-template: array, source: array}>
	 */
	private function templates(): array {
		$files = glob($this->directory . '/*/*.json');
		if ($files === false) {
			return [];
		}

		sort($files);

		$templates = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			// The allow-list and the snapshot index are not templates.
			if (is_array($data) === false
				|| is_array($data['x-template'] ?? null) === false
				|| is_array($data['source'] ?? null) === false
				|| (string)($data['x-template']['slug'] ?? '') === ''
			) {
				continue;
			}

			$templates[] = $data;
		}

		return $templates;
	}//end templates()
}//end class
