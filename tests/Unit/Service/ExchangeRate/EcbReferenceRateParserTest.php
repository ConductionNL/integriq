<?php

/**
 * Unit tests for EcbReferenceRateParser.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ExchangeRate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ExchangeRate;

use InvalidArgumentException;
use OCA\Integriq\Service\ExchangeRate\EcbReferenceRateParser;
use PHPUnit\Framework\TestCase;

/**
 * Parses stored copies of the ECB files under tests/fixtures/ecb/: the daily
 * file of 2026-10-30, and the 90-day history file trimmed to its three newest
 * days.
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */
class EcbReferenceRateParserTest extends TestCase {

	/**
	 * Directory of the stored ECB files.
	 *
	 * @var string
	 */
	private const FIXTURES = __DIR__ . '/../../../fixtures/ecb/';

	/**
	 * The daily file yields one date with every currency the ECB publishes.
	 *
	 * @return void
	 */
	public function testParsesTheDailyFile(): void {
		$rates = (new EcbReferenceRateParser())->parse((string)file_get_contents(self::FIXTURES . 'eurofxref-daily.xml'));

		$this->assertSame(['2026-10-30'], array_keys($rates));
		$this->assertCount(29, $rates['2026-10-30']);
		$this->assertSame(1.1172, $rates['2026-10-30']['USD']);
		$this->assertSame(0.8338, $rates['2026-10-30']['GBP']);
		$this->assertSame(175.68, $rates['2026-10-30']['JPY']);
		$this->assertArrayNotHasKey('EUR', $rates['2026-10-30']);

	}//end testParsesTheDailyFile()

	/**
	 * An element read with simplexml_load_string gives the same rates as the raw string.
	 *
	 * @return void
	 */
	public function testFromElementMatchesParse(): void {
		$parser  = new EcbReferenceRateParser();
		$raw     = (string)file_get_contents(self::FIXTURES . 'eurofxref-daily.xml');
		$element = simplexml_load_string($raw);
		$this->assertNotFalse($element);

		$this->assertSame($parser->parse($raw), $parser->fromElement($element));

	}//end testFromElementMatchesParse()

	/**
	 * The history file yields one entry per date, newest first.
	 *
	 * @return void
	 */
	public function testParsesTheHistoryFileNewestFirst(): void {
		$element = simplexml_load_string((string)file_get_contents(self::FIXTURES . 'eurofxref-hist-90d.xml'));
		$this->assertNotFalse($element);

		$rates = (new EcbReferenceRateParser())->fromElement($element);

		$this->assertSame(['2026-10-30', '2026-10-29', '2026-10-28'], array_keys($rates));
		foreach ($rates as $currencies) {
			$this->assertCount(29, $currencies);
		}

	}//end testParsesTheHistoryFileNewestFirst()

	/**
	 * Malformed dates, codes and rates are left out instead of stored.
	 *
	 * @return void
	 */
	public function testSkipsMalformedEntries(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">'
			. '<Cube><Cube time="2026-10-30"><Cube currency="USD" rate="1.1172"/><Cube currency="usd" rate="1.2"/>'
			. '<Cube currency="GBP" rate="n/a"/><Cube currency="JPY" rate="0"/></Cube>'
			. '<Cube time="30-10-2026"><Cube currency="USD" rate="1.1"/></Cube></Cube></gesmes:Envelope>';

		$this->assertSame(['2026-10-30' => ['USD' => 1.1172]], (new EcbReferenceRateParser())->parse($xml));

	}//end testSkipsMalformedEntries()

	/**
	 * Contents that are not XML (an HTML error page cut short) are refused.
	 *
	 * @return void
	 */
	public function testRefusesContentsThatAreNotXml(): void {
		$this->expectException(InvalidArgumentException::class);

		(new EcbReferenceRateParser())->parse('<html><body>Service unavailable');

	}//end testRefusesContentsThatAreNotXml()
}//end class
