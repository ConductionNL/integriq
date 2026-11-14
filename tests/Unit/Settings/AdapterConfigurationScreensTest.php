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
			$pages[$page['config']['schema'] . ':' . $page['type']] = [$page['permission'], $page['config']['register']];
		}

		ksort($pages);
		$this->assertSame(
			expected: [
				'bankfeed_connection:index' => ['admin', 'integriq'],
				'cardfeed_account:index' => ['admin', 'integriq'],
				'fsc_service:index' => ['admin', 'integriq'],
				'lti_platform:detail' => ['admin', 'integriq'],
				'lti_platform:index' => ['admin', 'integriq'],
				'lti_tool:detail' => ['admin', 'integriq'],
				'lti_tool:index' => ['admin', 'integriq'],
				'openformulieren_form_mapping:index' => ['admin', 'integriq'],
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
			foreach (array_merge(($page['config']['columns'] ?? []), ($page['config']['includeFields'] ?? [])) as $column) {
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
				'LtiPlatforms' => 'GatewayGroup',
				'LtiTools' => 'GatewayGroup',
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

	/**
	 * The LTI pages of one registration type.
	 *
	 * @param string $schema `lti_platform` or `lti_tool`.
	 *
	 * @return array<string, array<string, mixed>> The index and detail page, by type.
	 */
	private static function ltiPages(string $schema): array {
		$pages = [];
		foreach (self::fragment()['pages'] as $page) {
			if ($page['config']['schema'] === $schema) {
				$pages[$page['type']] = $page;
			}
		}

		return $pages;
	}//end ltiPages()

	/**
	 * The four lifecycle actions sit on the row and in the detail header of the
	 * record they act on, call the route that exists for them, and only
	 * suspend and rotate key confirm (proposal, Answers 1).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-an-administrative-action-with-a-route-has-a-control-req-acs-002
	 */
	public function testTheLifecycleActionsSitOnTheRecordTheyActOn(): void {
		$routes = (string)file_get_contents(self::ROOT . '/appinfo/routes.php');
		foreach (['approve' => '/approve', 'suspend' => '/suspend', 'generateKey' => '/keys/generate', 'rotateKey' => '/keys/rotate'] as $method => $tail) {
			$this->assertStringContainsString("['name' => 'lti#" . $method . "', 'url' => '/api/lti/{registrationType}/{registrationUuid}" . $tail . "', 'verb' => 'POST']", $routes);
		}

		foreach (['lti_platform', 'lti_tool'] as $schema) {
			$pages = self::ltiPages(schema: $schema);
			$surfaces = [
				'row' => array_values(array_filter($pages['index']['config']['actions'], static fn (array $a): bool => ($a['type'] ?? '') === 'api-call')),
				'header' => $pages['detail']['config']['headerActions'],
			];
			foreach ($surfaces as $where => $actions) {
				$byId = array_column($actions, null, 'id');
				$this->assertSame(['approve', 'suspend', 'generate-key', 'rotate-key'], array_keys($byId), $schema . ' ' . $where);
				$base = '/apps/integriq/api/lti/' . $schema . '/@objectId';
				$this->assertSame(
					[$base . '/approve', $base . '/suspend', $base . '/keys/generate', $base . '/keys/rotate'],
					array_column($actions, 'url')
				);
				$this->assertSame(['POST'], array_values(array_unique(array_column($actions, 'method'))));
				$confirming = array_keys(array_filter($byId, static fn (array $a): bool => ($a['confirm'] ?? false) === true));
				$this->assertSame(['suspend', 'rotate-key'], $confirming, $schema . ' ' . $where . ': only the actions that cut something off confirm');
				foreach ($confirming as $id) {
					$this->assertStringNotContainsStringIgnoringCase('are you sure', $byId[$id]['confirmMessage']);
					$this->assertMatchesRegularExpression('/(stops|refused|fail)/', $byId[$id]['confirmMessage'], 'The dialog names the consequence.');
				}

				foreach ($actions as $action) {
					$this->assertArrayNotHasKey('errorMessage', $action, 'A refusal shows the server\'s own reason.');
				}

				$this->assertSame(['field' => 'status', 'op' => 'neq', 'value' => 'approved'], $byId['approve']['visibleWhen']);
				$this->assertSame(['field' => 'status', 'op' => 'eq', 'value' => 'approved'], $byId['suspend']['visibleWhen']);
			}

			$this->assertSame($pages['detail']['id'], $pages['index']['config']['actions'][0]['route'], 'A row opens its detail page.');
		}
	}//end testTheLifecycleActionsSitOnTheRecordTheyActOn()

	/**
	 * The write-only signing keys and the status are never in a form: the key
	 * actions write the keys, approve and suspend write the status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-every-configuration-record-an-administrator-owns-has-a-screen-req-acs-001
	 */
	public function testTheFormsLeaveTheSecretsAndTheStatusOut(): void {
		foreach (['lti_platform', 'lti_tool'] as $schema) {
			$writeOnly = json_decode((string)file_get_contents(self::ROOT . '/lib/Settings/register.d/99-' . str_replace('_', '-', $schema) . '-secrets-writeonly.json'), true);
			$this->assertTrue($writeOnly['components']['schemas'][$schema]['properties']['signingKeys']['writeOnly']);
			foreach (self::ltiPages(schema: $schema) as $page) {
				$this->assertNotContains('signingKeys', $page['config']['includeFields']);
				$this->assertNotContains('status', $page['config']['includeFields']);
				$this->assertNotContains('signingKeys', ($page['config']['columns'] ?? []));
			}
		}
	}//end testTheFormsLeaveTheSecretsAndTheStatusOut()

	/**
	 * Every widget a page declares has a layout entry, and every layout entry
	 * names a declared widget; every action icon is registered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-a-declared-screen-is-proven-to-render-req-acs-003
	 */
	public function testEveryWidgetIsLaidOutAndEveryIconRegistered(): void {
		$icons = (string)file_get_contents(self::ROOT . '/src/icons.js');
		foreach (self::fragment()['pages'] as $page) {
			$config = $page['config'];
			$widgets = array_column(($config['widgets'] ?? []), 'id');
			$laidOut = array_column(($config['layout'] ?? []), 'widgetId');
			sort($widgets);
			sort($laidOut);
			$this->assertSame($widgets, $laidOut, $page['id']);

			$actions = array_merge(($config['actions'] ?? []), ($config['headerActions'] ?? []));
			foreach (array_merge(array_column($actions, 'icon'), array_column(($config['widgets'] ?? []), 'icon')) as $icon) {
				$this->assertMatchesRegularExpression('/^import ' . $icon . " from 'vue-material-design-icons\\/" . $icon . "\\.vue'/m", $icons, $icon);
			}
		}
	}//end testEveryWidgetIsLaidOutAndEveryIconRegistered()

	/**
	 * Every string the LTI pages show has a Dutch value that is not the English repeated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-a-declared-screen-is-proven-to-render-req-acs-003
	 */
	public function testEveryLtiStringIsTranslated(): void {
		$dutch = json_decode((string)file_get_contents(self::ROOT . '/l10n/nl.json'), true)['translations'];
		$strings = [];
		foreach (['lti_platform', 'lti_tool'] as $schema) {
			foreach (self::ltiPages(schema: $schema) as $page) {
				$strings[] = $page['title'];
				foreach (array_merge(($page['config']['actions'] ?? []), ($page['config']['headerActions'] ?? [])) as $action) {
					foreach (['label', 'confirmTitle', 'confirmMessage', 'confirmLabel', 'successMessage'] as $key) {
						if (isset($action[$key]) === true) {
							$strings[] = $action[$key];
						}
					}
				}

				foreach (($page['config']['widgets'] ?? []) as $widget) {
					$strings[] = $widget['title'];
				}
			}
		}

		foreach (array_unique($strings) as $string) {
			$this->assertArrayHasKey(key: $string, array: $dutch);
			$this->assertNotSame(expected: $string, actual: $dutch[$string], message: $string);
		}
	}//end testEveryLtiStringIsTranslated()
}//end class
