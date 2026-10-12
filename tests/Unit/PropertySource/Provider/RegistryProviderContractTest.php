<?php

/**
 * Integriq: the BRP and KvK bindings against the answers their seeded sources ship.
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
use OCA\Integriq\PropertySource\Provider\BrpPropertySource;
use OCA\Integriq\PropertySource\Provider\KvkPropertySource;
use OCA\Integriq\PropertySource\RegistrySourceGateway;
use PHPUnit\Framework\TestCase;

/**
 * The gateway double answers what the seeded source's own `mockResponse`
 * holds, which is the registry's real response shape (Haal Centraal BRP
 * Personen v2 `/personen`, KvK Zoeken v2 `/zoeken`). The first version of
 * these bindings asked `/brp/{bsn}` and `/kvk/{nummer}`, paths neither API
 * has, and read a flat record neither API returns; their tests handed the
 * double a made-up flat record, so they passed.
 *
 * @covers \OCA\Integriq\PropertySource\Provider\BrpPropertySource
 * @covers \OCA\Integriq\PropertySource\Provider\KvkPropertySource
 * @uses \OCA\Integriq\PropertySource\Exception\PropertySourceException
 * @uses \OCA\Integriq\PropertySource\Exception\SourceUnreachableException
 */
class RegistryProviderContractTest extends TestCase {
	/**
	 * The calls the bindings made.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The response a seeded source ships as its mock answer.
	 *
	 * @param string $fragment The register fragment's file name.
	 *
	 * @return array<string, mixed>
	 */
	private static function shipped(string $fragment): array {
		$file = dirname(__DIR__, 4) . '/lib/Settings/register.d/' . $fragment;
		$data = json_decode((string)file_get_contents($file), true);

		return (array)$data['components']['objects'][0]['configuration']['mockResponse'];
	}//end shipped()

	/**
	 * A gateway that records the call and answers the given body.
	 *
	 * @param array<string, mixed> $answer The body.
	 *
	 * @return RegistrySourceGateway
	 */
	private function gateway(array $answer): RegistrySourceGateway {
		$gateway = $this->getMockBuilder(RegistrySourceGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['read'])
			->getMock();
		$gateway->method('read')->willReturnCallback(
			function (...$arguments) use ($answer): array {
				$this->calls[] = $arguments;
				return $answer;
			}
		);

		return $gateway;
	}//end gateway()

	/**
	 * A person is asked for the way Haal Centraal takes the question, and the
	 * answer is the person, not the envelope.
	 *
	 * @return void
	 */
	public function testAPersonIsReadFromTheShippedHaalCentraalAnswer(): void {
		$person = (new BrpPropertySource($this->gateway(self::shipped('brp-haalcentraal-source.json'))))->resolve('999990019');

		$this->assertSame('999990019', $person['burgerservicenummer']);
		$this->assertSame('Tester', $person['naam']['geslachtsnaam']);
		$this->assertSame('/personen', $this->calls[0][2]);
		$this->assertSame([], $this->calls[0][3], 'The BSN never travels in the URL');
		$this->assertSame('RaadpleegMetBurgerservicenummer', $this->calls[0][4]['type']);
		$this->assertSame(['999990019'], $this->calls[0][4]['burgerservicenummer']);
		$this->assertSame(BrpPropertySource::DEFAULT_FIELDS, $this->calls[0][4]['fields']);
	}//end testAPersonIsReadFromTheShippedHaalCentraalAnswer()

	/**
	 * A property that needs more than the default fields declares them.
	 *
	 * @return void
	 */
	public function testDeclaredFieldsAreAskedFor(): void {
		$gateway = $this->gateway(self::shipped('brp-haalcentraal-source.json'));
		(new BrpPropertySource($gateway))->resolve('999990019', ['fields' => ['burgerservicenummer', 'partners.burgerservicenummer']]);

		$this->assertSame(['burgerservicenummer', 'partners.burgerservicenummer'], $this->calls[0][4]['fields']);
	}//end testDeclaredFieldsAreAskedFor()

	/**
	 * An envelope without a person is no record.
	 *
	 * @return void
	 */
	public function testAnEmptyPersonListIsNoRecord(): void {
		$this->expectException(SourceUnreachableException::class);
		(new BrpPropertySource($this->gateway(['type' => 'RaadpleegMetBurgerservicenummer', 'personen' => []])))->resolve('999990019');
	}//end testAnEmptyPersonListIsNoRecord()

	/**
	 * Haal Centraal has no type-ahead: a whole BSN suggests its one person,
	 * anything shorter suggests nothing and asks the registry nothing.
	 *
	 * @return void
	 */
	public function testOnlyAWholeBsnSuggestsAPerson(): void {
		$source = new BrpPropertySource($this->gateway(self::shipped('brp-haalcentraal-source.json')));

		$this->assertSame([], $source->suggest('Test'));
		$this->assertSame([], $this->calls);
		$this->assertSame([['identifier' => '999990019', 'label' => 'Jan Pieter de Tester']], $source->suggest('999990019'));
	}//end testOnlyAWholeBsnSuggestsAPerson()

	/**
	 * A company is asked for through KvK Zoeken by its number, and the answer
	 * is the head office row.
	 *
	 * @return void
	 */
	public function testACompanyIsReadFromTheShippedZoekenAnswer(): void {
		$company = (new KvkPropertySource($this->gateway(self::shipped('kvk-source.json'))))->resolve('69599084');

		$this->assertSame('69599084', $company['kvkNummer']);
		$this->assertSame('hoofdvestiging', $company['type']);
		$this->assertSame('/zoeken', $this->calls[0][2]);
		$this->assertSame(['kvkNummer' => '69599084'], $this->calls[0][3]);
	}//end testACompanyIsReadFromTheShippedZoekenAnswer()

	/**
	 * A number the answer does not hold is no record, even when Zoeken
	 * answered other companies.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutTheNumberIsNoRecord(): void {
		$this->expectException(SourceUnreachableException::class);
		(new KvkPropertySource($this->gateway(self::shipped('kvk-source.json'))))->resolve('11111111');
	}//end testAnAnswerWithoutTheNumberIsNoRecord()

	/**
	 * Type-ahead searches Zoeken by name.
	 *
	 * @return void
	 */
	public function testCompaniesAreSuggestedByName(): void {
		$hits = (new KvkPropertySource($this->gateway(self::shipped('kvk-source.json'))))->suggest('Conduction');

		$this->assertSame('/zoeken', $this->calls[0][2]);
		$this->assertSame(['naam' => 'Conduction'], $this->calls[0][3]);
		$this->assertContains(['identifier' => '69599084', 'label' => 'Conduction B.V.'], $hits);
	}//end testCompaniesAreSuggestedByName()
}//end class
