<?php

/**
 * The PDOK geocoding source hands suggest, lookup and reverse to the
 * Locatieserver client, logs each call with its source, and reads its flag
 * from app config.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Sources\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Pdok;

use OCA\Integriq\Adapters\Pdok\PdokGeocodingClient as AdapterGeocodingClient;
use OCA\Integriq\Sources\Pdok\PdokGeocodingClient;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Sources\Pdok\PdokGeocodingClient
 */
class PdokGeocodingClientTest extends TestCase {

	/**
	 * App config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The Locatieserver client.
	 *
	 * @var AdapterGeocodingClient&MockObject
	 */
	private AdapterGeocodingClient&MockObject $client;

	/**
	 * Set up the boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->client = $this->createMock(originalClassName: AdapterGeocodingClient::class);
		$this->client->method('flavour')->willReturn('mock');
	}//end setUp()

	/**
	 * The source under test.
	 *
	 * @return PdokGeocodingClient
	 */
	private function source(): PdokGeocodingClient {
		return new PdokGeocodingClient(config: $this->config, logger: $this->logger, geocodingClient: $this->client);
	}//end source()

	/**
	 * The flag is on for "1" and any case of "true", off otherwise, and read from the right key.
	 *
	 * @return void
	 */
	public function testTheFlagIsReadFromAppConfig(): void {
		$this->config->method('getValueString')
			->with('integriq', 'pdok.feature_flag', '0')
			->willReturnOnConsecutiveCalls('1', 'TRUE', 'true', '0', 'yes', '');
		$source = $this->source();

		$this->assertTrue(condition: $source->isActive());
		$this->assertTrue(condition: $source->isActive());
		$this->assertTrue(condition: $source->isActive());
		$this->assertFalse(condition: $source->isActive());
		$this->assertFalse(condition: $source->isActive());
		$this->assertFalse(condition: $source->isActive());
	}//end testTheFlagIsReadFromAppConfig()

	/**
	 * Suggest passes query and rows through, returns the client's answer, and logs the call.
	 *
	 * @return void
	 */
	public function testSuggestIsHandedToTheClient(): void {
		$this->config->method('getValueString')->willReturn('1');
		$answer = [['id' => 'adr-1', 'weergavenaam' => 'Dorpsstraat 1, Ergens']];
		$this->client->expects($this->once())->method('suggest')->with('Dorpsstraat 1', 5)->willReturn($answer);
		$this->logger->expects($this->once())->method('debug')->with(
			'pdok-geocoding.suggest',
			[
				'source' => 'pdok-geocoding',
				'category' => 'geo',
				'query' => 'Dorpsstraat 1',
				'rows' => 5,
				'active' => true,
				'flavour' => 'mock',
			]
		);

		$this->assertSame(expected: $answer, actual: $this->source()->suggest(query: 'Dorpsstraat 1', rows: 5));
	}//end testSuggestIsHandedToTheClient()

	/**
	 * Suggest asks for ten rows by default, also while the flag is off.
	 *
	 * @return void
	 */
	public function testSuggestDefaultsToTenRows(): void {
		$this->config->method('getValueString')->willReturn('0');
		$this->client->expects($this->once())->method('suggest')->with('Kerkplein', 10)->willReturn([]);
		$this->logger->expects($this->once())->method('debug')->with(
			'pdok-geocoding.suggest',
			$this->callback(callback: static fn (array $context): bool => $context['rows'] === 10 && $context['active'] === false)
		);

		$this->assertSame(expected: [], actual: $this->source()->suggest(query: 'Kerkplein'));
	}//end testSuggestDefaultsToTenRows()

	/**
	 * Lookup passes the PDOK id through and returns what the client found, or null.
	 *
	 * @return void
	 */
	public function testLookupIsHandedToTheClient(): void {
		$this->config->method('getValueString')->willReturn('0');
		$this->client->expects($this->exactly(count: 2))
			->method('lookup')
			->willReturnMap([['adr-1', ['id' => 'adr-1']], ['missing', null]]);
		$this->logger->expects($this->exactly(count: 2))->method('debug')->with(
			'pdok-geocoding.lookup',
			$this->callback(
				callback: static fn (array $context): bool => $context['source'] === 'pdok-geocoding'
					&& $context['category'] === 'geo'
					&& in_array(needle: $context['pdokId'], haystack: ['adr-1', 'missing'], strict: true)
					&& $context['active'] === false
					&& $context['flavour'] === 'mock'
			)
		);
		$source = $this->source();

		$this->assertSame(expected: ['id' => 'adr-1'], actual: $source->lookup(pdokId: 'adr-1'));
		$this->assertNull(actual: $source->lookup(pdokId: 'missing'));
	}//end testLookupIsHandedToTheClient()

	/**
	 * Reverse passes latitude and longitude in that order and logs them.
	 *
	 * @return void
	 */
	public function testReverseIsHandedToTheClient(): void {
		$this->config->method('getValueString')->willReturn('true');
		$answer = [['id' => 'adr-2']];
		$this->client->expects($this->once())->method('reverse')->with(52.1, 5.2)->willReturn($answer);
		$this->logger->expects($this->once())->method('debug')->with(
			'pdok-geocoding.reverse',
			[
				'source' => 'pdok-geocoding',
				'category' => 'geo',
				'lat' => 52.1,
				'lng' => 5.2,
				'active' => true,
				'flavour' => 'mock',
			]
		);

		$this->assertSame(expected: $answer, actual: $this->source()->reverse(latitude: 52.1, longitude: 5.2));
	}//end testReverseIsHandedToTheClient()

	/**
	 * The source descriptor names this source, disabled until its flag is set.
	 *
	 * @return void
	 */
	public function testTheSourceDescriptor(): void {
		$descriptor = PdokGeocodingClient::sourceDescriptor();

		$this->assertSame(expected: 'pdok-geocoding', actual: $descriptor['id']);
		$this->assertSame(expected: 'geo', actual: $descriptor['category']);
		$this->assertSame(expected: 'geocoding', actual: $descriptor['subCategory']);
		$this->assertSame(expected: PdokGeocodingClient::class, actual: $descriptor['adapterClass']);
		$this->assertSame(expected: 'https://api.pdok.nl/bzk/locatieserver/search/v3_1', actual: $descriptor['location']);
		$this->assertSame(expected: 'rest', actual: $descriptor['type']);
		$this->assertSame(expected: 'none', actual: $descriptor['auth']);
		$this->assertFalse(condition: $descriptor['isEnabled']);
		$this->assertSame(expected: PdokGeocodingClient::FLAG_KEY, actual: $descriptor['reference']);
	}//end testTheSourceDescriptor()
}//end class
