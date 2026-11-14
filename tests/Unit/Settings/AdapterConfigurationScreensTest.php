<?php

/**
 * Unit tests: the adapter configuration index pages name declared columns and registered icons.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-every-configuration-record-an-administrator-owns-has-a-screen-req-acs-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The fragment src/manifest.d/adapter-configuration-screens.json against the register and icons.js.
 */
class AdapterConfigurationScreensTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The fragment.
	 *
	 * @return array<string,mixed>
	 */
	private static function fragment(): array {
		return json_decode((string)file_get_contents(self::ROOT . '/src/manifest.d/adapter-configuration-screens.json'), true);
	}//end fragment()

	/**
	 * Declared properties per schema slug, from the register and its fragments.
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function schemaProperties(): array {
		$files = array_merge([self::ROOT . '/lib/Settings/integriq_register.json'], glob(self::ROOT . '/lib/Settings/register.d/*.json'));
		$properties = [];
		foreach ($files as $file) {
			$register = json_decode((string)file_get_contents($file), true);
			foreach (($register['components']['schemas'] ?? []) as $slug => $schema) {
				$properties[$slug] = array_merge($properties[$slug] ?? [], array_keys($schema['properties'] ?? []));
			}
		}

		return $properties;
	}//end schemaProperties()

	/**
	 * The four configurations each have an admin index page over their schema.
	 *
	 * @return void
	 */
	public function testEachConfigurationHasAnAdminIndexPage(): void {
		$pages = [];
		foreach (self::fragment()['pages'] as $page) {
			$pages[$page['config']['schema']] = [$page['type'], $page['permission'], $page['config']['register']];
		}

		ksort($pages);
		$this->assertSame(
			expected: [
				'bankfeed_connection' => ['index', 'admin', 'integriq'],
				'cardfeed_account' => ['index', 'admin', 'integriq'],
				'fsc_service' => ['index', 'admin', 'integriq'],
				'openformulieren_form_mapping' => ['index', 'admin', 'integriq'],
			],
			actual: $pages
		);
	}//end testEachConfigurationHasAnAdminIndexPage()

	/**
	 * Every column is a property its schema declares, so no column renders blank.
	 *
	 * @return void
	 */
	public function testEveryColumnIsADeclaredProperty(): void {
		$properties = self::schemaProperties();
		$undeclared = [];
		foreach (self::fragment()['pages'] as $page) {
			$schema = $page['config']['schema'];
			foreach ($page['config']['columns'] as $column) {
				if (in_array($column, $properties[$schema] ?? [], true) === false) {
					$undeclared[] = $schema . '.' . $column;
				}
			}
		}

		$this->assertSame(expected: [], actual: $undeclared);
	}//end testEveryColumnIsADeclaredProperty()

	/**
	 * Every menu entry routes to a page of the fragment, sits in a direction group, and has a registered icon.
	 *
	 * @return void
	 */
	public function testEveryMenuEntryIsRoutedAndHasARegisteredIcon(): void {
		$fragment = self::fragment();
		$pageIds = array_column($fragment['pages'], 'id');
		$icons = (string)file_get_contents(self::ROOT . '/src/icons.js');
		$entries = [];
		foreach ($fragment['menu'] as $group) {
			foreach ($group['children'] as $entry) {
				$entries[$entry['id']] = $group['id'];
				$this->assertContains(needle: $entry['route'], haystack: $pageIds);
				$this->assertMatchesRegularExpression('/^import ' . $entry['icon'] . " from 'vue-material-design-icons\\//m", $icons);
			}
		}

		ksort($entries);
		$this->assertSame(
			expected: [
				'BankfeedConnections' => 'ConnectionsGroup',
				'CardfeedAccounts' => 'ConnectionsGroup',
				'FscServices' => 'ConnectionsGroup',
				'OpenFormulierenForms' => 'GatewayGroup',
			],
			actual: $entries
		);
	}//end testEveryMenuEntryIsRoutedAndHasARegisteredIcon()

	/**
	 * Every page title has a Dutch value that is not the English repeated.
	 *
	 * @return void
	 */
	public function testEveryTitleIsTranslated(): void {
		$dutch = json_decode((string)file_get_contents(self::ROOT . '/l10n/nl.json'), true)['translations'];
		foreach (self::fragment()['pages'] as $page) {
			$this->assertArrayHasKey(key: $page['title'], array: $dutch);
			$this->assertNotSame(expected: $page['title'], actual: $dutch[$page['title']]);
		}
	}//end testEveryTitleIsTranslated()
}//end class
