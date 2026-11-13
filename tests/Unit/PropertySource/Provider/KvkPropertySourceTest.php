<?php

/**
 * Integriq — KvK property-source binding tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\PropertySource\Provider
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

namespace OCA\Integriq\Tests\Unit\PropertySource\Provider;

use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCA\Integriq\PropertySource\Provider\KvkPropertySource;
use OCA\Integriq\PropertySource\RegistrySourceGateway;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RFS-006: the KvK binding asks KvK Zoeken through the gateway, by name
 * for a suggestion and by kvkNummer for a record.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006
 *
 * @covers \OCA\Integriq\PropertySource\Provider\KvkPropertySource
 * @uses   \OCA\Integriq\PropertySource\Exception\SourceUnreachableException
 * @uses   \OCA\Integriq\PropertySource\Exception\PropertySourceException
 */
class KvkPropertySourceTest extends TestCase {
	/**
	 * A gateway double restricted to the method the real class has.
	 *
	 * @return RegistrySourceGateway The double.
	 */
	private function gateway(): RegistrySourceGateway {
		return $this->getMockBuilder(RegistrySourceGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['read'])
			->getMock();
	}//end gateway()

	/**
	 * A binding over a gateway that records each read and answers the given body.
	 *
	 * @param array<string,mixed> $body  The body the gateway answers.
	 * @param array<int,array>    $calls The reads made, by reference.
	 *
	 * @return KvkPropertySource The binding.
	 */
	private function source(array $body, ?array &$calls=null): KvkPropertySource {
		$calls   = [];
		$gateway = $this->gateway();
		$gateway->method('read')->willReturnCallback(
			static function (string $providerId, string $slug, string $endpoint, array $query=[], ?array $json=null) use (&$calls, $body): array {
				$calls[] = [$providerId, $slug, $endpoint, $query, $json];
				return $body;
			}
		);

		return new KvkPropertySource($gateway);
	}//end source()

	/**
	 * The provider id is kvk.
	 *
	 * @return void
	 */
	public function testTheIdIsKvk(): void {
		$this->assertSame('kvk', (new KvkPropertySource($this->gateway()))->id());
	}//end testTheIdIsKvk()

	/**
	 * A suggestion searches KvK Zoeken by name on the seeded source.
	 *
	 * @return void
	 */
	public function testSuggestSearchesByNameOnTheSeededSource(): void {
		$source = $this->source(['resultaten' => []], $calls);

		$this->assertSame([], $source->suggest('Conduction'));
		$this->assertSame([['kvk', 'kvk', '/zoeken', ['naam' => 'Conduction'], null]], $calls);
	}//end testSuggestSearchesByNameOnTheSeededSource()

	/**
	 * Hits become identifier and label; non-objects and hits without a number are dropped.
	 *
	 * @return void
	 */
	public function testSuggestNormalisesHitsAndDropsUnusableOnes(): void {
		$source = $this->source(
			[
				'resultaten' => [
					['kvkNummer' => '12345678', 'naam' => 'Conduction B.V.'],
					'not a hit',
					['naam' => 'No number'],
					['kvkNummer' => '', 'naam' => 'Empty number'],
					['kvkNummer' => 87654321],
				],
			]
		);

		$this->assertSame(
			[
				['identifier' => '12345678', 'label' => 'Conduction B.V.'],
				['identifier' => '87654321', 'label' => '87654321'],
			],
			$source->suggest('Con')
		);
	}//end testSuggestNormalisesHitsAndDropsUnusableOnes()

	/**
	 * A body without results, or with results that are not a list, suggests nothing.
	 *
	 * @return void
	 */
	public function testABodyWithoutResultsSuggestsNothing(): void {
		$this->assertSame([], $this->source(['foutmelding' => 'x'])->suggest('Con'));
		$this->assertSame([], $this->source(['resultaten' => null])->suggest('Con'));
	}//end testABodyWithoutResultsSuggestsNothing()

	/**
	 * A declared source overrides the seeded slug, for suggest and resolve alike.
	 *
	 * @return void
	 */
	public function testADeclaredSourceOverridesTheSlug(): void {
		$source = $this->source(['resultaten' => [['kvkNummer' => '12345678']]], $calls);

		$source->suggest('Con', ['source' => 'kvk-test']);
		$source->resolve('12345678', ['source' => 'kvk-test']);

		$this->assertSame('kvk-test', $calls[0][1]);
		$this->assertSame(['kvk', 'kvk-test', '/zoeken', ['kvkNummer' => '12345678'], null], $calls[1]);
	}//end testADeclaredSourceOverridesTheSlug()

	/**
	 * The head office is the record, wherever it sits among the rows of the number.
	 *
	 * @return void
	 */
	public function testResolvePrefersTheHeadOffice(): void {
		$head   = ['kvkNummer' => '12345678', 'type' => 'hoofdvestiging', 'adres' => 'Kerkstraat 1'];
		$source = $this->source(
			[
				'resultaten' => [
					['kvkNummer' => '12345678', 'type' => 'rechtspersoon'],
					['kvkNummer' => '99999999', 'type' => 'hoofdvestiging'],
					$head,
					['kvkNummer' => '12345678', 'type' => 'nevenvestiging'],
				],
			],
			$calls
		);

		$this->assertSame($head, $source->resolve('12345678'));
		$this->assertSame([['kvk', 'kvk', '/zoeken', ['kvkNummer' => '12345678'], null]], $calls);
	}//end testResolvePrefersTheHeadOffice()

	/**
	 * Without a head office the first row of that number is the record; other numbers and non-objects are skipped.
	 *
	 * @return void
	 */
	public function testWithoutAHeadOfficeTheFirstRowOfTheNumberIsTheRecord(): void {
		$first  = ['kvkNummer' => '12345678', 'type' => 'rechtspersoon'];
		$source = $this->source(
			[
				'resultaten' => [
					'x',
					['kvkNummer' => '11111111', 'type' => 'rechtspersoon'],
					$first,
					['kvkNummer' => '12345678', 'type' => 'nevenvestiging'],
				],
			]
		);

		$this->assertSame($first, $source->resolve('12345678'));
	}//end testWithoutAHeadOfficeTheFirstRowOfTheNumberIsTheRecord()

	/**
	 * No row of the number is a refusal naming the number, never an empty record.
	 *
	 * @return void
	 */
	public function testNoRowOfTheNumberIsARefusal(): void {
		$source = $this->source(['resultaten' => [['kvkNummer' => '11111111', 'type' => 'hoofdvestiging']]]);

		try {
			$source->resolve('12345678');
			$this->fail('No row of the number must be a refusal.');
		} catch (SourceUnreachableException $e) {
			$this->assertSame('kvk', $e->getProviderId());
			$this->assertSame('The kvk registry returned no record for "12345678".', $e->getMessage());
		}
	}//end testNoRowOfTheNumberIsARefusal()

	/**
	 * describe() names what the binding keys on and how fresh it keeps a value.
	 *
	 * @return void
	 */
	public function testDescribe(): void {
		$this->assertSame(
			[
				'id' => 'kvk',
				'label' => 'KvK organisation',
				'identifier' => 'kvkNummer',
				'stalenessBudget' => 86400,
				'listShaped' => false,
			],
			(new KvkPropertySource($this->gateway()))->describe()
		);
	}//end testDescribe()
}//end class
