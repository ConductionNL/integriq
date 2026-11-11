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
			$cards[] = self::cardOf(meta: $template['x-template'], source: $template['source']);
		}

		return $cards;
	}//end cards()

	/**
	 * One template as a catalogue card.
	 *
	 * @param array<string, mixed> $meta   The template's `x-template` block.
	 * @param array<string, mixed> $source The template's source.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	private static function cardOf(array $meta, array $source): array {
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

		return $card + self::snapshotOf(meta: $meta);
	}//end cardOf()

	/**
	 * The card's snapshot date, when the template records one.
	 *
	 * @param array<string, mixed> $meta The template's `x-template` block.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	private static function snapshotOf(array $meta): array {
		if (empty($meta['snapshotDate']) === true) {
			return [];
		}

		return ['snapshotDate' => (string)$meta['snapshotDate']];
	}//end snapshotOf()

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
			if (self::isTemplate(data: $data) === true) {
				$templates[] = $data;
			}
		}

		return $templates;
	}//end templates()

	/**
	 * Whether a decoded file is a template: the allow-list and the snapshot index are not.
	 *
	 * @param mixed $data The decoded file.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	private static function isTemplate(mixed $data): bool {
		return is_array($data) === true
			&& is_array($data['x-template'] ?? null) === true
			&& is_array($data['source'] ?? null) === true
			&& (string)($data['x-template']['slug'] ?? '') !== '';
	}//end isTemplate()
}//end class
