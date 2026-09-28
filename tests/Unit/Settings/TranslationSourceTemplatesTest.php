<?php

/**
 * Contract tests for the seeded translation source templates.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-translation-service/specs/translation-service/spec.md#requirement-translation-providers-are-source-templates-req-trl-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Repair\InitializeRegister;
use OCA\Integriq\Service\TranslationService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * Each template validates against the real `source` schema, ships disabled,
 * names the provider shape TranslationService reads, and sits in the catalogue.
 */
class TranslationSourceTemplatesTest extends TestCase {

	/**
	 * Template slug to fragment file and provider shape.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function templates(): array {
		return [
			'deepl-translation' => ['deepl-translation', 'deepl-translation-source.json', 'deepl'],
			'libretranslate'    => ['libretranslate', 'libretranslate-source.json', 'libretranslate'],
		];
	}//end templates()

	/**
	 * The register as InitializeRegister imports it: base plus every fragment.
	 *
	 * @return array<string, mixed>
	 */
	private static function mergedRegister(): array {
		$root = dirname(__DIR__, 3);
		$descriptor = json_decode((string)file_get_contents($root . '/lib/Settings/integriq_register.json'), true, flags: JSON_THROW_ON_ERROR);

		$merge = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			if (is_array($fragment) === false) {
				continue;
			}

			$descriptor = $merge->invoke(null, $descriptor, $fragment);
		}

		return $descriptor;
	}//end mergedRegister()

	/**
	 * Load the one seeded object from a fragment.
	 *
	 * @param string $file The fragment file name.
	 *
	 * @return array<string, mixed>
	 */
	private static function seededObject(string $file): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/' . $file;
		self::assertFileExists($path);
		$decoded = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		self::assertCount(1, $decoded['components']['objects']);
		return $decoded['components']['objects'][0];
	}//end seededObject()

	/**
	 * The seeded object passes the real source schema with the validator OpenRegister uses.
	 *
	 * @param string $slug     The template slug.
	 * @param string $file     The fragment file.
	 * @param string $provider The provider shape.
	 *
	 * @return void
	 *
	 * @dataProvider templates
	 */
	public function testTemplateValidatesAgainstTheSourceSchema(string $slug, string $file, string $provider): void {
		$object = self::seededObject(file: $file);
		$self   = $object['@self'];
		unset($object['@self']);

		$this->assertSame(['register' => 'integriq', 'schema' => 'source', 'slug' => $slug], $self);

		$schema = self::mergedRegister()['components']['schemas']['source'];
		$result = (new Validator())->validate(
			json_decode(json_encode($object, JSON_THROW_ON_ERROR)),
			json_encode($schema, JSON_THROW_ON_ERROR)
		);
		$errors = [];
		if ($result->isValid() === false) {
			$errors = (new ErrorFormatter())->format($result->error());
		}

		$this->assertSame([], $errors);
	}//end testTemplateValidatesAgainstTheSourceSchema()

	/**
	 * The template is dormant and names the shape TranslationService reads.
	 *
	 * @param string $slug     The template slug.
	 * @param string $file     The fragment file.
	 * @param string $provider The provider shape.
	 *
	 * @return void
	 *
	 * @dataProvider templates
	 */
	public function testTemplateIsDormantAndTriedByTheService(string $slug, string $file, string $provider): void {
		$object = self::seededObject(file: $file);

		$this->assertFalse($object['isEnabled']);
		$this->assertSame($provider, $object['configuration']['translationProvider']);
		$this->assertContains($slug, TranslationService::SOURCE_SLUGS);
	}//end testTemplateIsDormantAndTriedByTheService()

	/**
	 * Both templates sit in the catalogue under Language.
	 *
	 * @param string $slug     The template slug.
	 * @param string $file     The fragment file.
	 * @param string $provider The provider shape.
	 *
	 * @return void
	 *
	 * @dataProvider templates
	 */
	public function testTemplateIsListedUnderLanguage(string $slug, string $file, string $provider): void {
		$overrides = (new ReflectionClassConstant(\OCA\Integriq\Service\CatalogRegistryService::class, 'SLUG_CATEGORY_OVERRIDES'))->getValue();

		$this->assertSame('Language', $overrides[$slug] ?? null);
	}//end testTemplateIsListedUnderLanguage()
}//end class
