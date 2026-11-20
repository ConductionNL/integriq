<?php

/**
 * WooStarterSet unit tests.
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
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Catalogi;

use OCA\Integriq\Service\Catalogi\CatalogiBinding;
use OCA\Integriq\Service\Catalogi\CatalogiGate;
use OCA\Integriq\Service\Catalogi\WooStarterSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * One type per missing Woo category, never touching an existing one.
 */
#[CoversClass(WooStarterSet::class)]
#[UsesClass(CatalogiBinding::class)]
#[UsesClass(CatalogiGate::class)]
class WooStarterSetTest extends TestCase {

	use CatalogiFixture;

	/**
	 * The types created, as stored payloads.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $created = [];

	/**
	 * Seventeen categories, the first labelled "besluiten".
	 *
	 * @return list<array{uri: string, label: string}>
	 */
	private static function categories(): array {
		$categories = [['uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_besluiten', 'label' => 'besluiten']];
		for ($i = 2; $i <= 17; $i++) {
			$categories[] = ['uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_' . $i, 'label' => 'categorie ' . $i];
		}

		return $categories;
	}//end categories()

	/**
	 * The starter set over the given seams.
	 *
	 * @param array<int, array<string, mixed>> $stored     The stored types.
	 * @param array|null                       $categories The categories, null for an unreadable scheme.
	 * @param bool                             $bound      Whether a binding resolves.
	 *
	 * @return WooStarterSet
	 */
	private function starterSet(array $stored, ?array $categories, bool $bound = true): WooStarterSet {
		return new WooStarterSet(
			bindingReader: static fn (): ?CatalogiBinding => ($bound === true ? self::binding() : null),
			typesReader: static fn (): array => $stored,
			creator: function (CatalogiBinding $binding, array $stored): void {
				$this->created[] = $stored;
			},
			categoriesReader: static fn (): ?array => $categories
		);
	}//end starterSet()

	/**
	 * One existing type in "besluiten" and 17 categories: 16 created, 1 skipped.
	 *
	 * @return void
	 */
	public function testOnlyMissingCategoriesAreCreated(): void {
		$answer = $this->starterSet(stored: [['id' => 'own', 'name' => 'Ons besluit', 'category' => 'Besluiten', 'active' => true]], categories: self::categories())->run();

		self::assertSame(['status' => 200, 'body' => ['created' => 16, 'skipped' => 1]], $answer);
		self::assertCount(16, $this->created);
		self::assertSame(
			[
				'name' => 'categorie 2',
				'category' => 'categorie 2',
				'categoryUri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_2',
				'confidentiality' => 'openbaar',
				'active' => true,
			],
			$this->created[0]
		);
		self::assertNotContains('besluiten', array_column($this->created, 'category'));
	}//end testOnlyMissingCategoriesAreCreated()

	/**
	 * A category an existing type carries by URI counts as present too.
	 *
	 * @return void
	 */
	public function testACategoryIsPresentByItsUri(): void {
		$answer = $this->starterSet(stored: [['id' => 'own', 'categoryUri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_5']], categories: self::categories())->run();

		self::assertSame(['created' => 16, 'skipped' => 1], $answer['body']);
	}//end testACategoryIsPresentByItsUri()

	/**
	 * An unreadable or empty TOOI scheme refuses and creates nothing.
	 *
	 * @return void
	 */
	public function testAMissingSchemeRefuses(): void {
		foreach ([null, []] as $categories) {
			$answer = $this->starterSet(stored: [], categories: $categories)->run();
			self::assertSame(409, $answer['status']);
			self::assertSame('tooi-scheme-missing', $answer['body']['code']);
		}

		self::assertSame([], $this->created);
	}//end testAMissingSchemeRefuses()

	/**
	 * Without a binding there is nowhere to create, and nothing is.
	 *
	 * @return void
	 */
	public function testAnUnboundFacadeRefuses(): void {
		$answer = $this->starterSet(stored: [], categories: self::categories(), bound: false)->run();

		self::assertSame('catalogue-not-configured', $answer['body']['code']);
		self::assertSame([], $this->created);
	}//end testAnUnboundFacadeRefuses()

}//end class
