<?php

/**
 * Integriq — the subscription check's request, its refusals and how it reads Logius' answer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Berichtenbox
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

namespace OCA\Integriq\Tests\Unit\Adapters\Berichtenbox;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxException;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxValidatieClient;
use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Exception\MtlsConfigurationException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolverInterface;
use OCA\Integriq\Service\Mtls\MtlsTransportServiceInterface;
use OCA\Integriq\Service\Security\EgressGuardInterface;
use PHPUnit\Framework\TestCase;

/**
 * REQ-DPA-009: allowed and refused outcomes of one subscription check, in the order they are checked.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-subscription-is-checked-before-every-send-req-dpa-009
 */
class BerichtenboxValidatieClientTest extends TestCase {

	private const ENDPOINT = 'https://wus.example/BerichtenboxValidatieService';

	private const CONFIG = ['wusEndpoint' => self::ENDPOINT, 'senderOin' => '00000001234567890000', 'authentication' => ['mode' => 'mtls']];

	/**
	 * What happened, in order: egress, resolve, request.
	 *
	 * @var array<int,mixed>
	 */
	private array $calls = [];

	/**
	 * A client whose collaborators record their calls and behave as told.
	 *
	 * @param Response|\Throwable    $answer   What the transport answers or throws.
	 * @param \Throwable|null        $egress   What the egress guard throws, if anything.
	 * @param \Throwable|null        $resolve  What the certificate resolver throws, if anything.
	 *
	 * @return BerichtenboxValidatieClient
	 */
	private function client(Response|\Throwable $answer, ?\Throwable $egress = null, ?\Throwable $resolve = null): BerichtenboxValidatieClient {
		$bundle = new MtlsCertificateBundle('CERT', 'KEY', null, null);

		$guard = $this->createMock(EgressGuardInterface::class);
		$guard->method('assertAllowed')->willReturnCallback(
			function (string $url) use ($egress): void {
				$this->calls[] = ['egress', $url];
				if ($egress !== null) {
					throw $egress;
				}
			}
		);

		$resolver = $this->createMock(MtlsConfigResolverInterface::class);
		$resolver->method('resolve')->willReturnCallback(
			function (array $auth) use ($resolve, $bundle): MtlsCertificateBundle {
				$this->calls[] = ['resolve', $auth];
				if ($resolve !== null) {
					throw $resolve;
				}

				return $bundle;
			}
		);

		$transport = $this->createMock(MtlsTransportServiceInterface::class);
		$transport->method('request')->willReturnCallback(
			function (Client $client, string $method, string $url, array $options, MtlsCertificateBundle $used) use ($answer, $bundle): Response {
				$this->calls[] = ['request', $method, $url, $options, $used === $bundle];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		return new BerichtenboxValidatieClient(new Client(), $resolver, $transport, $guard);
	}//end client()

	/**
	 * A SOAP answer with the given subscriptions.
	 *
	 * @param array<string,string> $subscriptions BSN => isBerichtSturen text.
	 *
	 * @return string The answer.
	 */
	private function answer(array $subscriptions): string {
		$items = '';
		foreach ($subscriptions as $bsn => $value) {
			$items .= '<a:Abonnement><a:klant xmlns:b="http://schemas.rdw.nl/GEB/Shared/Types/2009/01"><b:Key> ' . $bsn . ' </b:Key></a:klant>'
				. '<a:isBerichtSturen> ' . $value . ' </a:isBerichtSturen></a:Abonnement>';
		}

		return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><ValidateAbonnementenResponse xmlns="http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/2009/01">'
			. '<ValidateAbonnementenResult xmlns:a="http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/Types/2009/01">' . $items
			. '</ValidateAbonnementenResult></ValidateAbonnementenResponse></s:Body></s:Envelope>';
	}//end answer()

	/**
	 * Assert the call refuses with this message and reason.
	 *
	 * @param callable $call    The call.
	 * @param string   $message The exact message.
	 * @param string   $reason  The exact reason.
	 *
	 * @return void
	 */
	private function assertRefused(callable $call, string $message, string $reason): void {
		try {
			$call();
			$this->fail('The check was not refused.');
		} catch (BerichtenboxException $exception) {
			$this->assertSame($message, $exception->getMessage());
			$this->assertSame($reason, $exception->getReason());
		}
	}//end assertRefused()

	/**
	 * Allowed: the guard, then the certificate, then one POST with the exact options and the resolved bundle.
	 *
	 * @return void
	 */
	public function testAnAllowedCheckAsksOnceForEveryDistinctBsn(): void {
		$client = $this->client(new Response(200, [], $this->answer(['999993653' => 'true', '999990019' => 'false', '123' => 'TRUE'])));

		$answers = $client->check(['999993653', '999990019', '999993653'], 'BESLUIT', self::CONFIG);

		$this->assertSame(['999993653' => true, '999990019' => false, '123' => false], $answers);
		$this->assertSame(['egress', self::ENDPOINT], $this->calls[0]);
		$this->assertSame(['resolve', ['mode' => 'mtls']], $this->calls[1]);
		$this->assertCount(3, $this->calls);
		[$step, $method, $url, $options, $sameBundle] = $this->calls[2];
		$this->assertSame(['request', 'POST', self::ENDPOINT, true], [$step, $method, $url, $sameBundle]);
		$this->assertSame(
			[
				'headers' => ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"' . BerichtenboxValidatieClient::SOAP_ACTION . '"'],
				'body' => $client->envelope(['999993653', '999990019'], 'BESLUIT', '00000001234567890000'),
				'http_errors' => false,
				'timeout' => 30,
			],
			$options
		);
	}//end testAnAllowedCheckAsksOnceForEveryDistinctBsn()

	/**
	 * Refused before anything is called: no BSN, or more than 250 distinct ones.
	 *
	 * @return void
	 */
	public function testABsnCountOutsideOneTo250IsRefusedBeforeAnyCall(): void {
		$client = $this->client(new Response(200, [], $this->answer([])));
		foreach ([[], array_map('strval', range(1, 251))] as $bsns) {
			$this->assertRefused(
				fn () => $client->check($bsns, 'BESLUIT', self::CONFIG),
				'A subscription check takes 1 to 250 BSNs.',
				BerichtenboxException::CODE_SUBSCRIPTION_FAULT
			);
		}

		$this->assertSame([], $this->calls);
	}//end testABsnCountOutsideOneTo250IsRefusedBeforeAnyCall()

	/**
	 * Refused by the egress guard: the certificate is never decrypted and nothing is sent.
	 *
	 * @return void
	 */
	public function testAnEndpointTheGuardRefusesIsNotConfigured(): void {
		$client = $this->client(new Response(200), new EgressRefusedException('host not allowed'));

		$this->assertRefused(
			fn () => $client->check(['1'], 'BESLUIT', ['senderOin' => 'x']),
			'The subscription check endpoint may not be called: host not allowed',
			BerichtenboxException::CODE_NOT_CONFIGURED
		);
		$this->assertSame([['egress', '']], $this->calls);
	}//end testAnEndpointTheGuardRefusesIsNotConfigured()

	/**
	 * Refused by the certificate: nothing is sent.
	 *
	 * @return void
	 */
	public function testAnUnusableCertificateIsNotConfigured(): void {
		$client = $this->client(new Response(200), null, new MtlsConfigurationException('expired', 'certificate_expired'));

		$this->assertRefused(
			fn () => $client->check(['1'], 'BESLUIT', self::CONFIG),
			'The PKIoverheid certificate cannot be used: expired',
			BerichtenboxException::CODE_NOT_CONFIGURED
		);
		$this->assertCount(2, $this->calls);
	}//end testAnUnusableCertificateIsNotConfigured()

	/**
	 * A transport failure, an empty or unreadable answer and an unanswered BSN are subscription faults.
	 *
	 * @return void
	 */
	public function testWhatLogiusDoesNotAnswerIsASubscriptionFault(): void {
		$cases = [
			[new MtlsTransportException('timed out', 'timeout'), 'The subscription check did not reach Logius: timed out'],
			[new Response(502, [], ''), 'The subscription check answered HTTP 502 without a SOAP body.'],
			[new Response(200, [], 'not xml <'), 'The subscription check answered HTTP 200 without a SOAP body.'],
			[new Response(200, [], $this->answer(['1' => 'true'])), 'Logius did not answer the subscription check for every BSN asked.'],
			[new Response(200, [], '<other/>'), 'Logius did not answer the subscription check for every BSN asked.'],
		];
		foreach ($cases as [$answer, $message]) {
			$this->assertRefused(
				fn () => $this->client($answer)->check(['1', '2'], 'BESLUIT', self::CONFIG),
				$message,
				BerichtenboxException::CODE_SUBSCRIPTION_FAULT
			);
		}
	}//end testWhatLogiusDoesNotAnswerIsASubscriptionFault()

	/**
	 * A fault without an application detail is told by its faultstring.
	 *
	 * @return void
	 */
	public function testAFaultWithoutDetailUsesItsFaultstring(): void {
		$fault = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><faultcode>s:Server</faultcode>'
			. '<faultstring> Service down </faultstring></s:Fault></s:Body></s:Envelope>';

		$this->assertRefused(
			fn () => $this->client(new Response(500, [], $fault))->check(['1'], 'BESLUIT', self::CONFIG),
			'Logius refused the subscription check:  Service down',
			BerichtenboxException::CODE_SUBSCRIPTION_FAULT
		);
	}//end testAFaultWithoutDetailUsesItsFaultstring()
}//end class
