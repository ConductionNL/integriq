<?php

/**
 * Golden tests for the AVD session enumeration.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\EndpointWorkspace\AzureVirtualDesktopAdapter;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the ARM path every enumeration requests, every degraded answer, and
 * the session summary built from each row, through listSessions and list.
 */
class AzureVirtualDesktopSessionsGoldenTest extends TestCase {

	/**
	 * Paths the broker was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $paths = [];

	/**
	 * An adapter whose broker answers with the given status and body.
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 *
	 * @return AzureVirtualDesktopAdapter The adapter.
	 */
	private function adapter(int $status, string $body): AzureVirtualDesktopAdapter {
		$this->paths = [];
		$broker      = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (...$arguments) use ($status, $body): array {
				$this->paths[] = $arguments[2].' '.$arguments[3];
				return ['status' => $status, 'headers' => [], 'body' => $body];
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('cred-uuid-avd');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new AzureVirtualDesktopAdapter(
			credentialBroker: $broker,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n
		);

	}//end adapter()

	/**
	 * The path encodes every segment and carries the API version; rows map to summaries.
	 *
	 * @return void
	 */
	public function testPathAndSummaries(): void {
		$body    = json_encode(
			[
				'value' => [
					['name' => 's-1', 'properties' => ['userPrincipalName' => 'a@x.nl', 'sessionState' => 'Active', 'createTime' => 't', 'activeDirectoryUserName' => 'X\\a', 'extra' => 1]],
					['name' => 's-2'],
					[],
				],
			]
		);
		$expected = [
			['id' => 's-1', 'userPrincipalName' => 'a@x.nl', 'sessionState' => 'Active', 'createTime' => 't', 'activeDirectoryUserName' => 'X\\a'],
			['id' => 's-2', 'userPrincipalName' => null, 'sessionState' => null, 'createTime' => null, 'activeDirectoryUserName' => null],
			['id' => null, 'userPrincipalName' => null, 'sessionState' => null, 'createTime' => null, 'activeDirectoryUserName' => null],
		];
		$path = 'GET /subscriptions/sub%201/resourceGroups/rg%2F1/providers/Microsoft.DesktopVirtualization'
			.'/hostPools/pool%3F1/sessionHosts/host%231/userSessions?api-version=2023-09-05';

		$adapter = $this->adapter(200, $body);
		$this->assertSame($expected, $adapter->listSessions('sub 1', 'rg/1', 'pool?1', 'host#1', ['ignored' => true]));
		$this->assertSame([$path], $this->paths);

		$adapter = $this->adapter(299, $body);
		$this->assertSame(
			$expected,
			$adapter->list('r', 's', 'o', ['subscriptionId' => 'sub 1', 'resourceGroup' => 'rg/1', 'hostPool' => 'pool?1', 'sessionHost' => 'host#1'])
		);
		$this->assertSame([$path], $this->paths);

	}//end testPathAndSummaries()

	/**
	 * Every degraded answer is an empty list.
	 *
	 * @return void
	 */
	public function testDegradedAnswersAreEmpty(): void {
		$cases = [
			[199, '{"value":[]}'],
			[300, '{"value":[{"name":"s"}]}'],
			[500, '{"value":[{"name":"s"}]}'],
			[200, 'not json'],
			[200, '{"other":[]}'],
			[200, '{"value":"s"}'],
			[200, '{"value":null}'],
		];
		foreach ($cases as [$status, $body]) {
			$this->assertSame([], $this->adapter($status, $body)->listSessions('s', 'r', 'p', 'h'), $status.' '.$body);
		}

		$this->assertSame([], $this->adapter(200, '{"value":[]}')->listSessions('s', 'r', 'p', 'h'));

	}//end testDegradedAnswersAreEmpty()
}//end class
