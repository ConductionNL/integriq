<?php

/**
 * Turns a pinned snapshot of the APIs.guru OpenAPI directory into generated
 * connector templates, for the entries on a reviewed allow-list.
 *
 * Build-time only: nothing at runtime reads the directory (design D3).
 *
 * @category Script
 * @package  OCA\Integriq\Scripts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-generated-saas-templates-come-from-a-pinned-directory-and-a-reviewed-allow-list-req-ccx-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Scripts;

/**
 * Pure: arrays in, arrays out. The CLI in generate-connector-templates.php
 * does the reading and writing.
 */
class ConnectorTemplateGenerator {

	/**
	 * Build one template per allow-listed entry the snapshot carries.
	 *
	 * @param array<string, array> $index        The trimmed directory index, keyed by APIs.guru key.
	 * @param array<string, array> $specs        The trimmed OpenAPI description per key.
	 * @param array<int, array>    $allowList    Entries {key, slug, name?, category?}.
	 * @param string               $snapshotDate The date the snapshot was taken (Y-m-d).
	 *
	 * @return array<string, array> Templates keyed by slug; an allow-listed key missing from the snapshot is left out.
	 */
	public function generate(array $index, array $specs, array $allowList, string $snapshotDate): array {
		$templates = [];
		foreach ($allowList as $allowed) {
			$key = (string)($allowed['key'] ?? '');
			$slug = (string)($allowed['slug'] ?? '');
			if ($key === '' || $slug === '' || isset($index[$key]) === false || isset($specs[$key]) === false) {
				continue;
			}

			$entry = $index[$key];
			$spec = $specs[$key];
			$title = (string)($allowed['name'] ?? $spec['info']['title'] ?? $entry['title'] ?? $slug);
			$docs = (string)($spec['externalDocs']['url'] ?? '');
			$auth = $this->authScheme(schemes: ($spec['components']['securitySchemes'] ?? []));

			$description = $title . ', generated from the APIs.guru directory snapshot of ' . $snapshotDate
				. '. A starting point: check the base URL and the scopes before use. Authentication: ' . $auth['label'] . '.'
				. ' Hold the credential in the credential broker and name it by credentialRef; this template carries none.';
			if ($docs !== '') {
				$description .= ' Documentation: ' . $docs;
			}

			$templates[$slug] = [
				'x-template' => [
					'slug' => $slug,
					'vendor' => (string)($spec['info']['x-providerName'] ?? explode(':', $key)[0]),
					'system' => $title,
					'standard' => 'OpenAPI ' . (string)($spec['openapi'] ?? '3'),
					'verifiedAgainst' => (string)$entry['swaggerUrl'],
					'tier' => 'generated',
					'snapshotDate' => $snapshotDate,
					'category' => (string)($allowed['category'] ?? $this->category(categories: ($entry['categories'] ?? []))),
					'directoryKey' => $key,
				],
				'source' => [
					'name' => $title,
					'description' => $description,
					'type' => 'api',
					'location' => rtrim((string)($spec['servers'][0]['url'] ?? ''), '/'),
					'auth' => $auth['auth'],
					'configuration' => $auth['configuration'],
					'isEnabled' => false,
				],
			];
		}//end foreach

		ksort($templates);
		return $templates;
	}//end generate()

	/**
	 * Keep only what a template reads from a full OpenAPI description.
	 *
	 * @param array $spec A full OpenAPI 3 description.
	 *
	 * @return array The trimmed description: version, info, docs, servers and the security schemes without their scope lists.
	 */
	public function trimSpec(array $spec): array {
		$schemes = [];
		foreach (($spec['components']['securitySchemes'] ?? []) as $name => $scheme) {
			foreach (($scheme['flows'] ?? []) as $flow => $settings) {
				unset($settings['scopes']);
				$scheme['flows'][$flow] = $settings;
			}

			unset($scheme['description']);
			$schemes[$name] = $scheme;
		}

		return [
			'openapi' => (string)($spec['openapi'] ?? ''),
			'info' => array_intersect_key(($spec['info'] ?? []), array_flip(['title', 'version', 'x-providerName', 'x-apisguru-categories'])),
			'externalDocs' => ($spec['externalDocs'] ?? []),
			'servers' => ($spec['servers'] ?? []),
			'components' => ['securitySchemes' => $schemes],
		];
	}//end trimSpec()

	/**
	 * Map the first security scheme onto integriq's source auth strategy.
	 *
	 * @param array $schemes The OpenAPI security schemes.
	 *
	 * @return array{auth: string, label: string, configuration: array}
	 */
	private function authScheme(array $schemes): array {
		foreach ($schemes as $scheme) {
			$type = (string)($scheme['type'] ?? '');
			if ($type === 'oauth2') {
				$flows = ($scheme['flows'] ?? []);
				$tokenUrl = '';
				foreach ($flows as $flow) {
					$tokenUrl = (string)($flow['tokenUrl'] ?? $tokenUrl);
				}

				return [
					'auth' => 'oauth',
					'label' => 'OAuth 2.0 (' . implode(', ', array_keys($flows)) . ')',
					'configuration' => $tokenUrl === '' ? [] : ['authentication' => ['tokenUrl' => $tokenUrl]],
				];
			}

			if ($type === 'apiKey') {
				return [
					'auth' => 'apikey',
					'label' => 'API key in ' . (string)($scheme['in'] ?? 'header') . ' ' . (string)($scheme['name'] ?? ''),
					'configuration' => [],
				];
			}

			if ($type === 'http') {
				$basic = strtolower((string)($scheme['scheme'] ?? '')) === 'basic';
				return [
					'auth' => $basic === true ? 'basic' : 'jwt',
					'label' => 'HTTP ' . (string)($scheme['scheme'] ?? 'bearer'),
					'configuration' => [],
				];
			}
		}//end foreach

		return ['auth' => 'none', 'label' => 'none declared', 'configuration' => []];
	}//end authScheme()

	/**
	 * A Store category from the directory's own tags.
	 *
	 * @param array<int, string> $categories The x-apisguru-categories.
	 *
	 * @return string
	 */
	private function category(array $categories): string {
		$first = (string)($categories[0] ?? '');
		if ($first === '') {
			return 'Business software';
		}

		return ucfirst($first);
	}//end category()
}//end class
