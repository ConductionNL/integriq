<?php

/**
 * Unit tests for WusDigipoortProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Digipoort
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/tasks.md#task-1-source-type-and-providers
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Digipoort;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Digikoppeling\WusProfileService;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\DigipoortException;
use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Exception\MtlsConfigurationException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\Digipoort\SbrDelivery;
use OCA\Integriq\Service\Digipoort\WusDigipoortProvider;
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolverInterface;
use OCA\Integriq\Service\Mtls\MtlsHandshakeException;
use OCA\Integriq\Service\Mtls\MtlsTransportServiceInterface;
use OCA\Integriq\Service\Security\EgressGuardInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The WUS binding against recorded Digipoort answers, signed and unsigned.
 */
#[CoversClass(WusDigipoortProvider::class)]
#[UsesClass(SbrDelivery::class)]
#[UsesClass(DigipoortException::class)]
#[UsesClass(MtlsCertificateBundle::class)]
class WusDigipoortProviderTest extends TestCase {
	/**
	 * A recorded aanleverResponse.
	 */
	private const DELIVERED = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Header/><soap:Body>'
		. '<aan:aanleverResponse xmlns:aan="http://logius.nl/digipoort/koppelvlakservices/1.2/">'
		. '<aan:kenmerk>a6b54e1c-7f5e-4c1e-9f43-2d1e8b1d0a11</aan:kenmerk><aan:tijdstempelAangeleverd>2026-10-28T10:14:02+01:00</aan:tijdstempelAangeleverd>'
		. '</aan:aanleverResponse></soap:Body></soap:Envelope>';

	/**
	 * A recorded getStatussenProcesResponse: delivered, then rejected with a foutcode.
	 */
	private const REJECTED = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
		. '<sta:getStatussenProcesResponse xmlns:sta="http://logius.nl/digipoort/koppelvlakservices/1.2/"><sta:statussenProces>'
		. '<sta:StatusResultaat><sta:kenmerk>KNM-1</sta:kenmerk><sta:statuscode>100</sta:statuscode>'
		. '<sta:tijdstempelStatus>2026-10-28T10:14:02+01:00</sta:tijdstempelStatus><sta:statusomschrijving>Aanleveren gelukt</sta:statusomschrijving></sta:StatusResultaat>'
		. '<sta:StatusResultaat><sta:kenmerk>KNM-1</sta:kenmerk><sta:statuscode>510</sta:statuscode>'
		. '<sta:tijdstempelStatus>2026-10-28T10:24:37+01:00</sta:tijdstempelStatus><sta:statusomschrijving>Verwerking mislukt</sta:statusomschrijving>'
		. '<sta:statusFoutcode><sta:foutcode>OB0001</sta:foutcode><sta:foutbeschrijving>Het tijdvak is al aangegeven.</sta:foutbeschrijving></sta:statusFoutcode>'
		. '</sta:StatusResultaat></sta:statussenProces></sta:getStatussenProcesResponse></soap:Body></soap:Envelope>';

	/**
	 * A recorded getStatussenProcesResponse: delivered, then accepted.
	 */
	private const ACCEPTED = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
		. '<sta:getStatussenProcesResponse xmlns:sta="http://logius.nl/digipoort/koppelvlakservices/1.2/"><sta:statussenProces>'
		. '<sta:StatusResultaat><sta:statuscode>100</sta:statuscode><sta:tijdstempelStatus>2026-10-28T10:14:02+01:00</sta:tijdstempelStatus></sta:StatusResultaat>'
		. '<sta:StatusResultaat><sta:statuscode>500</sta:statuscode><sta:tijdstempelStatus>2026-10-28T10:24:37+01:00</sta:tijdstempelStatus>'
		. '<sta:statusomschrijving>Verwerking gelukt</sta:statusomschrijving></sta:StatusResultaat>'
		. '</sta:statussenProces></sta:getStatussenProcesResponse></soap:Body></soap:Envelope>';

	/**
	 * A recorded aanleverFault.
	 */
	private const FAULT = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault>'
		. '<faultcode>soap:Client</faultcode><faultstring>aanleverFault</faultstring><detail>'
		. '<aan:aanleverFault xmlns:aan="http://logius.nl/digipoort/koppelvlakservices/1.2/"><aan:foutcode>ALS100</aan:foutcode>'
		. '<aan:foutbeschrijving>De berichtsoort is onbekend.</aan:foutbeschrijving></aan:aanleverFault>'
		. '</detail></soap:Fault></soap:Body></soap:Envelope>';

	/**
	 * The source configuration of a preproduction source.
	 */
	private const CONFIGURATION = [
		'provider' => 'wus',
		'environment' => 'preproduction',
		'certificateRef' => 'pkio-kade-2026',
		'authentication' => ['mtls' => ['certificate' => 'x']],
		'endpoints' => [
			'preproduction' => [
				'aanleverUrl' => 'https://preprod-dgp2.procesinfrastructuur.nl/wus/2.0/aanleverservice/1.2',
				'statusUrl' => 'https://preprod-dgp2.procesinfrastructuur.nl/wus/2.0/statusinformatieservice/1.2',
			],
		],
	];

	/**
	 * The WUS profile double.
	 *
	 * @var WusProfileService&MockObject
	 */
	private WusProfileService&MockObject $wus;

	/**
	 * The mTLS resolver double.
	 *
	 * @var MtlsConfigResolverInterface&MockObject
	 */
	private MtlsConfigResolverInterface&MockObject $mtlsResolver;

	/**
	 * The mTLS transport double.
	 *
	 * @var MtlsTransportServiceInterface&MockObject
	 */
	private MtlsTransportServiceInterface&MockObject $transport;

	/**
	 * The egress guard double.
	 *
	 * @var EgressGuardInterface&MockObject
	 */
	private EgressGuardInterface&MockObject $egress;

	/**
	 * The provider under test.
	 *
	 * @var WusDigipoortProvider
	 */
	private WusDigipoortProvider $provider;

	/**
	 * Build the provider over its doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->wus = $this->createMock(WusProfileService::class);
		$this->mtlsResolver = $this->createMock(MtlsConfigResolverInterface::class);
		$this->mtlsResolver->method('resolve')->willReturn(new MtlsCertificateBundle(certificatePem: 'CERT', privateKeyPem: 'KEY'));
		$this->transport = $this->createMock(MtlsTransportServiceInterface::class);
		$this->egress = $this->createMock(EgressGuardInterface::class);

		$this->provider = new WusDigipoortProvider(
			$this->createMock(Client::class),
			$this->wus,
			$this->mtlsResolver,
			$this->transport,
			$this->egress
		);
	}//end setUp()

	/**
	 * A delivery signs the aanleverRequest with the source's certificate and returns Digipoort's kenmerk.
	 *
	 * @return void
	 */
	public function testADeliveryIsSignedSentAndAnsweredWithTheKenmerk(): void {
		$this->wus->expects($this->once())->method('buildSignedRequest')->with(
			'pkio-kade-2026',
			$this->callback(
				function (string $body): bool {
					$this->assertStringContainsString('<aan:berichtsoort>Omzetbelasting</aan:berichtsoort>', $body);
					$this->assertStringContainsString('<aan:aanleverkenmerk>SBR-0011223344556677</aan:aanleverkenmerk>', $body);
					$this->assertStringContainsString('<aan:nummer>001234567B01</aan:nummer><aan:type>Fi</aan:type>', $body);
					$this->assertStringContainsString('<aan:inhoud>' . base64_encode('<xbrl/>') . '</aan:inhoud>', $body);
					$this->assertStringContainsString('<aan:autorisatieAdres>http://geenausp.nl</aan:autorisatieAdres>', $body);
					return true;
				}
			)
		)->willReturn('<signed/>');
		$this->wus->expects($this->once())->method('verifyResponse')->with(self::DELIVERED)->willReturnArgument(0);
		$this->transport->expects($this->once())->method('request')->with(
			$this->anything(),
			'POST',
			'https://preprod-dgp2.procesinfrastructuur.nl/wus/2.0/aanleverservice/1.2',
			$this->callback(fn (array $options): bool => $options['body'] === '<signed/>'),
			$this->isInstanceOf(MtlsCertificateBundle::class)
		)->willReturn(new Response(200, [], self::DELIVERED));

		$kenmerk = $this->provider->deliver(configuration: self::CONFIGURATION, delivery: $this->delivery());

		$this->assertSame('a6b54e1c-7f5e-4c1e-9f43-2d1e8b1d0a11', $kenmerk);
	}//end testADeliveryIsSignedSentAndAnsweredWithTheKenmerk()

	/**
	 * Without resolvable certificate material the delivery fails closed, names the certificate and sends nothing.
	 *
	 * @return void
	 */
	public function testAMissingCertificateFailsClosedAndSendsNothing(): void {
		$this->wus->method('buildSignedRequest')->willThrowException(
			new DigikoppelingException(message: 'The credential broker returned no usable signing material for certificateRef "pkio-kade-2026".')
		);
		$this->transport->expects($this->never())->method('request');

		try {
			$this->provider->deliver(configuration: self::CONFIGURATION, delivery: $this->delivery());
			$this->fail('A delivery without certificate material must be refused.');
		} catch (DigipoortException $exception) {
			$this->assertStringContainsString('The PKIoverheid certificate pkio-kade-2026 cannot be used', $exception->getMessage());
			$this->assertTrue($exception->retryable);
		}
	}//end testAMissingCertificateFailsClosedAndSendsNothing()

	/**
	 * An answer whose signature does not verify is rejected.
	 *
	 * @return void
	 */
	public function testAnUnsignedAnswerIsRejected(): void {
		$this->wus->method('buildSignedRequest')->willReturn('<signed/>');
		$this->wus->method('verifyResponse')->willThrowException(
			new DigikoppelingException(message: 'WUS response rejected: the WS-Security signature is missing or does not verify (transport error).')
		);
		$this->transport->method('request')->willReturn(new Response(200, [], self::DELIVERED));

		$this->expectException(DigipoortException::class);
		$this->expectExceptionMessage('WUS response rejected');

		$this->provider->deliver(configuration: self::CONFIGURATION, delivery: $this->delivery());
	}//end testAnUnsignedAnswerIsRejected()

	/**
	 * A Digipoort fault is refused with its description, before the signature is even looked at.
	 *
	 * @return void
	 */
	public function testAFaultIsRefusedWithItsDescription(): void {
		$this->wus->method('buildSignedRequest')->willReturn('<signed/>');
		$this->wus->expects($this->never())->method('verifyResponse');
		$this->transport->method('request')->willReturn(new Response(500, [], self::FAULT));

		$this->expectException(DigipoortException::class);
		$this->expectExceptionMessage('Digipoort refused the request (HTTP 500): De berichtsoort is onbekend.');

		$this->provider->deliver(configuration: self::CONFIGURATION, delivery: $this->delivery());
	}//end testAFaultIsRefusedWithItsDescription()

	/**
	 * A status with a foutcode is a rejection carrying the code and text; the rest are deliveries.
	 *
	 * @return void
	 */
	public function testStatusesMapAFoutcodeToARejection(): void {
		$this->wus->expects($this->once())->method('buildSignedRequest')->with(
			'pkio-kade-2026',
			$this->stringContains('<aan:kenmerk>KNM-1</aan:kenmerk>')
		)->willReturn('<signed/>');
		$this->wus->method('verifyResponse')->willReturnArgument(0);
		$this->transport->expects($this->once())->method('request')->with(
			$this->anything(),
			'POST',
			'https://preprod-dgp2.procesinfrastructuur.nl/wus/2.0/statusinformatieservice/1.2',
			$this->anything(),
			$this->anything()
		)->willReturn(new Response(200, [], self::REJECTED));

		$statuses = $this->provider->statuses(configuration: self::CONFIGURATION, deliveryReference: 'KNM-1');

		$this->assertSame(
			[
				[
					'status' => 'delivered',
					'code' => '100',
					'description' => 'Aanleveren gelukt',
					'at' => '2026-10-28T10:14:02+01:00',
					'errors' => [],
				],
				[
					'status' => 'rejected',
					'code' => '510',
					'description' => 'Verwerking mislukt',
					'at' => '2026-10-28T10:24:37+01:00',
					'errors' => [['code' => 'OB0001', 'text' => 'Het tijdvak is al aangegeven.']],
				],
			],
			$statuses
		);
	}//end testStatusesMapAFoutcodeToARejection()

	/**
	 * An accepted code from the default list, or from the source's own list, is an acceptance.
	 *
	 * @return void
	 */
	public function testAcceptedCodesFollowTheSource(): void {
		$this->wus->method('buildSignedRequest')->willReturn('<signed/>');
		$this->wus->method('verifyResponse')->willReturnArgument(0);
		$this->transport->method('request')->willReturnCallback(fn (): Response => new Response(200, [], self::ACCEPTED));

		$default = $this->provider->statuses(configuration: self::CONFIGURATION, deliveryReference: 'KNM-1');
		$custom = $this->provider->statuses(configuration: (['acceptedStatusCodes' => ['100']] + self::CONFIGURATION), deliveryReference: 'KNM-1');

		$this->assertSame(['delivered', 'accepted'], array_column($default, 'status'));
		$this->assertSame(['accepted', 'delivered'], array_column($custom, 'status'));
	}//end testAcceptedCodesFollowTheSource()

	/**
	 * A source without an environment is refused before anything is signed.
	 *
	 * @return void
	 */
	public function testASourceWithoutAnEnvironmentIsRefused(): void {
		$configuration = self::CONFIGURATION;
		unset($configuration['environment']);
		$this->wus->expects($this->never())->method('buildSignedRequest');

		$this->expectException(DigipoortException::class);
		$this->expectExceptionMessage('names no environment');

		$this->provider->deliver(configuration: $configuration, delivery: $this->delivery());
	}//end testASourceWithoutAnEnvironmentIsRefused()

	/**
	 * A production source without production endpoints is refused.
	 *
	 * @return void
	 */
	public function testAnEnvironmentWithoutEndpointsIsRefused(): void {
		$this->expectException(DigipoortException::class);
		$this->expectExceptionMessage('The Digipoort source has no aanleverUrl for production.');

		$this->provider->deliver(configuration: (['environment' => 'production'] + self::CONFIGURATION), delivery: $this->delivery());
	}//end testAnEnvironmentWithoutEndpointsIsRefused()

	/**
	 * An endpoint the egress guard refuses is never called.
	 *
	 * @return void
	 */
	public function testARefusedEndpointIsNotCalled(): void {
		$this->egress->method('assertAllowed')->willThrowException(new EgressRefusedException('private address'));
		$this->transport->expects($this->never())->method('request');

		$this->expectException(DigipoortException::class);
		$this->expectExceptionMessage('The Digipoort endpoint may not be called: private address');

		$this->provider->deliver(configuration: self::CONFIGURATION, delivery: $this->delivery());
	}//end testARefusedEndpointIsNotCalled()

	/**
	 * Without a usable client certificate nothing is sent.
	 *
	 * @return void
	 */
	public function testAMissingClientCertificateSendsNothing(): void {
		$this->wus->method('buildSignedRequest')->willReturn('<signed/>');
		$this->mtlsResolver = $this->createMock(MtlsConfigResolverInterface::class);
		$this->mtlsResolver->method('resolve')->willThrowException(
			new MtlsConfigurationException('no certificate configured', MtlsTransportException::ERROR_HANDSHAKE_FAILED)
		);
		$this->transport->expects($this->never())->method('request');
		$provider = new WusDigipoortProvider($this->createMock(Client::class), $this->wus, $this->mtlsResolver, $this->transport, $this->egress);

		$this->expectException(DigipoortException::class);
		$this->expectExceptionMessage('The client certificate for https://preprod-dgp2.procesinfrastructuur.nl/wus/2.0/aanleverservice/1.2 cannot be used');

		$provider->deliver(configuration: self::CONFIGURATION, delivery: $this->delivery());
	}//end testAMissingClientCertificateSendsNothing()

	/**
	 * A transport failure is reported as unreachable.
	 *
	 * @return void
	 */
	public function testATransportFailureIsReported(): void {
		$this->wus->method('buildSignedRequest')->willReturn('<signed/>');
		$this->transport->method('request')->willThrowException(
			new MtlsHandshakeException(message: 'handshake failed', errorCode: MtlsTransportException::ERROR_HANDSHAKE_FAILED)
		);

		$this->expectException(DigipoortException::class);
		$this->expectExceptionMessage('Digipoort could not be reached: handshake failed');

		$this->provider->deliver(configuration: self::CONFIGURATION, delivery: $this->delivery());
	}//end testATransportFailureIsReported()

	/**
	 * An answer without a kenmerk is not a delivery.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutAKenmerkIsNotADelivery(): void {
		$this->wus->method('buildSignedRequest')->willReturn('<signed/>');
		$this->wus->method('verifyResponse')->willReturnArgument(0);
		$this->transport->method('request')->willReturn(new Response(200, [], '<ok/>'));

		$this->expectException(DigipoortException::class);
		$this->expectExceptionMessage('without a kenmerk');

		$this->provider->deliver(configuration: self::CONFIGURATION, delivery: $this->delivery());
	}//end testAnAnswerWithoutAKenmerkIsNotADelivery()

	/**
	 * The delivery used throughout.
	 *
	 * @return SbrDelivery
	 */
	private function delivery(): SbrDelivery {
		return new SbrDelivery(
			berichtsoort: 'Omzetbelasting',
			aanleverkenmerk: 'SBR-0011223344556677',
			identityNumber: '001234567B01',
			identityType: 'Fi',
			instance: '<xbrl/>',
			fileName: 'ob-2026-q3.xbrl'
		);
	}//end delivery()
}//end class
