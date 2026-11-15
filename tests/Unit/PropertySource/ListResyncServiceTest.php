<?php

/**
 * Integriq — list-shaped property-source resync tests.
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

use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCA\Integriq\PropertySource\ListResyncService;
use OCA\Integriq\PropertySource\PropertySourceProviderInterface;
use OCA\Integriq\PropertySource\PropertySourceRegistry;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-RFS-007: a list resyncs on demand, reports what changed, and a failure
 * leaves the previous list serving.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-list-shaped-source-resyncs-on-demand-req-rfs-007
 */
class ListResyncServiceTest extends TestCase {
	/**
	 * In-memory app-config store.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * Build the service around a provider double.
	 *
	 * @param PropertySourceProviderInterface $provider The provider.
	 *
	 * @return ListResyncService The service under test.
	 */
	private function service(PropertySourceProviderInterface $provider): ListResyncService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return new ListResyncService(
			new PropertySourceRegistry([$provider]),
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * A list-shaped provider double.
	 *
	 * @return PropertySourceProviderInterface The double.
	 */
	private function provider(): PropertySourceProviderInterface {
		$provider = $this->createMock(PropertySourceProviderInterface::class);
		$provider->method('id')->willReturn('classificatie');
		$provider->method('describe')->willReturn(
			[
				'id' => 'classificatie',
				'label' => 'Classification plan',
				'identifier' => 'code',
				'stalenessBudget' => 86400,
				'listShaped' => true,
			]
		);

		return $provider;
	}//end provider()

	/**
	 * Seed the list currently served.
	 *
	 * @param array<int,array<string,mixed>> $list The stored list.
	 *
	 * @return void
	 */
	private function seedList(array $list): void {
		$this->config[ListResyncService::LIST_KEY_PREFIX . 'classificatie'] = json_encode($list);
	}//end seedList()

	/**
	 * A resync refreshes the list and reports the change count.
	 *
	 * @return void
	 */
	public function testAResyncReportsWhatChanged(): void {
		$provider = $this->provider();
		$provider->method('suggest')->willReturn(
			[
				['identifier' => '1.1', 'label' => 'Bestuur'],
				['identifier' => '2.1', 'label' => 'Vergunningen'],
				['identifier' => '3.1', 'label' => 'Nieuw'],
			]
		);
		$this->seedList(
			[
				['identifier' => '1.1', 'label' => 'Bestuur'],
				['identifier' => '2.1', 'label' => 'Vergunning'],
			]
		);

		$service = $this->service($provider);
		$report = $service->resync('classificatie');

		$this->assertTrue($report['succeeded']);
		$this->assertSame(2, $report['changed'], 'One relabelled entry and one added entry.');
		$this->assertCount(3, $service->currentList('classificatie'));
	}//end testAResyncReportsWhatChanged()

	/**
	 * The last resync timestamp survives into the report anyone can read.
	 *
	 * @return void
	 */
	public function testTheLastResyncTimestampIsReadable(): void {
		$provider = $this->provider();
		$provider->method('suggest')->willReturn([['identifier' => '1.1', 'label' => 'Bestuur']]);

		$service = $this->service($provider);
		$service->resync('classificatie');

		$this->assertNotNull($service->lastReport('classificatie')['lastResyncAt']);
	}//end testTheLastResyncTimestampIsReadable()

	/**
	 * A failed resync leaves the previous list in place and reports the failure.
	 *
	 * @return void
	 */
	public function testAFailedResyncLeavesThePreviousListInPlace(): void {
		$provider = $this->provider();
		$provider->method('suggest')->willThrowException(
			new SourceUnreachableException('classificatie', 'the source failed mid-resync')
		);
		$this->seedList([['identifier' => '1.1', 'label' => 'Bestuur']]);

		$service = $this->service($provider);
		$report = $service->resync('classificatie');

		$this->assertFalse($report['succeeded']);
		$this->assertStringContainsString('mid-resync', $report['message']);
		$this->assertSame(
			[['identifier' => '1.1', 'label' => 'Bestuur']],
			$service->currentList('classificatie'),
			'The previous list must still be served after a failed resync.'
		);
	}//end testAFailedResyncLeavesThePreviousListInPlace()

	/**
	 * Lists before and after, and how many entries changed.
	 *
	 * @return array<string,array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:int}>
	 */
	public static function listChanges(): array {
		$a = ['identifier' => 'a', 'label' => 'A'];
		$b = ['identifier' => 'b', 'label' => 'B'];
		return [
			'nothing before or after' => [[], [], 0],
			'unchanged'               => [[$a, $b], [$b, $a], 0],
			'one added'               => [[$a], [$a, $b], 1],
			'one removed'             => [[$a, $b], [$a], 1],
			'one relabelled'          => [[$a], [['identifier' => 'a', 'label' => 'Z']], 1],
			'replaced'                => [[$a], [$b], 2],
			'a later duplicate wins'  => [[$a, ['identifier' => 'a', 'label' => 'Y']], [$a], 1],
			'no identifier or label'  => [[['x' => 1]], [['identifier' => '', 'label' => '']], 0],
			'numbers as strings'      => [[['identifier' => 1, 'label' => 2]], [['identifier' => '1', 'label' => '2']], 0],
		];

	}//end listChanges()

	/**
	 * A resync counts the added, removed and relabelled entries, serves the
	 * fetched list, stores it and reports it, asking the provider with the config.
	 *
	 * @param array<int,array<string,mixed>> $before  The list served before.
	 * @param array<int,array<string,mixed>> $after   The list fetched.
	 * @param int                            $changed The changes reported.
	 *
	 * @return void
	 *
	 * @dataProvider listChanges
	 */
	public function testAResyncCountsEachChange(array $before, array $after, int $changed): void {
		$provider = $this->provider();
		$provider->expects($this->once())->method('suggest')->with('', ['token' => 't'])->willReturn($after);
		$this->seedList($before);
		$started = time();

		$report = $this->service($provider)->resync('classificatie', ['token' => 't']);

		$this->assertGreaterThanOrEqual($started, $report['lastResyncAt']);
		unset($report['lastResyncAt']);
		$this->assertSame(['changed' => $changed, 'succeeded' => true, 'message' => '', 'served' => count($after)], $report);
		$this->assertSame(json_encode($after), $this->config[ListResyncService::LIST_KEY_PREFIX . 'classificatie']);

	}//end testAResyncCountsEachChange()

	/**
	 * A failed resync logs the provider and the error, keeps the previous
	 * timestamp and reports what is still served.
	 *
	 * @return void
	 */
	public function testAFailedResyncKeepsThePreviousTimestamp(): void {
		$provider = $this->provider();
		$provider->method('suggest')->willReturnOnConsecutiveCalls(
			[['identifier' => '1.1', 'label' => 'Bestuur'], ['identifier' => '1.2', 'label' => 'B']],
			$this->throwException(new SourceUnreachableException('classificatie', 'down'))
		);
		$logger = $this->createMock(LoggerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$service = new ListResyncService(new PropertySourceRegistry([$provider]), $appConfig, $logger);
		$first = $service->resync('classificatie');
		$logger->expects($this->once())->method('warning')->with('property-source.resync.failed', ['provider' => 'classificatie', 'error' => 'down']);

		$failed = $service->resync('classificatie');

		$this->assertSame($first['lastResyncAt'], $failed['lastResyncAt']);
		unset($failed['lastResyncAt']);
		$this->assertSame(['changed' => 0, 'succeeded' => false, 'message' => 'down', 'served' => 2], $failed);
		$this->assertSame($service->lastReport('classificatie')['served'], 2);

	}//end testAFailedResyncKeepsThePreviousTimestamp()
}//end class
