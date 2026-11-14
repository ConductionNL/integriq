<?php

/**
 * PaymentSettlementService and PaymentSettlementPullJob unit tests
 *
 * The daily settlement report (REQ-LPP-008): a new Mollie settlement is
 * emitted once as nl.conduction.payment.settlement and stored per source; a
 * stored one is not emitted again; a `log` source is skipped; a failing
 * source does not stop the others; and the job runs the pull daily without
 * throwing.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Payment;

use OCA\Integriq\BackgroundJob\PaymentSettlementPullJob;
use OCA\Integriq\Exception\PaymentProviderException;
use OCA\Integriq\Service\CloudEventEmitterInterface;
use OCA\Integriq\Service\Payment\MolliePaymentProvider;
use OCA\Integriq\Service\Payment\PaymentSettlementService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the settlement pull.
 */
class PaymentSettlementServiceTest extends TestCase {

	/**
	 * The OpenRegister object service mock.
	 *
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * The Mollie binding mock.
	 *
	 * @var MolliePaymentProvider&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mollie;

	/**
	 * The emitted events, as [type, source, subject, data].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $emitted = [];

	/**
	 * The stored settlements.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The payment sources the register holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sources = [];

	/**
	 * The clock.
	 *
	 * @var ITimeFactory&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $time;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$rows = $filters['schema'] === 'source' ? $this->sources : $this->stored;
				$results = [];
				foreach ($rows as $row) {
					$matches = true;
					foreach ($filters as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === false && ($row[$key] ?? null) !== $value) {
							$matches = false;
						}
					}

					if ($matches === true) {
						$entity = new ObjectEntity();
						$entity->setObject($row);
						$results[] = $entity;
					}
				}

				return ['results' => $results, 'total' => count($results)];
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, mixed $register, mixed $schema): ObjectEntity {
				$this->assertSame('integriq', $register);
				$this->assertSame('payment_settlement', $schema);
				$this->stored[] = $object;
				return new ObjectEntity();
			}
		);
		$this->mollie = $this->createMock(MolliePaymentProvider::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(strtotime('2026-10-03T06:00:00Z'));
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return PaymentSettlementService
	 */
	private function service(?LoggerInterface $logger=null): PaymentSettlementService {
		$events = $this->createMock(CloudEventEmitterInterface::class);
		$events->method('emitCloudEvent')->willReturnCallback(
			function (string $type, string $source, ?string $subject, array $data): array {
				$this->emitted[] = [$type, $source, $subject, $data];
				return [];
			}
		);

		return new PaymentSettlementService($this->objectService, $this->mollie, $events, $this->time, ($logger ?? new NullLogger()));
	}//end service()

	/**
	 * The settlement st_example0001 as the binding reports it.
	 *
	 * @return array<string, mixed>
	 */
	private function settlement(): array {
		$lines = [];
		for ($i = 1; $i <= 14; $i++) {
			$lines[] = ['providerPaymentId' => sprintf('tr_ex%04d', $i), 'type' => 'payment', 'amount' => $i === 14 ? '43.65' : '43.75', 'fee' => '0.00'];
		}

		$lines[] = ['providerPaymentId' => 'tr_ex0003', 'type' => 'refund', 'amount' => '-22.85', 'fee' => '0.00'];

		return [
			'settlementReference' => 'st_example0001', 'settledAt' => '2026-10-02T09:41:44+00:00', 'currency' => 'EUR',
			'amountGross' => '612.40', 'amountFees' => '4.06', 'amountRefunds' => '22.85', 'amountChargebacks' => '0.00',
			'amountNet' => '585.49', 'transactions' => $lines,
		];
	}//end settlement()

	/**
	 * A Mollie payout is reported once with its lines and stored per source;
	 * the log source is skipped and its provider never asked.
	 *
	 * @return void
	 */
	public function testAMolliePayoutIsReportedAndStored(): void {
		$this->sources = [
			['slug' => 'mollie-live', 'type' => 'payment', 'configuration' => ['provider' => 'mollie']],
			['slug' => 'ideal-ouderbijdrage', 'type' => 'payment', 'configuration' => ['provider' => 'log']],
		];
		$this->mollie->expects($this->once())->method('fetchSettlements')
			->with(['provider' => 'mollie'], '2026-08-29T06:00:00Z')
			->willReturn([$this->settlement()]);

		$report = $this->service()->pullAll();

		$this->assertSame(['sources' => 1, 'reported' => 1, 'failed' => 0], $report);
		$this->assertCount(1, $this->emitted);
		[$type, $source, $subject, $data] = $this->emitted[0];
		$this->assertSame('nl.conduction.payment.settlement', $type);
		$this->assertSame('/payments/settlements/st_example0001', $source);
		$this->assertSame('st_example0001', $subject);
		$this->assertSame($this->settlement(), $data);
		$this->assertCount(15, $data['transactions']);

		$this->assertCount(1, $this->stored);
		$this->assertSame('mollie-live', $this->stored[0]['sourceSlug']);
		$this->assertSame('mollie', $this->stored[0]['provider']);
		$this->assertSame('st_example0001', $this->stored[0]['settlementReference']);
		$this->assertSame('2026-10-03T06:00:00+00:00', $this->stored[0]['reportedAt']);
	}//end testAMolliePayoutIsReportedAndStored()

	/**
	 * A second pull sends nothing for a settlement already reported.
	 *
	 * @return void
	 */
	public function testThePullRunningTwiceReportsNothingTwice(): void {
		$this->sources = [['slug' => 'mollie-live', 'type' => 'payment', 'configuration' => ['provider' => 'mollie']]];
		$this->mollie->method('fetchSettlements')->willReturn([$this->settlement()]);

		$this->service()->pullAll();
		$second = $this->service()->pullAll();

		$this->assertSame(['sources' => 1, 'reported' => 0, 'failed' => 0], $second);
		$this->assertCount(1, $this->emitted);
		$this->assertCount(1, $this->stored);
	}//end testThePullRunningTwiceReportsNothingTwice()

	/**
	 * The same reference on another source is that source's own settlement.
	 *
	 * @return void
	 */
	public function testTheSameReferenceOnAnotherSourceIsReported(): void {
		$this->stored = [['sourceSlug' => 'mollie-other', 'settlementReference' => 'st_example0001']];
		$this->sources = [['slug' => 'mollie-live', 'type' => 'payment', 'configuration' => ['provider' => 'mollie']]];
		$this->mollie->method('fetchSettlements')->willReturn([$this->settlement()]);

		$this->assertSame(1, $this->service()->pullAll()['reported']);
	}//end testTheSameReferenceOnAnotherSourceIsReported()

	/**
	 * A failing source is logged and counted; the others still run.
	 *
	 * @return void
	 */
	public function testAFailingSourceDoesNotStopTheOthers(): void {
		$this->sources = [
			['slug' => 'mollie-a', 'type' => 'payment', 'configuration' => ['provider' => 'mollie', 'baseUrl' => 'a']],
			['slug' => 'mollie-b', 'type' => 'payment', 'configuration' => ['provider' => 'mollie', 'baseUrl' => 'b']],
		];
		$this->mollie->method('fetchSettlements')->willReturnCallback(
			function (array $configuration): array {
				if ($configuration['baseUrl'] === 'a') {
					throw new PaymentProviderException('Mollie responded with HTTP 503.');
				}

				return [$this->settlement()];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('integriq: payment settlements could not be pulled', ['source' => 'mollie-a', 'exception' => 'Mollie responded with HTTP 503.']);

		$this->assertSame(['sources' => 2, 'reported' => 1, 'failed' => 1], $this->service($logger)->pullAll());
		$this->assertSame('mollie-b', $this->stored[0]['sourceSlug']);
	}//end testAFailingSourceDoesNotStopTheOthers()

	/**
	 * The job runs daily, one at a time, and logs instead of throwing.
	 *
	 * @return void
	 */
	public function testTheJobRunsThePullDailyAndNeverThrows(): void {
		$service = $this->createMock(PaymentSettlementService::class);
		$service->expects($this->exactly(2))->method('pullAll')->willReturnOnConsecutiveCalls(
			['sources' => 1, 'reported' => 1, 'failed' => 0],
			$this->throwException(new \RuntimeException('register down'))
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('PaymentSettlementPullJob: pull complete', ['sources' => 1, 'reported' => 1, 'failed' => 0]);
		$logger->expects($this->once())->method('error')->with('PaymentSettlementPullJob: pull failed: register down');

		$job = new PaymentSettlementPullJob($this->time, $service, $logger);
		$this->assertSame(86400, $job->getInterval());
		$this->assertFalse($job->getAllowParallelRuns());

		$job->run(null);
		$job->run(null);
	}//end testTheJobRunsThePullDailyAndNeverThrows()

}//end class
