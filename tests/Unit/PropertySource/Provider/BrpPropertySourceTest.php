<?php

/**
 * Integriq — BRP property-source binding tests.
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

use OCA\Integriq\PropertySource\Exception\MissingSourceConfigurationException;
use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCA\Integriq\PropertySource\Provider\BrpPropertySource;
use OCA\Integriq\PropertySource\RegistrySourceGateway;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RFS-006: a binding reads through the gateway, never through a client of
 * its own, and a missing source is a configuration error.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006
 */
class BrpPropertySourceTest extends TestCase {
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
	 * The binding reads the seeded Haal Centraal source through the gateway.
	 *
	 * @return void
	 */
	public function testResolveReadsTheSeededSourceThroughTheGateway(): void {
		$gateway = $this->gateway();
		$gateway->expects($this->once())
			->method('read')
			->with('brp', BrpPropertySource::SOURCE_SLUG, '/personen', [], $this->callback(static fn (array $body): bool => $body['burgerservicenummer'] === ['999993653']))
			->willReturn(['personen' => [['burgerservicenummer' => '999993653', 'naam' => 'De Vries']]]);

		$value = (new BrpPropertySource($gateway))->resolve('999993653');

		$this->assertSame('De Vries', $value['naam']);
	}//end testResolveReadsTheSeededSourceThroughTheGateway()

	/**
	 * A declared `source` in the property configuration overrides the default slug.
	 *
	 * @return void
	 */
	public function testADeclaredSourceOverridesTheDefaultSlug(): void {
		$gateway = $this->gateway();
		$gateway->expects($this->once())
			->method('read')
			->with('brp', 'brp-acceptatie', $this->anything(), $this->anything(), $this->anything())
			->willReturn(['personen' => [['burgerservicenummer' => '999993653']]]);

		(new BrpPropertySource($gateway))->resolve('999993653', ['source' => 'brp-acceptatie']);
	}//end testADeclaredSourceOverridesTheDefaultSlug()

	/**
	 * A binding whose source is not configured fails naming the source, and no
	 * call is made.
	 *
	 * @return void
	 */
	public function testAMissingSourceFailsBeforeAnyCall(): void {
		$gateway = $this->gateway();
		$gateway->method('read')->willThrowException(
			new MissingSourceConfigurationException('brp', BrpPropertySource::SOURCE_SLUG)
		);

		try {
			(new BrpPropertySource($gateway))->resolve('999993653');
			$this->fail('A binding without a source must not resolve.');
		} catch (MissingSourceConfigurationException $e) {
			$this->assertSame(BrpPropertySource::SOURCE_SLUG, $e->getSourceSlug());
			$this->assertStringContainsString(BrpPropertySource::SOURCE_SLUG, $e->getMessage());
		}
	}//end testAMissingSourceFailsBeforeAnyCall()

	/**
	 * A body without the keyed identifier is not a record.
	 *
	 * @return void
	 */
	public function testABodyWithoutTheIdentifierIsNotARecord(): void {
		$gateway = $this->gateway();
		$gateway->method('read')->willReturn(['foutmelding' => 'niet gevonden']);

		$this->expectException(SourceUnreachableException::class);

		(new BrpPropertySource($gateway))->resolve('999993653');
	}//end testABodyWithoutTheIdentifierIsNotARecord()

	/**
	 * describe() names the identifier the registry keys on.
	 *
	 * @return void
	 */
	public function testDescribeNamesTheIdentifierItKeysOn(): void {
		$described = (new BrpPropertySource($this->gateway()))->describe();

		$this->assertSame('brp', $described['id']);
		$this->assertSame('burgerservicenummer', $described['identifier']);
		$this->assertSame(BrpPropertySource::STALENESS_BUDGET, $described['stalenessBudget']);
	}//end testDescribeNamesTheIdentifierItKeysOn()

	/**
	 * The fields a property asks for, and the fields the read asks the registry for.
	 *
	 * @return array<string,array{0:mixed,1:list<string>}>
	 */
	public static function fieldRequests(): array {
		$defaults = BrpPropertySource::DEFAULT_FIELDS;
		return [
			'none'                       => [null, $defaults],
			'empty list'                 => [[], $defaults],
			'not a list'                 => ['naam', $defaults],
			'own fields, keyed'          => [['a' => 'naam', 'b' => 7], ['naam', '7']],
			'relations dropped'          => [['naam', 'partners', 'ouders.naam', 'kinderen'], ['naam']],
			'only relations'             => [['partners.naam', 'ouders'], $defaults],
			'look-alike kept'            => [['partnerschap', 'kinderenAantal', 'naam.partners'], ['partnerschap', 'kinderenAantal', 'naam.partners']],
		];

	}//end fieldRequests()

	/**
	 * The read asks for the fields the property asked for, without relations.
	 *
	 * @param mixed        $fields   The property's fields.
	 * @param list<string> $expected The fields read.
	 *
	 * @return void
	 *
	 * @dataProvider fieldRequests
	 */
	public function testTheReadAsksForThePropertysFields(mixed $fields, array $expected): void {
		$gateway = $this->gateway();
		$gateway->expects($this->once())->method('read')
			->with(
				$this->identicalTo('brp'),
				$this->identicalTo('brp-haalcentraal'),
				$this->identicalTo('/personen'),
				$this->identicalTo([]),
				$this->identicalTo(['type' => 'RaadpleegMetBurgerservicenummer', 'burgerservicenummer' => ['999993653'], 'fields' => $expected])
			)
			->willReturn(['personen' => [['burgerservicenummer' => '999993653']]]);
		$config = [];
		if ($fields !== null) {
			$config['fields'] = $fields;
		}

		(new BrpPropertySource($gateway))->resolve('999993653', $config);

	}//end testTheReadAsksForThePropertysFields()

	/**
	 * The first record that is an object is the person; none is a refusal with this message.
	 *
	 * @return void
	 */
	public function testTheFirstObjectRecordIsThePerson(): void {
		$gateway = $this->gateway();
		$gateway->method('read')->willReturnOnConsecutiveCalls(
			['personen' => ['x', ['id' => 1], ['id' => 2]]],
			['personen' => 'x'],
			['personen' => ['x']]
		);
		$source = new BrpPropertySource($gateway);

		$this->assertSame(['id' => 1], $source->resolve('999993653'));
		foreach ([1, 2] as $attempt) {
			try {
				$source->resolve('999993653');
				$this->fail('no record must be a refusal');
			} catch (SourceUnreachableException $exception) {
				$this->assertSame('The brp registry returned no record for the burgerservicenummer asked.', $exception->getMessage(), (string)$attempt);
			}
		}

	}//end testTheFirstObjectRecordIsThePerson()

	/**
	 * The provider id is brp.
	 *
	 * @return void
	 */
	public function testTheIdIsBrp(): void {
		$this->assertSame('brp', (new BrpPropertySource($this->gateway()))->id());
	}//end testTheIdIsBrp()

	/**
	 * A whole number the registry knows no person for suggests nothing.
	 *
	 * @return void
	 */
	public function testANumberWithoutAPersonSuggestsNothing(): void {
		$gateway = $this->gateway();
		$gateway->expects($this->once())->method('read')->willReturn(['personen' => []]);

		$this->assertSame([], (new BrpPropertySource($gateway))->suggest(' 999993653 '));
	}//end testANumberWithoutAPersonSuggestsNothing()

	/**
	 * A person without a name is suggested under the number asked.
	 *
	 * @return void
	 */
	public function testAPersonWithoutANameIsLabelledWithTheNumber(): void {
		$gateway = $this->gateway();
		$gateway->method('read')->willReturnOnConsecutiveCalls(
			['personen' => [['burgerservicenummer' => '999993653']]],
			['personen' => [['naam' => ['voornamen' => 'Jan']]]]
		);
		$source = new BrpPropertySource($gateway);

		$expected = [['identifier' => '999993653', 'label' => '999993653']];
		$this->assertSame($expected, $source->suggest('999993653'));
		$this->assertSame($expected, $source->suggest('999993653'));
	}//end testAPersonWithoutANameIsLabelledWithTheNumber()
}//end class
