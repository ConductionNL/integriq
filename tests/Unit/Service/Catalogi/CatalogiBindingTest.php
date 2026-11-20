<?php

/**
 * CatalogiBinding unit tests: resolution, the default field map, no app id in code.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Catalogi
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
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Catalogi;

use OCA\Integriq\Service\Catalogi\CatalogiBinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The binding the facade serves from.
 */
#[CoversClass(CatalogiBinding::class)]
class CatalogiBindingTest extends TestCase {

	/**
	 * A complete binding row.
	 *
	 * @var array<string, mixed>
	 */
	public const ROW = [
		'publishedUuid' => '3f9c1d2e-0000-4000-8000-000000000001',
		'domein' => 'WOO',
		'rsin' => '002220647',
		'naam' => 'Documenttypen',
		'register' => 'documents',
		'schema' => 'documentType',
		'clients' => [['clientId' => 'dms', 'scopes' => ['catalogi.lezen'], 'concepts' => true], 'not-a-client'],
	];

	/**
	 * No binding, or an incomplete one, resolves to null.
	 *
	 * @return void
	 */
	public function testNoBindingResolvesToNull(): void {
		$exists = static fn (): bool => true;

		self::assertNull(CatalogiBinding::resolve(rows: [], schemaExists: $exists));
		self::assertNull(CatalogiBinding::resolve(rows: [['register' => 'documents', 'schema' => 'documentType']], schemaExists: $exists), 'no published uuid');
		self::assertNull(CatalogiBinding::resolve(rows: [['publishedUuid' => 'x', 'register' => 'documents']], schemaExists: $exists), 'no schema');
	}//end testNoBindingResolvesToNull()

	/**
	 * A binding whose schema does not resolve is no binding.
	 *
	 * @return void
	 */
	public function testABindingWhoseSchemaDoesNotResolveIsNull(): void {
		$asked = [];
		$binding = CatalogiBinding::resolve(
			rows: [self::ROW],
			schemaExists: static function (string $register, string $schema) use (&$asked): bool {
				$asked[] = [$register, $schema];
				return false;
			}
		);

		self::assertNull($binding);
		self::assertSame([['documents', 'documentType']], $asked);
	}//end testABindingWhoseSchemaDoesNotResolveIsNull()

	/**
	 * The default field map gives the resource fields REQ-ZCS-001 names.
	 *
	 * @return void
	 */
	public function testTheDefaultFieldMap(): void {
		$binding = CatalogiBinding::resolve(rows: [self::ROW], schemaExists: static fn (): bool => true);
		self::assertNotNull($binding);
		self::assertSame(['domein' => 'WOO', 'rsin' => '002220647', 'naam' => 'Documenttypen'], $binding->catalog);

		$mapped = $binding->mapType(object: [
			'@self' => ['id' => 'type-1'],
			'name' => 'Besluit',
			'confidentiality' => 'openbaar',
			'category' => 'besluiten',
			'categoryUri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_besluit',
			'active' => true,
			'identifier' => 'DT-7',
			'validFrom' => '2026-01-01T00:00:00+00:00',
		]);

		self::assertSame(
			[
				'uuid' => 'type-1',
				'omschrijving' => 'Besluit',
				'vertrouwelijkheidaanduiding' => 'openbaar',
				'informatieobjectcategorie' => 'besluiten',
				'informatieobjectcategorieUri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_besluit',
				'concept' => false,
				'identificatie' => 'DT-7',
				'beginGeldigheid' => '2026-01-01',
			],
			$mapped
		);
		self::assertTrue($binding->mapType(object: ['id' => 'type-2', 'active' => false])['concept'], 'inactive is a concept');
		self::assertFalse($binding->mapType(object: ['id' => 'type-3'])['concept'], 'an absent flag is no concept');
	}//end testTheDefaultFieldMap()

	/**
	 * A binding's own field map overrides the default per field, and writes back through it.
	 *
	 * @return void
	 */
	public function testAFieldMapOverridesTheDefault(): void {
		$binding = CatalogiBinding::fromObject(row: self::ROW + ['fieldMap' => ['omschrijving' => 'title', 'concept' => '!published', 'x' => 3]]);

		self::assertSame('Raadsbesluit', $binding->mapType(object: ['id' => 'a', 'title' => 'Raadsbesluit', 'name' => 'ignored'])['omschrijving']);
		self::assertTrue($binding->mapType(object: ['id' => 'a', 'published' => false])['concept']);
		self::assertSame(
			['title' => 'Notulen', 'category' => 'vergaderstukken', 'published' => true],
			$binding->storedFrom(resource: ['omschrijving' => 'Notulen', 'informatieobjectcategorie' => 'vergaderstukken', 'concept' => false, 'onbekend' => 'x'])
		);
	}//end testAFieldMapOverridesTheDefault()

	/**
	 * A client is found by its client id, with its scopes and concept permission.
	 *
	 * @return void
	 */
	public function testClientFor(): void {
		$binding = CatalogiBinding::fromObject(row: self::ROW);

		self::assertSame(['scopes' => ['catalogi.lezen'], 'concepts' => true], $binding->clientFor(clientId: 'dms'));
		self::assertNull($binding->clientFor(clientId: 'other'));
		self::assertNull($binding->clientFor(clientId: ''));
	}//end testClientFor()

	/**
	 * The facade names components, never app ids (ZgwSetCatalogue's rule).
	 *
	 * @return void
	 */
	public function testNoAppIdIsNamedInTheFacade(): void {
		$files = glob(dirname(__DIR__, 4) . '/lib/Service/Catalogi/*.php');
		self::assertNotEmpty($files);
		foreach ($files as $file) {
			$code = (string)file_get_contents($file);
			foreach (['filinq', 'docudesk'] as $appId) {
				self::assertStringNotContainsStringIgnoringCase($appId, $code, basename($file) . ' names ' . $appId);
			}
		}
	}//end testNoAppIdIsNamedInTheFacade()

}//end class
