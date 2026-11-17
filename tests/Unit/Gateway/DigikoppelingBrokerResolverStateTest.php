<?php

/**
 * Integriq — DigikoppelingBrokerResolver stored-state tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Gateway
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

namespace OCA\Integriq\Tests\Unit\Gateway;

use OCA\Integriq\Gateway\BrokerConfigurationException;
use OCA\Integriq\Gateway\DigikoppelingBrokerResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * How the resolver reads damaged stored state and what select() writes.
 *
 * @covers \OCA\Integriq\Gateway\DigikoppelingBrokerResolver
 * @uses   \OCA\Integriq\Gateway\BrokerConfigurationException
 */
class DigikoppelingBrokerResolverStateTest extends TestCase {
	/**
	 * In-memory app-config store.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * A resolver over the in-memory store.
	 *
	 * @param LoggerInterface|null $logger The logger, a stub when null.
	 *
	 * @return DigikoppelingBrokerResolver The resolver.
	 */
	private function resolver(?LoggerInterface $logger = null): DigikoppelingBrokerResolver {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('integriq', $app);
				$this->config[$key] = $value;
				return true;
			}
		);

		return new DigikoppelingBrokerResolver($appConfig, ($logger ?? $this->createStub(LoggerInterface::class)));
	}//end resolver()

	/**
	 * Undecodable or scalar stored audit and broker values read as empty.
	 *
	 * @return void
	 */
	public function testDamagedStoredStateReadsAsEmpty(): void {
		$this->config[DigikoppelingBrokerResolver::AUDIT_KEY] = '{not json';
		$this->config[DigikoppelingBrokerResolver::BROKERS_KEY] = '"just a string"';
		$resolver = $this->resolver();

		$this->assertSame([], $resolver->audit());
		$this->assertSame([], $resolver->available());

		$this->config[DigikoppelingBrokerResolver::AUDIT_KEY] = '42';
		$this->assertSame([], $resolver->audit());
	}//end testDamagedStoredStateReadsAsEmpty()

	/**
	 * Nothing stored means no brokers and an empty audit trail.
	 *
	 * @return void
	 */
	public function testNothingStoredMeansNoBrokersAndNoAudit(): void {
		$resolver = $this->resolver();

		$this->assertSame([], $resolver->available());
		$this->assertSame([], $resolver->audit());
	}//end testNothingStoredMeansNoBrokersAndNoAudit()

	/**
	 * A damaged audit trail is replaced, not appended to, on the next selection,
	 * and the change is logged with from, to and by.
	 *
	 * @return void
	 */
	public function testSelectingOverADamagedAuditStartsAFreshTrailAndLogs(): void {
		$this->config[DigikoppelingBrokerResolver::BROKERS_KEY] = json_encode(['b' => ['endpoint' => 'https://b.test']]);
		$this->config[DigikoppelingBrokerResolver::AUDIT_KEY] = 'garbage';
		$this->config[DigikoppelingBrokerResolver::SELECTED_KEY] = 'a';

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'digikoppeling.broker.changed',
			['from' => 'a', 'to' => 'b', 'by' => 'admin']
		);

		$audit = $this->resolver($logger)->select('b', 'admin');

		$this->assertCount(1, $audit);
		$this->assertSame('a', $audit[0]['from']);
		$this->assertSame('b', $audit[0]['to']);
		$this->assertSame('admin', $audit[0]['by']);
		$this->assertSame('b', $this->config[DigikoppelingBrokerResolver::SELECTED_KEY]);
		$this->assertSame($audit, json_decode($this->config[DigikoppelingBrokerResolver::AUDIT_KEY], true));
	}//end testSelectingOverADamagedAuditStartsAFreshTrailAndLogs()

	/**
	 * A broker selected without an endpoint is refused with its id named.
	 *
	 * @return void
	 */
	public function testABrokerWithAnEmptyEndpointIsRefused(): void {
		$this->config[DigikoppelingBrokerResolver::BROKERS_KEY] = json_encode(['x' => ['endpoint' => '']]);
		$this->config[DigikoppelingBrokerResolver::SELECTED_KEY] = 'x';

		$this->expectException(BrokerConfigurationException::class);
		$this->expectExceptionMessage('The Digikoppeling broker "x" has no endpoint configured. Nothing was sent.');

		$this->resolver()->resolve();
	}//end testABrokerWithAnEmptyEndpointIsRefused()

	/**
	 * A resolved broker carries its id ahead of its own configuration.
	 *
	 * @return void
	 */
	public function testAResolvedBrokerCarriesItsId(): void {
		$this->config[DigikoppelingBrokerResolver::BROKERS_KEY] = json_encode(['x' => ['endpoint' => 'https://x.test', 'label' => 'X']]);
		$this->config[DigikoppelingBrokerResolver::SELECTED_KEY] = 'x';

		$this->assertSame(['id' => 'x', 'endpoint' => 'https://x.test', 'label' => 'X'], $this->resolver()->resolve());
	}//end testAResolvedBrokerCarriesItsId()
}//end class
