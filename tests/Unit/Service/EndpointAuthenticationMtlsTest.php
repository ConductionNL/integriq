<?php

/**
 * Unit tests for the `mtls` authentication type on an endpoint rule: the
 * verified certificate goes to the bridge with the endpoint's allowed
 * consumers, a refusal is a 401, and `mtls` works inside `anyOf`.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\Auth\ClientCertificate;
use OCA\Integriq\Service\Auth\ClientCertificateReader;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\Integriq\Service\EndpointService
 * @uses   \OCA\Integriq\Service\Auth\ClientCertificate
 * @uses   \OCA\Integriq\Exception\AuthenticationException
 */
final class EndpointAuthenticationMtlsTest extends TestCase {

	/**
	 * The request data the rule sees: no Authorization header at all.
	 */
	private const DATA = ['headers' => [], 'method' => 'GET', 'body' => 'b'];

	/**
	 * A certificate value; its contents do not matter to the endpoint, only that it is passed on.
	 *
	 * @return ClientCertificate
	 */
	private static function certificate(): ClientCertificate {
		return new ClientCertificate(pem: 'pem', fingerprint: 'ab', subject: '/CN=x', issuer: '/CN=ca', validFrom: 0, validTo: PHP_INT_MAX, oin: '00000001234567890000');
	}//end certificate()

	/**
	 * Run an endpoint rule's authentication over the given bridge and reader.
	 *
	 * @param OpenRegisterCredentialBridge $bridge         The bridge.
	 * @param ClientCertificateReader|null $reader         The certificate reader.
	 * @param array                        $authentication The rule's authentication block.
	 *
	 * @return array|JSONResponse
	 */
	private function runRule(OpenRegisterCredentialBridge $bridge, ?ClientCertificateReader $reader, array $authentication): array|JSONResponse {
		$reflection = new ReflectionClass(EndpointService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('authorizationService')->setValue($service, $bridge);
		$reflection->getProperty('certificateReader')->setValue($service, $reader);
		$rule = new ObjectEntity();
		$rule->setObject(['configuration' => ['authentication' => $authentication]]);

		return $reflection->getMethod('processAuthenticationRule')->invoke($service, $rule, self::DATA);
	}//end runRule()

	/**
	 * A reader that reports the given certificate.
	 *
	 * @param ClientCertificate|null $certificate The certificate.
	 *
	 * @return ClientCertificateReader
	 */
	private function reader(?ClientCertificate $certificate): ClientCertificateReader {
		$reader = $this->createMock(ClientCertificateReader::class);
		$reader->method('fromRequest')->willReturn($certificate);

		return $reader;
	}//end reader()

	/**
	 * A verified certificate pinned to an allowed consumer passes, with no Authorization header.
	 *
	 * @return void
	 */
	public function testAPinnedCertificatePassesWithoutAHeader(): void {
		$certificate = self::certificate();
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->expects($this->once())->method('authorizeClientCertificate')->with($certificate, ['zaaksysteem']);

		$this->assertSame(self::DATA, $this->runRule(bridge: $bridge, reader: $this->reader(certificate: $certificate), authentication: ['type' => 'mtls', 'consumers' => ['zaaksysteem']]));
	}//end testAPinnedCertificatePassesWithoutAHeader()

	/**
	 * A refused certificate answers 401 with the bridge's reason; no reader passes no certificate.
	 *
	 * @return void
	 */
	public function testARefusedCertificateIs401(): void {
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->expects($this->exactly(2))->method('authorizeClientCertificate')
			->with(null, [])
			->willThrowException(new AuthenticationException('No verified client certificate', []));

		foreach ([$this->reader(certificate: null), null] as $reader) {
			$response = $this->runRule(bridge: $bridge, reader: $reader, authentication: ['type' => 'mtls']);

			$this->assertInstanceOf(JSONResponse::class, $response);
			$this->assertSame(401, $response->getStatus());
			$this->assertSame('No verified client certificate', $response->getData()['error']);
		}
	}//end testARefusedCertificateIs401()

	/**
	 * Inside anyOf, a caller without a certificate still passes on its API key.
	 *
	 * @return void
	 */
	public function testMtlsInsideAnyOf(): void {
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->expects($this->once())->method('authorizeClientCertificate')
			->willThrowException(new AuthenticationException('No verified client certificate', []));
		$bridge->expects($this->never())->method('authorizeApiKey');

		$response = $this->runRule(bridge: $bridge, reader: $this->reader(certificate: null), authentication: ['anyOf' => [['type' => 'mtls'], ['type' => 'apikey', 'keys' => []]]]);

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['0:mtls', '1:apikey'], array_column($response->getData()['details']['anyOf'], 'method'));
		$this->assertSame('No verified client certificate', $response->getData()['details']['anyOf'][0]['error']);
	}//end testMtlsInsideAnyOf()
}//end class
