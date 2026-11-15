<?php

/**
 * SOAPService unit tests
 *
 * The SOAP engine a source gets, driven end to end over a mocked HTTP
 * handler: the WSDL is fetched through the source's own HTTP client, the
 * action goes to the source's location, and the SOAP version follows the
 * source configuration (1.1 when named so, 1.2 otherwise). A source without
 * a WSDL is refused, and only SOAP 1.1 and 1.2 are accepted.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Service\SOAPService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Unit tests for the SOAP engine set-up.
 *
 * @covers \OCA\Integriq\Service\SOAPService
 */
class SOAPServiceTest extends TestCase {

	private const WSDL = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<definitions xmlns="http://schemas.xmlsoap.org/wsdl/" xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/"
	xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:tns="urn:test" targetNamespace="urn:test">
	<types>
		<xsd:schema targetNamespace="urn:test" elementFormDefault="qualified">
			<xsd:element name="Echo"><xsd:complexType><xsd:sequence><xsd:element name="text" type="xsd:string"/></xsd:sequence></xsd:complexType></xsd:element>
			<xsd:element name="EchoResponse"><xsd:complexType><xsd:sequence><xsd:element name="result" type="xsd:string"/></xsd:sequence></xsd:complexType></xsd:element>
		</xsd:schema>
	</types>
	<message name="EchoIn"><part name="parameters" element="tns:Echo"/></message>
	<message name="EchoOut"><part name="parameters" element="tns:EchoResponse"/></message>
	<portType name="EchoPort"><operation name="Echo"><input message="tns:EchoIn"/><output message="tns:EchoOut"/></operation></portType>
	<binding name="EchoBinding" type="tns:EchoPort">
		<soap:binding style="document" transport="http://schemas.xmlsoap.org/soap/http"/>
		<operation name="Echo"><soap:operation soapAction="urn:test#Echo"/><input><soap:body use="literal"/></input><output><soap:body use="literal"/></output></operation>
	</binding>
	<service name="EchoService"><port name="EchoPort" binding="tns:EchoBinding"><soap:address location="https://soap.example.test/echo"/></port></service>
</definitions>
XML;

	private const ANSWER_11 = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:t="urn:test">'
		. '<SOAP-ENV:Body><t:EchoResponse><t:result>hallo terug</t:result></t:EchoResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';

	private const ANSWER_12 = '<?xml version="1.0"?><env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope" xmlns:t="urn:test">'
		. '<env:Body><t:EchoResponse><t:result>hallo terug</t:result></t:EchoResponse></env:Body></env:Envelope>';

	/**
	 * The requests the handler saw.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $history = [];

	public function testTheEngineFetchesTheWsdlThroughTheSourceClientAndSendsTheAction(): void {
		$engine = (new SOAPService(cookieJar: new CookieJar()))->setupEngine(
			source: $this->source(['wsdl' => 'https://soap.example.test/echo?wsdl', 'soapVersion' => '1.1', 'permanentWsdl' => false]),
			passedConfig: $this->config()
		);

		$result = $engine->request('Echo', [['text' => 'hallo']]);

		$this->assertSame('hallo terug', $result->result);
		$this->assertSame('https://soap.example.test/echo?wsdl', (string)$this->history[0]['request']->getUri());
		$this->assertCount(2, $this->history);
		$call = $this->history[1]['request'];
		$this->assertSame('POST', $call->getMethod());
		$this->assertSame('https://soap.example.test/location', (string)$call->getUri());
		$this->assertStringContainsString('>hallo</', (string)$call->getBody());
		$this->assertStringContainsString('urn:test#Echo', $call->getHeaderLine('SOAPAction'));
		$this->assertStringStartsWith('text/xml', $call->getHeaderLine('Content-Type'));
	}//end testTheEngineFetchesTheWsdlThroughTheSourceClientAndSendsTheAction()

	public function testAnUnnamedOrNumericTwoVersionSpeaksSoapOnePointTwo(): void {
		foreach ([null, 2, '1.2'] as $version) {
			$engine = (new SOAPService(cookieJar: new CookieJar()))->setupEngine(
				source: $this->source(['wsdl' => 'https://soap.example.test/echo?wsdl', 'soapVersion' => $version, 'permanentWsdl' => 'true']),
				passedConfig: $this->config(answer: self::ANSWER_12)
			);

			$this->assertSame('hallo terug', $engine->request('Echo', [['text' => 'hallo']])->result);
			$this->assertStringStartsWith('application/soap+xml', end($this->history)['request']->getHeaderLine('Content-Type'), (string)$version);
		}
	}//end testAnUnnamedOrNumericTwoVersionSpeaksSoapOnePointTwo()

	public function testASourceWithoutAWsdlIsRefused(): void {
		$this->expectException(ConfigException::class);
		$this->expectExceptionMessage('No wsdl provided');

		(new SOAPService(cookieJar: new CookieJar()))->setupEngine(source: $this->source([]), passedConfig: []);
	}//end testASourceWithoutAWsdlIsRefused()

	public function testOnlySoapOneAndTwoAreAccepted(): void {
		$this->expectException(BadRequestHttpException::class);
		$this->expectExceptionMessage('only soap 1.1 and 1.2 are supported');

		(new SOAPService(cookieJar: new CookieJar()))->setupEngine(
			source: $this->source(['wsdl' => 'https://soap.example.test/echo?wsdl', 'soapVersion' => 3, 'permanentWsdl' => false]),
			passedConfig: $this->config()
		);
	}//end testOnlySoapOneAndTwoAreAccepted()

	public function testCallSoapSourceRefusesASourceWithoutAWsdlAndRestoresTheNullEntityLoader(): void {
		libxml_set_external_entity_loader(static fn (): string => '/permissive');

		try {
			(new SOAPService(cookieJar: new CookieJar()))->callSoapSource(
				source: $this->source([]),
				soapAction: 'Echo',
				config: ['body' => '{"text":"hallo"}']
			);
			$this->fail('A source without a WSDL must be refused.');
		} catch (ConfigException $exception) {
			$this->assertSame('No wsdl provided', $exception->getMessage());
		}

		$loader = libxml_get_external_entity_loader();
		$this->assertIsCallable($loader);
		$this->assertNull($loader('public', 'file:///etc/hostname', []));
	}//end testCallSoapSourceRefusesASourceWithoutAWsdlAndRestoresTheNullEntityLoader()

	/**
	 * A SOAP source.
	 *
	 * @param array<string, mixed> $configuration The source configuration.
	 *
	 * @return ObjectEntity
	 */
	private function source(array $configuration): ObjectEntity {
		$source = new ObjectEntity();
		$source->setObject(['location' => 'https://soap.example.test/location', 'configuration' => $configuration]);

		return $source;
	}//end source()

	/**
	 * The client config: a mocked handler serving the WSDL, then the answer.
	 *
	 * @param string $answer The SOAP answer.
	 *
	 * @return array<string, mixed>
	 */
	private function config(string $answer = self::ANSWER_11): array {
		$this->history = [];
		// Answered by URL, not by order: a permanent WSDL is cached on disk and
		// is then not fetched again.
		$stack = HandlerStack::create(
			static function (RequestInterface $request) use ($answer): PromiseInterface {
				if (str_ends_with((string)$request->getUri(), '?wsdl') === true) {
					return Create::promiseFor(new Response(200, ['Content-Type' => 'text/xml'], self::WSDL));
				}

				return Create::promiseFor(new Response(200, ['Content-Type' => 'text/xml; charset=utf-8'], $answer));
			}
		);
		$stack->push(Middleware::history($this->history));
		return ['handler' => $stack];
	}//end config()
}//end class
