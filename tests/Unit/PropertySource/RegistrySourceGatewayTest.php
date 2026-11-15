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
	 * The golden outcomes, taken before the answer handling was split.
	 *
	 * @var string
	 */
	private const GOLDEN_ANSWERS = '{"json-string:read":{"returned":{"a":[1,2]},"logs":[]},"json-string:readWithoutBodies":{"returned":{"a":[1,2]},"logs":[]},"array-body:read":{"returned":{"b":true},"logs":[]},"array-body:readWithoutBodies":{"returned":{"b":true},"logs":[]},"not-json:read":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered a body this binding cannot read.","provider":"kvk","logs":[]},"not-json:readWithoutBodies":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered a body this binding cannot read.","provider":"kvk","logs":[]},"scalar-json:read":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered a body this binding cannot read.","provider":"kvk","logs":[]},"scalar-json:readWithoutBodies":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered a body this binding cannot read.","provider":"kvk","logs":[]},"no-body:read":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered a body this binding cannot read.","provider":"kvk","logs":[]},"no-body:readWithoutBodies":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered a body this binding cannot read.","provider":"kvk","logs":[]},"no-status:read":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered HTTP 0.","provider":"kvk","logs":[]},"no-status:readWithoutBodies":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered HTTP 0.","provider":"kvk","logs":[]},"below-200:read":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered HTTP 199.","provider":"kvk","logs":[]},"below-200:readWithoutBodies":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered HTTP 199.","provider":"kvk","logs":[]},"above-299:read":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered HTTP 300.","provider":"kvk","logs":[]},"above-299:readWithoutBodies":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered HTTP 300.","provider":"kvk","logs":[]},"not-found:read":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered HTTP 404.","provider":"kvk","logs":[]},"not-found:readWithoutBodies":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" answered HTTP 404.","provider":"kvk","logs":[]},"transport:read":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" did not answer: connection refused","provider":"kvk","logs":[["property-source.gateway.transport",{"provider":"kvk","source":"kvk-source","error":"connection refused"}]]},"transport:readWithoutBodies":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\SourceUnreachableException","message":"Source \\"kvk-source\\" did not answer: connection refused","provider":"kvk","logs":[["property-source.gateway.transport",{"provider":"kvk","source":"kvk-source","error":"connection refused"}],["property-source.gateway.transport",{"provider":"kvk","source":"kvk-source","error":"connection refused"}]]},"no-source:read":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\MissingSourceConfigurationException","message":"Provider \\"kvk\\" has no source configured under \\"kvk-source\\", so no call was made.","provider":"kvk","logs":[]},"no-source:readWithoutBodies":{"threw":"OCA\\\\Integriq\\\\PropertySource\\\\Exception\\\\MissingSourceConfigurationException","message":"Provider \\"kvk\\" has no source configured under \\"kvk-source\\", so no call was made.","provider":"kvk","logs":[]}}';
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
	 * A read that withholds its bodies reaches the call engine recorded, with
	 * `withholdBodies` on.
	 *
	 * @return void
	 */
	public function testAReadThatWithholdsItsBodiesSaysSoToTheCallEngine(): void {
		$this->gateway()->read('brp', 'brp-haalcentraal', '/personen', [], ['type' => 'RaadpleegMetBurgerservicenummer']);
		$this->assertCount(9, $this->call['rest']);
		$this->assertFalse(end($this->call['rest']), 'A plain read withholds nothing (withholdBodies, the call engine\'s last parameter)');

		$answer = $this->gateway()->readWithoutBodies('brp', 'brp-haalcentraal', '/personen', [], ['type' => 'RaadpleegMetBurgerservicenummer']);
		$this->assertSame(['ok' => true], $answer);
		$this->assertSame('POST', $this->call['method']);
		$this->assertCount(9, $this->call['rest']);
		$this->assertTrue($this->call['rest'][7], 'The call is recorded (persistLog)');
		$this->assertTrue(end($this->call['rest']), 'Its bodies are withheld (withholdBodies)');
	}//end testAReadThatWithholdsItsBodiesSaysSoToTheCallEngine()

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

	/**
	 * GOLDEN: what a read returns or throws for every answer the call engine
	 * can give, and what it logs. Pinned before the answer handling was split.
	 *
	 * @return void
	 */
	public function testGoldenAnswersAndFailures(): void {
		$cases = [
			'json-string' => ['statusCode' => 200, 'response' => ['body' => '{"a":[1,2]}']],
			'array-body' => ['statusCode' => 299, 'response' => ['body' => ['b' => true]]],
			'not-json' => ['statusCode' => 201, 'response' => ['body' => 'not json']],
			'scalar-json' => ['statusCode' => 200, 'response' => ['body' => '42']],
			'no-body' => ['statusCode' => 204],
			'no-status' => ['response' => ['body' => '{}']],
			'below-200' => ['statusCode' => 199, 'response' => ['body' => '{}']],
			'above-299' => ['statusCode' => 300, 'response' => ['body' => '{}']],
			'not-found' => ['statusCode' => '404', 'response' => ['body' => '{}']],
			'transport' => null,
			'no-source' => false,
		];

		$outcomes = [];
		foreach ($cases as $name => $callLog) {
			$logs = [];
			$logger = $this->createMock(LoggerInterface::class);
			$logger->method('warning')->willReturnCallback(
				function (string $message, array $context) use (&$logs): void {
					$logs[] = [$message, $context];
				}
			);
			$store = $this->createMock(ConnectionStore::class);
			$store->method('findSourceBySlug')->willReturn($callLog === false ? null : new ObjectEntity());
			$calls = $this->createMock(CallService::class);
			$calls->method('call')->willReturnCallback(
				function () use ($callLog): ObjectEntity {
					if ($callLog === null) {
						throw new \RuntimeException('connection refused');
					}

					$log = new ObjectEntity();
					$log->setObject($callLog);
					return $log;
				}
			);
			$gateway = new RegistrySourceGateway($store, $calls, $logger);

			foreach (['read', 'readWithoutBodies'] as $method) {
				try {
					$outcome = ['returned' => $gateway->$method('kvk', 'kvk-source', '/zoeken', ['q' => 1])];
				} catch (\Throwable $exception) {
					$outcome = ['threw' => get_class($exception), 'message' => $exception->getMessage(), 'provider' => $exception->getProviderId()];
				}

				$outcomes[$name . ':' . $method] = $outcome + ['logs' => $logs];
			}
		}//end foreach

		$this->assertSame(self::GOLDEN_ANSWERS, json_encode($outcomes));
	}//end testGoldenAnswersAndFailures()

}//end class
