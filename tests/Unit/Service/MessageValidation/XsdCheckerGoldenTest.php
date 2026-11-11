<?php

/**
 * Golden test for XsdChecker::check.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\MessageValidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\MessageValidation;

use OCA\Integriq\Service\MessageValidation\XsdChecker;
use PHPUnit\Framework\TestCase;

/**
 * Pins the outcome of every XSD check path — valid, invalid, remote references,
 * unparsable schema or message, non-text message — and that libxml's error
 * mode is restored afterwards.
 */
class XsdCheckerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/message-validation/xsd-outcomes.json';

	private const XSD = '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="persoon"><xs:complexType><xs:sequence>'
		.'<xs:element name="bsn" type="xs:string"/><xs:element name="leeftijd" type="xs:integer"/></xs:sequence></xs:complexType></xs:element></xs:schema>';

	/**
	 * The errors with libxml's own wording, which differs between libxml builds, cut off.
	 *
	 * @param array<int, array{path: string, message: string}> $errors The errors.
	 *
	 * @return array<int, array{path: string, message: string}> The distinct normalised errors.
	 */
	private static function normalised(array $errors): array {
		$out = [];
		foreach ($errors as $error) {
			$error['message'] = (string) preg_replace('/Line \\d+: .*/s', 'Line …', $error['message']);
			$out[json_encode($error)] = $error;
		}

		return array_values($out);

	}//end normalised()

	/**
	 * Every check matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryCheckMatchesTheGoldenFile(): void {
		$remote = '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:import namespace="urn:a" schemaLocation=" https://example.org/a.xsd "/>'
			.'<xs:include schemaLocation="local.xsd"/><xs:redefine schemaLocation="http://example.org/b.xsd"/><xs:element name="x"/></xs:schema>';
		$cases  = [
			'valid' => [self::XSD, '<persoon><bsn>1</bsn><leeftijd>3</leeftijd></persoon>'],
			'invalid' => [self::XSD, '<persoon><leeftijd>drie</leeftijd></persoon>'],
			'remote' => [$remote, '<x/>'],
			'bad-schema' => ['<xs:schema', '<x/>'],
			'bad-message' => [self::XSD, '<persoon>'],
			'unusable-schema' => ['<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="x" type="nope"/></xs:schema>', '<x/>'],
			'array' => [self::XSD, ['persoon' => []]],
			'null' => [self::XSD, null],
		];

		$checker = new XsdChecker();
		$actual  = [];
		foreach ($cases as $name => [$xsd, $payload]) {
			$before        = libxml_use_internal_errors(false);
			$outcome       = $checker->check($xsd, $payload);
			$after         = libxml_use_internal_errors($before);
			$actual[$name] = ['valid' => $outcome->isValid(), 'errors' => self::normalised(errors: $outcome->errors()), 'internalErrorsRestored' => ($after === false)];
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true), $actual);

	}//end testEveryCheckMatchesTheGoldenFile()
}//end class
