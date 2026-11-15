<?php

/**
 * The KISS client's configuration schema, and a stored token that cannot be decrypted.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use OCA\Integriq\Exception\KissProviderException;
use OCA\Integriq\Service\Kiss\KlantinteractiesClient;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\Kiss\KlantinteractiesClient
 * @uses   \OCA\Integriq\Exception\KissProviderException
 */
class KlantinteractiesClientConfigTest extends TestCase {

	/**
	 * The requests the client sent.
	 *
	 * @var array<int, mixed>
	 */
	private array $history = [];

	/**
	 * The client over a crypto double and a Guzzle client that answers nothing.
	 *
	 * @param ICrypto $crypto The crypto double.
	 *
	 * @return KlantinteractiesClient
	 */
	private function client(ICrypto $crypto): KlantinteractiesClient {
		$stack = HandlerStack::create(new MockHandler([]));
		$stack->push(Middleware::history($this->history));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new KlantinteractiesClient(new Client(['handler' => $stack]), $crypto, $l, $this->createMock(LoggerInterface::class));
	}//end client()

	/**
	 * The schema requires an encrypted token and documents the defaults.
	 *
	 * @return void
	 */
	public function testTheSchemaRequiresAnEncryptedToken(): void {
		$schema = $this->client($this->createMock(ICrypto::class))->getConfigSchema();

		$this->assertSame('object', $schema['type']);
		$this->assertSame(['authentication'], $schema['required']);
		$this->assertSame(['baseUrl', 'authentication', 'onderwerpobject', 'pageSize'], array_keys($schema['properties']));
		$authentication = $schema['properties']['authentication'];
		$this->assertSame(['encryptedToken'], $authentication['required']);
		$this->assertSame('Token', $authentication['properties']['scheme']['default']);
		$this->assertSame('ZRC', $schema['properties']['onderwerpobject']['properties']['codeRegister']['default']);
		$this->assertSame(['type' => 'integer', 'description' => 'Klantcontacten pulled per sync sweep.', 'default' => 100], $schema['properties']['pageSize']);
	}//end testTheSchemaRequiresAnEncryptedToken()

	/**
	 * A token that cannot be decrypted is refused with the cause, before any request.
	 *
	 * @return void
	 */
	public function testAnUndecryptableTokenIsRefusedBeforeAnyRequest(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->once())->method('decrypt')->with('ciphertext-blob')->willThrowException(new RuntimeException('HMAC does not match.'));
		$client = $this->client($crypto);

		try {
			$client->listCustomerContacts(
				sourceConfiguration: ['baseUrl' => 'https://kiss.example.nl/api/v1', 'authentication' => ['encryptedToken' => 'ciphertext-blob']],
				since: null,
				pageSize: 10
			);
			$this->fail('no refusal');
		} catch (KissProviderException $e) {
			$this->assertSame('The stored KISS API token could not be decrypted: HMAC does not match.', $e->getMessage());
		}

		$this->assertSame([], $this->history);
	}//end testAnUndecryptableTokenIsRefusedBeforeAnyRequest()
}//end class
