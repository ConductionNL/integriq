<?php

/**
 * Golden recording of the StUF-BG service: for each npsLv01, adrLv01 and
 * npsLa01 message, what it answers and every builder, mapper, search and log
 * call in order. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\StUFBGService;
use OCA\Integriq\Service\StUFFieldMapper;
use OCA\Integriq\Service\StUFXMLBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every reading of the StUF-BG requests and responses.
 *
 * @spec openspec/specs/stuf-adapter/spec.md
 */
class StUFBGServiceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/stuf-bg-service.json';

	private const SOAP11 = 'http://schemas.xmlsoap.org/soap/envelope/';

	private const SOAP12 = 'http://www.w3.org/2003/05/soap-envelope';

	private const STUF = 'http://www.egem.nl/StUF/StUF0301';

	private const BG = 'http://www.egem.nl/StUF/sector/bg/0310';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Wrap a body in an envelope.
	 *
	 * @param string $body  The body's content.
	 * @param string $attrs The envelope's namespace declarations (default: SOAP 1.1, StUF, BG).
	 *
	 * @return string The envelope.
	 */
	private static function envelope(string $body, string $attrs = ''): string {
		if ($attrs === '') {
			$attrs = ' xmlns:SOAP-ENV="' . self::SOAP11 . '" xmlns:StUF="' . self::STUF . '" xmlns:BG="' . self::BG . '"';
		}

		return '<SOAP-ENV:Envelope' . $attrs . '><SOAP-ENV:Body>' . $body . '</SOAP-ENV:Body></SOAP-ENV:Envelope>';

	}//end envelope()

	/**
	 * An unprefixed element carrying StUF parameters and stuurgegevens.
	 *
	 * @param string|null $maximum   The maximumAantal, or null for no parameters.
	 * @param string|null $reference The referentienummer, or null for no stuurgegevens.
	 *
	 * @return string The element.
	 */
	private static function wrapper(?string $maximum, ?string $reference): string {
		$inner = '';
		if ($maximum !== null) {
			$inner .= '<StUF:parameters><StUF:maximumAantal>' . $maximum . '</StUF:maximumAantal></StUF:parameters>';
		}

		if ($reference !== null) {
			$inner .= '<StUF:stuurgegevens><StUF:referentienummer>' . $reference . '</StUF:referentienummer></StUF:stuurgegevens>';
		}

		return '<wrap>' . $inner . '</wrap>';

	}//end wrapper()

	/**
	 * The npsLv01 requests.
	 *
	 * @return array<string,string>
	 */
	private static function npsRequests(): array {
		$full = '<BG:npsLv01><StUF:stuurgegevens><StUF:referentienummer>REF-1</StUF:referentienummer></StUF:stuurgegevens>'
			. '<StUF:parameters><StUF:maximumAantal>5</StUF:maximumAantal></StUF:parameters>'
			. '<BG:gelijk><BG:inp.bsn>999993653</BG:inp.bsn><BG:geslachtsnaam>Moulin</BG:geslachtsnaam>'
			. '<BG:voorvoegselGeslachtsnaam>de</BG:voorvoegselGeslachtsnaam><BG:voornamen>Suzanne</BG:voornamen>'
			. '<BG:geboortedatum>19800101</BG:geboortedatum><BG:voornamen2></BG:voornamen2></BG:gelijk>'
			. '<BG:scope><BG:object><BG:inp.bsn/><BG:geslachtsnaam/></BG:object></BG:scope></BG:npsLv01>';
		$bsn = '<BG:gelijk><BG:inp.bsn>1</BG:inp.bsn></BG:gelijk>';
		return [
			'malformed'                  => 'not xml',
			'no body'                    => '<SOAP-ENV:Envelope xmlns:SOAP-ENV="' . self::SOAP11 . '"><SOAP-ENV:Header/></SOAP-ENV:Envelope>',
			'no criteria'                => self::envelope('<BG:npsLv01><BG:gelijk/></BG:npsLv01>'),
			'no gelijk'                  => self::envelope('<BG:npsLv01/>'),
			'no npsLv01'                 => self::envelope('<BG:other/>'),
			'empty criterion only'       => self::envelope('<BG:npsLv01><BG:gelijk><BG:inp.bsn></BG:inp.bsn></BG:gelijk></BG:npsLv01>'),
			'full request'               => self::envelope($full),
			'maximum zero'               => self::envelope('<BG:npsLv01><StUF:parameters><StUF:maximumAantal>0</StUF:maximumAantal></StUF:parameters>' . $bsn . '</BG:npsLv01>'),
			'maximum blank'              => self::envelope('<BG:npsLv01><StUF:parameters><StUF:maximumAantal></StUF:maximumAantal></StUF:parameters>' . $bsn . '</BG:npsLv01>'),
			'parameters without maximum' => self::envelope('<BG:npsLv01><StUF:parameters/>' . $bsn . '</BG:npsLv01>'),
			'blank reference'            => self::envelope('<BG:npsLv01><StUF:stuurgegevens><StUF:referentienummer></StUF:referentienummer></StUF:stuurgegevens>' . $bsn . '</BG:npsLv01>'),
			'stuurgegevens without ref'  => self::envelope('<BG:npsLv01><StUF:stuurgegevens/>' . $bsn . '</BG:npsLv01>'),
			'empty scope object'         => self::envelope('<BG:npsLv01>' . $bsn . '<BG:scope><BG:object/></BG:scope></BG:npsLv01>'),
			'scope without object'       => self::envelope('<BG:npsLv01>' . $bsn . '<BG:scope/></BG:npsLv01>'),
			'no stuf namespace'          => self::envelope('<BG:npsLv01>' . $bsn . '</BG:npsLv01>', ' xmlns:SOAP-ENV="' . self::SOAP11 . '" xmlns:BG="' . self::BG . '"'),
			'no bg-named prefix'         => self::envelope('<P:npsLv01 xmlns:P="' . self::BG . '"><P:gelijk><P:inp.bsn>1</P:inp.bsn></P:gelijk></P:npsLv01>', ' xmlns:SOAP-ENV="' . self::SOAP11 . '"'),
			'unprefixed wrapper, maximum 5 and a ref' => self::envelope(self::wrapper(maximum: '5', reference: 'REF-W') . '<BG:npsLv01>' . $bsn . '</BG:npsLv01>'),
			'unprefixed wrapper, maximum 0'           => self::envelope(self::wrapper(maximum: '0', reference: '') . '<BG:npsLv01>' . $bsn . '</BG:npsLv01>'),
			'unprefixed wrapper, maximum blank'       => self::envelope(self::wrapper(maximum: '', reference: null) . '<BG:npsLv01>' . $bsn . '</BG:npsLv01>'),
			'unprefixed wrapper, maximum abc'         => self::envelope(self::wrapper(maximum: 'abc', reference: 'R') . '<BG:npsLv01>' . $bsn . '</BG:npsLv01>'),
			'unprefixed wrapper, no criteria'         => self::envelope(self::wrapper(maximum: '5', reference: 'R')),
			'a blank ref, then a ref'                 => self::envelope(self::wrapper(maximum: null, reference: '') . self::wrapper(maximum: null, reference: 'R3') . '<BG:npsLv01>' . $bsn . '</BG:npsLv01>'),
			'parameters without maximum, then 9'      => self::envelope('<wrap><StUF:parameters/></wrap>' . self::wrapper(maximum: '9', reference: null) . '<BG:npsLv01>' . $bsn . '</BG:npsLv01>'),
			'bg used before stuf'                     => self::envelope('<BG:npsLv01>' . $bsn . '</BG:npsLv01>' . self::wrapper(maximum: '5', reference: 'REF-B')),
			'two wrappers, the first has none'        => self::envelope('<a/>' . self::wrapper(maximum: '7', reference: 'R2') . '<BG:npsLv01>' . $bsn . '</BG:npsLv01>'),
			'soap 1.2 without soap prefix' => '<env:Envelope xmlns:env="' . self::SOAP12 . '" xmlns:BG="' . self::BG . '"><env:Body><BG:npsLv01>' . $bsn . '</BG:npsLv01></env:Body></env:Envelope>',
			'soap 1.1 without soap prefix' => '<e:Envelope xmlns:e="' . self::SOAP11 . '" xmlns:BG="' . self::BG . '"><e:Body><BG:npsLv01>' . $bsn . '</BG:npsLv01></e:Body></e:Envelope>',
		];

	}//end npsRequests()

	/**
	 * The adrLv01 requests.
	 *
	 * @return array<string,string>
	 */
	private static function adrRequests(): array {
		return [
			'malformed'         => '<<',
			'no body'           => '<SOAP-ENV:Envelope xmlns:SOAP-ENV="' . self::SOAP11 . '"/>',
			'full request'      => self::envelope(
				'<BG:adrLv01><StUF:stuurgegevens><StUF:referentienummer>REF-2</StUF:referentienummer></StUF:stuurgegevens>'
				. '<BG:gelijk><BG:postcode>1234AB</BG:postcode><BG:huisnummer>7</BG:huisnummer><BG:straatnaam>Laan</BG:straatnaam>'
				. '<BG:woonplaats>Hier</BG:woonplaats><BG:huisletter></BG:huisletter></BG:gelijk></BG:adrLv01>'
			),
			'no criteria'       => self::envelope('<BG:adrLv01><BG:gelijk/></BG:adrLv01>'),
			'no gelijk'         => self::envelope('<BG:adrLv01/>'),
			'no adrLv01'        => self::envelope('<BG:npsLv01/>'),
			'blank postcode'    => self::envelope('<BG:adrLv01><BG:gelijk><BG:postcode></BG:postcode><BG:huisnummer>1</BG:huisnummer></BG:gelijk></BG:adrLv01>'),
			'unprefixed wrapper with a ref' => self::envelope(self::wrapper(maximum: null, reference: 'REF-A') . '<BG:adrLv01><BG:gelijk><BG:postcode>1</BG:postcode></BG:gelijk></BG:adrLv01>'),
			'no bg namespace'   => self::envelope('<P:adrLv01 xmlns:P="' . self::BG . '"><P:gelijk><P:postcode>1</P:postcode></P:gelijk></P:adrLv01>', ' xmlns:SOAP-ENV="' . self::SOAP11 . '"'),
		];

	}//end adrRequests()

	/**
	 * The npsLa01 responses.
	 *
	 * @return array<string,string>
	 */
	private static function npsResponses(): array {
		$attrs = ' xmlns:SOAP-ENV="' . self::SOAP11 . '" xmlns:StUF="' . self::STUF . '" xmlns:BG="' . self::BG . '"';
		return [
			'malformed'       => 'nope',
			'no body'         => '<SOAP-ENV:Envelope' . $attrs . '/>',
			'no npsLa01'      => self::envelope('<BG:other/>'),
			'no antwoord'     => self::envelope('<BG:npsLa01/>'),
			'empty antwoord'  => self::envelope('<BG:npsLa01><BG:antwoord/></BG:npsLa01>'),
			'two persons'     => self::envelope(
				'<BG:npsLa01><BG:antwoord>'
				. '<BG:object><BG:inp.bsn>1</BG:inp.bsn><BG:geslachtsnaam StUF:noValue="geenWaarde"/>'
				. '<BG:voornamen StUF:noValue="nietOndersteund"/>'
				. '<BG:verblijfsadres><BG:postcode>1234AB</BG:postcode><BG:huisnummer>7</BG:huisnummer></BG:verblijfsadres></BG:object>'
				. '<BG:object><BG:inp.bsn>2</BG:inp.bsn><BG:verblijfsadres StUF:noValue="nietOndersteund"/></BG:object>'
				. '</BG:antwoord></BG:npsLa01>'
			),
			'stuf used first' => self::envelope(
				'<StUF:stuurgegevens/><BG:npsLa01><BG:antwoord><BG:object><BG:inp.bsn>3</BG:inp.bsn>'
				. '<BG:geslachtsnaam StUF:noValue="geenWaarde"/><BG:voornamen StUF:noValue="nietOndersteund"/>'
				. '<BG:verblijfsadres><BG:postcode>9999ZZ</BG:postcode></BG:verblijfsadres></BG:object></BG:antwoord></BG:npsLa01>'
			),
			'no bg namespace' => self::envelope('<P:npsLa01 xmlns:P="' . self::BG . '"><P:antwoord><P:object><P:inp.bsn>1</P:inp.bsn></P:object></P:antwoord></P:npsLa01>', ' xmlns:SOAP-ENV="' . self::SOAP11 . '"'),
		];

	}//end npsResponses()

	/**
	 * Every message answers and calls exactly what the recording holds.
	 *
	 * Namespaces are found by prefix or URI hint in document order, so a
	 * request that uses the BG namespace before the StUF one reads its StUF
	 * parts in the BG namespace; the recording keeps that as it is.
	 *
	 * @return void
	 */
	public function testEveryMessageMatchesTheRecording(): void {
		$service = $this->service();
		$stuurgegevens = ['zenderOrganisatie' => 'Z', 'ontvangerOrganisatie' => 'O'];
		// The malformed messages make libxml warn; collect those quietly.
		$previous = libxml_use_internal_errors(true);
		try {
			$found = $this->record(service: $service, stuurgegevens: $stuurgegevens);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, $found[$name], $name);
		}

	}//end testEveryMessageMatchesTheRecording()

	/**
	 * Run every message and record what it answers and calls.
	 *
	 * @param StUFBGService        $service       The service.
	 * @param array<string,string> $stuurgegevens The stuurgegevens for the replies.
	 *
	 * @return array<string,array{answer:mixed,calls:list<string>}>
	 */
	private function record(StUFBGService $service, array $stuurgegevens): array {
		$found = [];
		foreach (self::npsRequests() as $name => $xml) {
			$this->calls = [];
			$answer = $service->handleNpsLv01(soapXml: $xml, stuurgegevens: $stuurgegevens);
			$found['npsLv01 ' . $name] = ['answer' => $answer, 'calls' => $this->calls];
		}

		foreach (self::adrRequests() as $name => $xml) {
			$this->calls = [];
			$answer = $service->handleAdrLv01(soapXml: $xml, stuurgegevens: $stuurgegevens);
			$found['adrLv01 ' . $name] = ['answer' => $answer, 'calls' => $this->calls];
		}

		foreach (self::npsResponses() as $name => $xml) {
			$this->calls = [];
			$answer = $service->parseNpsLa01Response(soapXml: $xml);
			$found['npsLa01 ' . $name] = ['answer' => $answer, 'calls' => $this->calls];
		}

		return $found;

	}//end record()

	/**
	 * The service over collaborators that log every call.
	 *
	 * @return StUFBGService
	 */
	private function service(): StUFBGService {
		$log = function (string $what, array $args): void {
			$this->calls[] = $what . ' ' . json_encode($args, JSON_UNESCAPED_SLASHES);
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(fn (...$args) => $log('warning', $args));
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnCallback(function (...$args) use ($log): array {
			$log('findAll', $args);
			return ['results' => [['id' => 'r-1'], ['id' => 'r-2']]];
		});
		$mapper = $this->createMock(StUFFieldMapper::class);
		foreach (['mapPersonToStUF', 'mapAddressToStUF', 'mapStUFToPerson'] as $method) {
			$mapper->method($method)->willReturnCallback(function (...$args) use ($log, $method): array {
				$log($method, $args);
				return ['mapped by ' . $method => $args];
			});
		}

		$builder = $this->createMock(StUFXMLBuilder::class);
		foreach (['buildFo01', 'buildNpsLa01', 'buildAdrLa01'] as $method) {
			$builder->method($method)->willReturnCallback(function (...$args) use ($log, $method): string {
				$log($method, $args);
				return '<' . $method . '/>';
			});
		}

		return new StUFBGService(logger: $logger, orObjectService: $objects, fieldMapper: $mapper, xmlBuilder: $builder);

	}//end service()
}//end class
