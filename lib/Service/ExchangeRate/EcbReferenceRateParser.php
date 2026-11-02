<?php

/**
 * Integriq EcbReferenceRateParser.
 *
 * Reads the euro foreign exchange reference rate files the European Central
 * Bank publishes (eurofxref-daily.xml, eurofxref-hist-90d.xml).
 *
 * @category Service
 * @package  OCA\Integriq\Service\ExchangeRate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ExchangeRate;

use InvalidArgumentException;
use OCA\Integriq\Util\SafeXmlParser;
use SimpleXMLElement;

/**
 * Turns an ECB reference rate file into rates per date per currency.
 *
 * The file nests one `Cube time="YYYY-MM-DD"` per publication date, each with
 * one `Cube currency="XXX" rate="1.2345"` per currency, all in the eurofxref
 * default namespace. A rate is the number of units of the currency per euro.
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */
class EcbReferenceRateParser {

	/**
	 * Parse the raw XML of an ECB reference rate file.
	 *
	 * @param string $xml The file contents.
	 *
	 * @return array<string, array<string, float>> Rates keyed by date, then by currency code.
	 *
	 * @throws InvalidArgumentException When the contents are not XML.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SafeXmlParser is the repo's stateless XXE-hardened parse helper.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
	 */
	public function parse(string $xml): array {
		$previous = libxml_use_internal_errors(true);
		try {
			$element = SafeXmlParser::parse(data: $xml);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($element === false) {
			throw new InvalidArgumentException('The ECB reference rate file is not valid XML.');
		}

		return $this->fromElement(envelope: $element);

	}//end parse()

	/**
	 * Read the rates from an already parsed ECB envelope.
	 *
	 * Days with a malformed date and entries with a malformed currency code or
	 * a rate that is not a positive number are left out.
	 *
	 * @param SimpleXMLElement $envelope The parsed `gesmes:Envelope` root.
	 *
	 * @return array<string, array<string, float>> Rates keyed by date, then by currency code, newest date first.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
	 */
	public function fromElement(SimpleXMLElement $envelope): array {
		$rates = [];

		foreach ($this->cubes(element: $envelope) as $wrapper) {
			foreach ($this->cubes(element: $wrapper) as $day) {
				$this->readDay(day: $day, rates: $rates);
			}
		}

		krsort($rates);

		return $rates;

	}//end fromElement()

	/**
	 * Read one `Cube time` element into the rates.
	 *
	 * @param SimpleXMLElement $day The day element.
	 * @param array<string, array<string, float>> $rates The rates read so far.
	 *
	 * @return void
	 */
	private function readDay(SimpleXMLElement $day, array &$rates): void {
		$date = (string)$day['time'];
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return;
		}

		foreach ($this->cubes(element: $day) as $entry) {
			$currency = (string)$entry['currency'];
			$rate     = (string)$entry['rate'];
			if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || is_numeric($rate) === false || (float)$rate <= 0.0) {
				continue;
			}

			$rates[$date][$currency] = (float)$rate;
		}

	}//end readDay()

	/**
	 * The `Cube` children of an element, in document order.
	 *
	 * @param SimpleXMLElement $element The parent element.
	 *
	 * @return list<SimpleXMLElement>
	 */
	private function cubes(SimpleXMLElement $element): array {
		$cubes = [];
		foreach ($element->children() as $child) {
			if ($child->getName() === 'Cube') {
				$cubes[] = $child;
			}
		}

		return $cubes;

	}//end cubes()
}//end class
