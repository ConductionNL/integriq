<?php

/**
 * Unit tests: every manifest page is declared once across the base manifest and its fragments.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-an-administrative-action-with-a-route-has-a-control-req-acs-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The pages the app renders are src/manifest.json's plus every src/manifest.d fragment's.
 *
 * buildManifest() merges the fragments in file-name order and a page whose id
 * is already known REPLACES the earlier one without a word. So a page id
 * declared twice is a page whose first definition never renders, while a
 * test that reads only that one fragment keeps passing.
 */
class ManifestFragmentPagesTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * Every manifest file in merge order: the base, then the fragments sorted by name.
	 *
	 * @return array<string,array<string,mixed>> The parsed files, keyed by path relative to the root.
	 */
	private static function manifestFiles(): array {
		$fragments = glob(self::ROOT . '/src/manifest.d/*.json');
		sort($fragments);
		$files = [];
		foreach (array_merge([self::ROOT . '/src/manifest.json'], $fragments) as $path) {
			$files[substr($path, strlen(self::ROOT) + 1)] = json_decode((string)file_get_contents($path), true);
		}

		return $files;
	}//end manifestFiles()

	/**
	 * The pages as the app renders them, merged the way buildManifest() merges them.
	 *
	 * @return array<string,array<string,mixed>> The pages, keyed by id.
	 */
	private static function mergedPages(): array {
		$pages = [];
		foreach (self::manifestFiles() as $manifest) {
			foreach (($manifest['pages'] ?? []) as $page) {
				$pages[(string)$page['id']] = $page;
			}
		}

		return $pages;
	}//end mergedPages()

	/**
	 * No page id is declared in two manifest files, or twice in one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-an-administrative-action-with-a-route-has-a-control-req-acs-002
	 */
	public function testEveryPageIdIsDeclaredOnce(): void {
		$declaredIn = [];
		foreach (self::manifestFiles() as $file => $manifest) {
			foreach (($manifest['pages'] ?? []) as $page) {
				$declaredIn[(string)$page['id']][] = $file;
			}
		}

		$this->assertGreaterThan(50, count($declaredIn), 'the manifest files must declare pages for this check to mean anything');
		$duplicates = array_filter($declaredIn, static fn (array $files): bool => count($files) > 1);
		$this->assertSame([], $duplicates, 'page ids declared more than once: only the last definition renders');
	}//end testEveryPageIdIsDeclaredOnce()

	/**
	 * The LTI tool page that renders has the lifecycle actions and the platform details panel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-an-administrative-action-with-a-route-has-a-control-req-acs-002
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
	 */
	public function testTheRenderedLtiToolPageHasItsActionsAndThePlatformDetails(): void {
		$pages = self::mergedPages();

		$index = array_column(($pages['LtiTools']['config']['actions'] ?? []), 'id');
		$detail = array_column(($pages['LtiToolDetail']['config']['headerActions'] ?? []), 'id');
		foreach (['approve', 'suspend', 'generate-key', 'rotate-key'] as $action) {
			$this->assertContains($action, $index, 'row action ' . $action . ' on LtiTools');
			$this->assertContains($action, $detail, 'header action ' . $action . ' on LtiToolDetail');
		}

		$panels = array_column(($pages['LtiToolDetail']['config']['bodyWidgets'] ?? []), 'component');
		$this->assertSame(['LtiPlatformDetails'], $panels);
	}//end testTheRenderedLtiToolPageHasItsActionsAndThePlatformDetails()
}//end class
