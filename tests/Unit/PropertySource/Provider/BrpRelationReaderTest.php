<?php

/**
 * Contract test: the BRP relation read against the answer the seeded source ships.
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
use OCA\Integriq\PropertySource\Provider\BrpRelationReader;
use OCA\Integriq\PropertySource\RegistrySourceGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The gateway double answers what the seeded `brp-haalcentraal` source ships
 * as its mock answer, so the reader is held to the Haal Centraal BRP Personen
 * v2 shape (`partners`, `ouders`, `kinderen`, each with a burgerservicenummer)
 * and not to a shape a test made up.
 *
 * @covers \OCA\Integriq\PropertySource\Provider\BrpRelationReader
 * @uses \OCA\Integriq\PropertySource\Exception\PropertySourceException
 * @uses \OCA\Integriq\PropertySource\Exception\SourceUnreachableException
 * @uses \OCA\Integriq\PropertySource\Exception\MissingSourceConfigurationException
 *
 * @spec openspec/changes/brp-relations-read/specs/registry-field-source/spec.md#requirement-brp-relations-are-read-only-where-the-source-says-the-municipality-may
 */
class BrpRelationReaderTest extends TestCase {
	/**
	 * The calls the reader made through the gateway.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Everything handed to the logger: message and context.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * The answer the seeded source ships.
	 *
	 * @return array<string, mixed>
	 */
	private static function shipped(): array {
		$file = dirname(__DIR__, 4) . '/lib/Settings/register.d/brp-haalcentraal-source.json';
		$data = json_decode((string)file_get_contents($file), true);

		return (array)$data['components']['objects'][0]['configuration']['mockResponse'];
	}//end shipped()

	/**
	 * A reader over a gateway double.
	 *
	 * @param array<string, mixed>|null $configuration The source's configuration, null for no source.
	 * @param array<string, mixed>|\Throwable $answer The body, or the failure.
	 *
	 * @return BrpRelationReader
	 */
	private function reader(?array $configuration, array|\Throwable $answer): BrpRelationReader {
		$gateway = $this->getMockBuilder(RegistrySourceGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['read', 'readWithoutBodies', 'configuration'])
			->getMock();
		$gateway->method('configuration')->willReturn($configuration);
		$gateway->expects($this->never())->method('read');
		$gateway->method('readWithoutBodies')->willReturnCallback(
			function (...$arguments) use ($answer): array {
				$this->calls[] = $arguments;
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (...$arguments): void {
					$this->logged[] = $arguments;
				}
			);
		}

		return new BrpRelationReader($gateway, $logger);
	}//end reader()

	/**
	 * With the setting on, the partner, parents and children of the shipped
	 * person come back as relation and burgerservicenummer, nothing else.
	 *
	 * @return void
	 */
	public function testTheRelationsOfTheShippedPersonAreRead(): void {
		$result = $this->reader(['relationFields' => true], self::shipped())->read('999990019');

		$this->assertTrue($result['available']);
		$this->assertSame('', $result['reason']);
		$this->assertSame(
			[
				['relation' => 'partner', 'burgerservicenummer' => '999993653'],
				['relation' => 'parent', 'burgerservicenummer' => '999991723'],
				['relation' => 'parent', 'burgerservicenummer' => '999992077'],
				['relation' => 'child', 'burgerservicenummer' => '999994669'],
			],
			$result['relations']
		);
	}//end testTheRelationsOfTheShippedPersonAreRead()

	/**
	 * The question asks for the three relation numbers and no other field,
	 * with the number in the body. The reader's gateway double fails on a
	 * read that records bodies, so every call here went the way that withholds them.
	 *
	 * @return void
	 */
	public function testOnlyTheRelationNumbersAreAskedForAndNoBodyIsRecorded(): void {
		$this->reader(['relationFields' => true], self::shipped())->read('999990019', ['source' => 'brp-acceptatie']);

		$this->assertCount(1, $this->calls);
		$this->assertSame('brp-acceptatie', $this->calls[0][1]);
		$this->assertSame('/personen', $this->calls[0][2]);
		$this->assertSame([], $this->calls[0][3], 'The BSN never travels in the URL');
		$this->assertSame(
			[
				'type' => 'RaadpleegMetBurgerservicenummer',
				'burgerservicenummer' => ['999990019'],
				'fields' => ['partners.burgerservicenummer', 'ouders.burgerservicenummer', 'kinderen.burgerservicenummer'],
			],
			$this->calls[0][4]
		);
	}//end testOnlyTheRelationNumbersAreAskedForAndNoBodyIsRecorded()

	/**
	 * A source without the setting is never asked for relations.
	 *
	 * @param array<string, mixed> $configuration The source's configuration.
	 *
	 * @return void
	 *
	 * @dataProvider notEnabled
	 */
	public function testASourceWithoutTheSettingIsNotAsked(array $configuration): void {
		$result = $this->reader($configuration, self::shipped())->read('999990019');

		$this->assertFalse($result['available']);
		$this->assertSame(BrpRelationReader::REASON_NOT_ENABLED, $result['reason']);
		$this->assertSame([], $result['relations']);
		$this->assertSame([], $this->calls, 'A municipality without the authorisation asks nothing');
	}//end testASourceWithoutTheSettingIsNotAsked()

	/**
	 * Configurations that do not switch the read on. Only a boolean true does.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function notEnabled(): array {
		return [
			'absent' => [[]],
			'false' => [['relationFields' => false]],
			'a string' => [['relationFields' => 'true']],
			'the shipped source' => [(array)json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/register.d/brp-haalcentraal-source.json'), true)['components']['objects'][0]['configuration']],
		];
	}//end notEnabled()

	/**
	 * Without a configured source the read is not available, and says why.
	 *
	 * @return void
	 */
	public function testWithoutASourceTheReadIsNotAvailable(): void {
		$result = $this->reader(null, self::shipped())->read('999990019');

		$this->assertFalse($result['available']);
		$this->assertSame(BrpRelationReader::REASON_NO_SOURCE, $result['reason']);
		$this->assertSame([], $this->calls);
	}//end testWithoutASourceTheReadIsNotAvailable()

	/**
	 * A text that is not a burgerservicenummer asks the registry nothing.
	 *
	 * @return void
	 */
	public function testSomethingThatIsNotABsnAsksNothing(): void {
		$result = $this->reader(['relationFields' => true], self::shipped())->read('12345');

		$this->assertFalse($result['available']);
		$this->assertSame(BrpRelationReader::REASON_INVALID_IDENTIFIER, $result['reason']);
		$this->assertSame([], $this->calls);
	}//end testSomethingThatIsNotABsnAsksNothing()

	/**
	 * A registry that does not answer is "not available", never "no relations".
	 *
	 * @param \Throwable $failure What the gateway threw.
	 * @param string $reason The reason expected.
	 *
	 * @return void
	 *
	 * @dataProvider failures
	 */
	public function testARegistryThatDoesNotAnswerIsNotAvailable(\Throwable $failure, string $reason): void {
		$result = $this->reader(['relationFields' => true], $failure)->read('999990019');

		$this->assertFalse($result['available']);
		$this->assertSame($reason, $result['reason']);
		$this->assertSame([], $result['relations']);
	}//end testARegistryThatDoesNotAnswerIsNotAvailable()

	/**
	 * Gateway failures and the reason each one becomes.
	 *
	 * @return array<string, array{0: \Throwable, 1: string}>
	 */
	public static function failures(): array {
		return [
			'unreachable' => [new SourceUnreachableException('brp', 'Source "brp-haalcentraal" answered HTTP 403.'), BrpRelationReader::REASON_UNREACHABLE],
			'source removed between the two reads' => [new MissingSourceConfigurationException('brp', 'brp-haalcentraal'), BrpRelationReader::REASON_NO_SOURCE],
		];
	}//end failures()

	/**
	 * An envelope without the person is "not available": the registry did not
	 * say this person has no relations, it said nothing about the person.
	 *
	 * @return void
	 */
	public function testAnEnvelopeWithoutThePersonIsNotAvailable(): void {
		$result = $this->reader(['relationFields' => true], ['type' => 'RaadpleegMetBurgerservicenummer', 'personen' => []])->read('999990019');

		$this->assertFalse($result['available']);
		$this->assertSame(BrpRelationReader::REASON_UNREACHABLE, $result['reason']);
	}//end testAnEnvelopeWithoutThePersonIsNotAvailable()

	/**
	 * A person the registry knows with no relation on record is an answer:
	 * available, and empty. A relation without a number (a partner who is not
	 * in the BRP) is left out.
	 *
	 * @return void
	 */
	public function testAPersonWithoutRelationsIsAnAnswer(): void {
		$answer = ['personen' => [['partners' => [['naam' => ['geslachtsnaam' => 'Jansen']]], 'kinderen' => []]]];
		$result = $this->reader(['relationFields' => true], $answer)->read('999990019');

		$this->assertTrue($result['available']);
		$this->assertSame([], $result['relations']);
	}//end testAPersonWithoutRelationsIsAnAnswer()

	/**
	 * No number, of the person or of a relation, and no part of the answer
	 * reaches the log, on a good read or a failed one.
	 *
	 * @return void
	 */
	public function testNeitherANumberNorTheAnswerIsLogged(): void {
		$this->reader(['relationFields' => true], self::shipped())->read('999990019');
		$this->reader(['relationFields' => true], new SourceUnreachableException('brp', 'no answer for 999990019'))->read('999990019');
		$this->reader([], self::shipped())->read('999990019');

		$this->assertNotSame([], $this->logged, 'A read leaves a line saying it happened');
		$written = (string)json_encode($this->logged);
		foreach (['999990019', '999993653', '999991723', '999992077', '999994669', 'Tester', 'partners', 'ouders', 'kinderen'] as $private) {
			$this->assertStringNotContainsString($private, $written);
		}
	}//end testNeitherANumberNorTheAnswerIsLogged()
}//end class
