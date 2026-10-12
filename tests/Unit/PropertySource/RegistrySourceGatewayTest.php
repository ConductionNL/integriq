<?php

/**
 * Integriq: how the registry gateway puts a question to a source.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\PropertySource
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

namespace OCA\Integriq\Tests\Unit\PropertySource;

use OCA\Integriq\PropertySource\RegistrySourceGateway;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\PropertySource\RegistrySourceGateway
 */
class RegistrySourceGatewayTest extends TestCase {
	/**
	 * The call the gateway made: method and config.
	 *
	 * @var array<string, mixed>
	 */
	private array $call = [];

	/**
	 * A gateway over a call engine that answers 200 with a JSON body.
	 *
	 * @return RegistrySourceGateway
	 */
	private function gateway(): RegistrySourceGateway {
		$source = new ObjectEntity();
		$source->setObject(['slug' => 'brp-haalcentraal', 'configuration' => ['relationFields' => true]]);
		$store  = $this->createMock(ConnectionStore::class);
		$store->method('findSourceBySlug')->willReturn($source);

		$log = new ObjectEntity();
		$log->setObject(['statusCode' => 200, 'response' => ['body' => '{"ok":true}']]);
		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willReturnCallback(
			function (ObjectEntity $source, string $endpoint, string $method, array $config, ...$rest) use ($log): ObjectEntity {
				$this->call = ['endpoint' => $endpoint, 'method' => $method, 'config' => $config, 'rest' => $rest];
				return $log;
			}
		);

		return new RegistrySourceGateway($store, $calls, $this->createMock(LoggerInterface::class));
	}//end gateway()

	/**
	 * Without a body the question is a GET with its query.
	 *
	 * @return void
	 */
	public function testAQuestionWithoutABodyIsAGet(): void {
		$answer = $this->gateway()->read('kvk', 'kvk', '/zoeken', ['kvkNummer' => '69599084']);

		$this->assertSame(['ok' => true], $answer);
		$this->assertSame('GET', $this->call['method']);
		$this->assertSame(['query' => ['kvkNummer' => '69599084']], $this->call['config']);
	}//end testAQuestionWithoutABodyIsAGet()

	/**
	 * With a body the question is a POST carrying it as JSON.
	 *
	 * @return void
	 */
	public function testAQuestionWithABodyIsAPost(): void {
		$this->gateway()->read('brp', 'brp-haalcentraal', '/personen', [], ['type' => 'RaadpleegMetBurgerservicenummer']);

		$this->assertSame('POST', $this->call['method']);
		$this->assertSame('/personen', $this->call['endpoint']);
		$this->assertSame('{"type":"RaadpleegMetBurgerservicenummer"}', $this->call['config']['body']);
	}//end testAQuestionWithABodyIsAPost()

	/**
	 * An unrecorded read reaches the call engine with `persistLog` false, so
	 * no call log row is written for it.
	 *
	 * @return void
	 */
	public function testAReadTheCallerAsksNotToRecordIsNotWrittenToTheCallLog(): void {
		$this->gateway()->read('brp', 'brp-haalcentraal', '/personen', [], ['type' => 'RaadpleegMetBurgerservicenummer']);
		$this->assertTrue(end($this->call['rest']), 'A read is written to the call log (persistLog, the call engine\'s last parameter)');

		$answer = $this->gateway()->readUnrecorded('brp', 'brp-haalcentraal', '/personen', [], ['type' => 'RaadpleegMetBurgerservicenummer']);
		$this->assertSame(['ok' => true], $answer);
		$this->assertSame('POST', $this->call['method']);
		$this->assertCount(8, $this->call['rest']);
		$this->assertFalse(end($this->call['rest']));
	}//end testAReadTheCallerAsksNotToRecordIsNotWrittenToTheCallLog()

	/**
	 * The configuration of a source is read by slug; no source is null.
	 *
	 * @return void
	 */
	public function testASourceConfigurationIsReadBySlug(): void {
		$this->assertSame(['relationFields' => true], $this->gateway()->configuration('brp-haalcentraal'));

		$store = $this->createMock(ConnectionStore::class);
		$store->method('findSourceBySlug')->willReturn(null);
		$gateway = new RegistrySourceGateway($store, $this->createMock(CallService::class), $this->createMock(LoggerInterface::class));
		$this->assertNull($gateway->configuration('brp-haalcentraal'));
	}//end testASourceConfigurationIsReadBySlug()
}//end class
