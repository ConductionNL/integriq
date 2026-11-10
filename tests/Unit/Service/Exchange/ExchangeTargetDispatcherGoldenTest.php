<?php

/**
 * ExchangeTargetDispatcher golden: the full outcome of every dispatch path.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Exchange;

use OCA\Integriq\Adapters\Swv\SwvHandoffClient;
use OCA\Integriq\Event\ExchangeRecordsReceivedEvent;
use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\Exchange\ExchangeTargetDispatcher;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\RodService;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Service\VerzuimloketService;
use OCA\Integriq\Sources\Swv\SwvHandoffSourceAdapter;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins whole outcome arrays, adapter arguments (parameter defaults and precedence), the SWV
 * acknowledgement, and the landed-import event, so the dispatcher can be split safely.
 */
class ExchangeTargetDispatcherGoldenTest extends TestCase {

	/** @var array<int, array<int, mixed>> Adapter calls: [method, ...arguments]. */
	private array $calls = [];

	/** @var array<int, string> Logged warnings. */
	private array $warnings = [];

	/** @var callable|null What the event dispatcher does with the event. */
	private $onEvent = null;

	/** @var array<string, mixed> The SWV receiver's answer. */
	private array $swvAnswer = ['acceptedStatus' => 'received'];

	/** @var \Throwable|null What every adapter throws, when set. */
	private ?\Throwable $adapterFailure = null;

	/**
	 * Build the dispatcher around recording adapter doubles.
	 *
	 * @return ExchangeTargetDispatcher
	 */
	private function dispatcher(): ExchangeTargetDispatcher {
		$record = function (string $method) {
			return function (...$arguments) use ($method): array {
				$this->calls[] = array_merge([$method], $arguments);
				if ($this->adapterFailure !== null) {
					throw $this->adapterFailure;
				}

				return [];
			};
		};
		$rod = $this->createMock(RodService::class);
		$rod->method('sendBericht')->willReturnCallback($record('rod'));
		$verzuimloket = $this->createMock(VerzuimloketService::class);
		$verzuimloket->method('sendMelding')->willReturnCallback($record('leerplicht'));
		$oso = $this->createMock(OsoService::class);
		$oso->method('sendExport')->willReturnCallback($record('oso'));
		$uwlr = $this->createMock(UwlrEduVService::class);
		foreach (['sendUwlrExport', 'sendEduVExport', 'syncBasispoort', 'syncEntreeContent'] as $method) {
			$uwlr->method($method)->willReturnCallback($record($method));
		}

		$swvClient = $this->createMock(SwvHandoffClient::class);
		$swvClient->method('handOff')->willReturnCallback(
			function (...$arguments): array {
				$this->calls[] = array_merge(['swv'], $arguments);
				return $this->swvAnswer;
			}
		);
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				if ($this->onEvent !== null) {
					($this->onEvent)($event);
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message): void {
				$this->warnings[] = $message;
			}
		);

		return new ExchangeTargetDispatcher(
			$rod,
			$verzuimloket,
			$oso,
			$uwlr,
			new SwvHandoffSourceAdapter($this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class), $swvClient),
			$events,
			$logger
		);

	}//end dispatcher()

	/**
	 * Dispatch one job.
	 *
	 * @param array $job jobId, target, direction, scope, records, and optionally ownerApp and ownerRef.
	 *
	 * @return array The outcome.
	 */
	private function dispatch(array $job): array {
		$this->calls = [];

		return $this->dispatcher()->dispatch(
			jobId: $job['jobId'],
			target: $job['target'],
			direction: $job['direction'],
			scope: $job['scope'],
			records: $job['records'],
			ownerApp: ($job['ownerApp'] ?? ''),
			ownerRef: ($job['ownerRef'] ?? '')
		);

	}//end dispatch()

	/**
	 * Every export target with its parameter default, scope fallback and record precedence.
	 *
	 * @return void
	 */
	public function testExportTargetsAndParameters(): void {
		$records = [
			['recordId' => 'a', 'data' => ['x' => 1]],
			['recordId' => 'b', 'data' => ['x' => 2, 'berichtsoort' => 'R', 'meldingType' => 'R', 'subtype' => 'R', 'dataService' => 'R']],
			['data' => 'not-an-array'],
		];
		$scope = ['berichtsoort' => 'S', 'meldingType' => 'S', 'subtype' => 'S', 'dataService' => 'S'];
		$expected = ['accepted' => ['a', 'b', ''], 'rejected' => [], 'refusal' => null];
		$b = ['x' => 2, 'berichtsoort' => 'R', 'meldingType' => 'R', 'subtype' => 'R', 'dataService' => 'R'];

		$this->assertSame($expected, $this->dispatch(['jobId' => 'j', 'target' => 'bron-rod', 'direction' => 'export', 'scope' => [], 'records' => $records]));
		$this->assertSame([['rod', 'inschrijving', 'j:a', ['x' => 1]], ['rod', 'R', 'j:b', $b], ['rod', 'inschrijving', 'j:', []]], $this->calls);
		$this->dispatch(['jobId' => 'j', 'target' => 'bron-rod', 'direction' => 'export', 'scope' => $scope, 'records' => $records]);
		$this->assertSame([['rod', 'S', 'j:a', ['x' => 1]], ['rod', 'R', 'j:b', $b], ['rod', 'S', 'j:', []]], $this->calls);

		$this->assertSame($expected, $this->dispatch(['jobId' => 'j', 'target' => 'leerplicht', 'direction' => 'export', 'scope' => [], 'records' => $records]));
		$this->assertSame([['leerplicht', 'eerste-melding', 'j:a', ['x' => 1]], ['leerplicht', 'R', 'j:b', $b], ['leerplicht', 'eerste-melding', 'j:', []]], $this->calls);

		$this->assertSame($expected, $this->dispatch(['jobId' => 'j', 'target' => 'oso', 'direction' => 'export', 'scope' => $scope, 'records' => $records]));
		$this->assertSame([['oso', 'j:a', ['x' => 1]], ['oso', 'j:b', $b], ['oso', 'j:', []]], $this->calls);

		$this->assertSame($expected, $this->dispatch(['jobId' => 'j', 'target' => 'uwlr', 'direction' => 'export', 'scope' => [], 'records' => $records]));
		$this->assertSame([['sendUwlrExport', 'j:a', 'pupil', ['x' => 1]], ['sendUwlrExport', 'j:b', 'R', $b], ['sendUwlrExport', 'j:', 'pupil', []]], $this->calls);

		$this->assertSame($expected, $this->dispatch(['jobId' => 'j', 'target' => 'edu-v', 'direction' => 'export', 'scope' => $scope, 'records' => $records]));
		$this->assertSame([['sendEduVExport', 'j:a', 'S', ['x' => 1]], ['sendEduVExport', 'j:b', 'R', $b], ['sendEduVExport', 'j:', 'S', []]], $this->calls);
		$this->dispatch(['jobId' => 'j', 'target' => 'edu-v', 'direction' => 'export', 'scope' => [], 'records' => $records]);
		$this->assertSame('onderwijsdeelnemers', $this->calls[0][2]);

		$this->assertSame($expected, $this->dispatch(['jobId' => 'j', 'target' => 'basispoort', 'direction' => 'sync', 'scope' => $scope, 'records' => $records]));
		$this->assertSame([['syncBasispoort', 'j:a', ['x' => 1]], ['syncBasispoort', 'j:b', $b], ['syncBasispoort', 'j:', []]], $this->calls);

		$this->assertSame($expected, $this->dispatch(['jobId' => 'j', 'target' => 'entree-content', 'direction' => 'sync', 'scope' => $scope, 'records' => $records]));
		$this->assertSame([['syncEntreeContent', 'j:a', ['x' => 1]], ['syncEntreeContent', 'j:b', $b], ['syncEntreeContent', 'j:', []]], $this->calls);

	}//end testExportTargetsAndParameters()

	/**
	 * Refusals before any record is sent, and the per-record rejections.
	 *
	 * @return void
	 */
	public function testRefusalsAndRejections(): void {
		$records = [['recordId' => 'a', 'sourceKind' => 'k', 'data' => []]];
		$none = ['accepted' => [], 'rejected' => [], 'refusal' => 'no-handler'];
		$this->assertSame($none, $this->dispatch(['jobId' => 'j', 'target' => 'uwlr', 'direction' => 'sync', 'scope' => [], 'records' => $records]));
		$this->assertSame($none, $this->dispatch(['jobId' => 'j', 'target' => 'nope', 'direction' => 'export', 'scope' => [], 'records' => $records]));
		$this->assertSame(
			['accepted' => [], 'rejected' => [], 'refusal' => 'source-missing'],
			$this->dispatch(['jobId' => 'j', 'target' => 'swv', 'direction' => 'export', 'scope' => ['receiverId' => ''], 'records' => $records])
		);
		$this->assertSame([], $this->calls);

		$this->swvAnswer = ['acceptedStatus' => 'queued'];
		$this->assertSame(
			['accepted' => [], 'rejected' => [['recordId' => 'a', 'sourceKind' => 'k', 'errorCode' => 'send-failed', 'offendingFields' => []]], 'refusal' => null],
			$this->dispatch(['jobId' => 'j', 'target' => 'swv', 'direction' => 'export', 'scope' => ['receiverId' => 'r-1'], 'records' => $records])
		);
		$this->assertSame([['swv', 'r-1', []]], $this->calls);
		$this->swvAnswer = ['acceptedStatus' => 'accepted'];
		$this->assertSame(['a'], $this->dispatch(['jobId' => 'j', 'target' => 'swv', 'direction' => 'export', 'scope' => ['receiverId' => 'r-1'], 'records' => $records])['accepted']);

		$this->adapterFailure = new OsoTranslationException(message: 'Required field "learnerEckId" and "x.y" and "learnerEckId" are wrong');
		$this->assertSame(
			['accepted' => [], 'rejected' => [['recordId' => 'a', 'sourceKind' => 'k', 'errorCode' => 'translation-failed', 'offendingFields' => ['learnerEckId', 'x.y']]], 'refusal' => null],
			$this->dispatch(['jobId' => 'j', 'target' => 'oso', 'direction' => 'export', 'scope' => [], 'records' => $records])
		);
		$this->adapterFailure = new RuntimeException('down "field"');
		$this->assertSame(
			['accepted' => [], 'rejected' => [['recordId' => '', 'sourceKind' => '', 'errorCode' => 'send-failed', 'offendingFields' => []]], 'refusal' => null],
			$this->dispatch(['jobId' => 'j', 'target' => 'basispoort', 'direction' => 'sync', 'scope' => [], 'records' => [['data' => []]]])
		);

	}//end testRefusalsAndRejections()

	/**
	 * An import lands in the owning app: the event it carries, and each answer.
	 *
	 * @return void
	 */
	public function testLandedImport(): void {
		$records = [3 => ['recordId' => 'a', 'data' => ['x' => 1]], 7 => ['recordId' => 'b']];
		$job = ['jobId' => 'j-9', 'target' => 'oso', 'direction' => 'import', 'scope' => ['s' => 1], 'records' => $records, 'ownerApp' => 'leerlinq', 'ownerRef' => 'ref-1'];
		$seen = [];
		$this->onEvent = function (ExchangeRecordsReceivedEvent $event) use (&$seen): void {
			$seen = [$event->getJobId(), $event->getOwnerApp(), $event->getTarget(), $event->getDirection(), $event->getOwnerRef(), $event->getScope(), $event->getRecords()];
			$event->accept(2, [['recordId' => 'b', 'errorCode' => 'dup']]);
		};

		$this->assertSame(
			['accepted' => [], 'rejected' => [['recordId' => 'b', 'sourceKind' => '', 'errorCode' => 'dup', 'offendingFields' => []]], 'refusal' => null, 'acceptedCount' => 1],
			$this->dispatch($job)
		);
		$this->assertSame(['j-9', 'leerlinq', 'oso', 'import', 'ref-1', ['s' => 1], [['recordId' => 'a', 'data' => ['x' => 1]], ['recordId' => 'b']]], $seen);

		$this->onEvent = null;
		$this->assertSame(['accepted' => [], 'rejected' => [], 'refusal' => 'no-owner-answer'], $this->dispatch($job));

		$this->onEvent = function (): void {
			throw new RuntimeException('record 111 broke');
		};
		$this->warnings = [];
		$this->assertSame(['accepted' => [], 'rejected' => [], 'refusal' => 'no-owner-answer'], $this->dispatch($job));
		$this->assertSame(['[ExchangeTargetDispatcher] the owning app failed on the records of job j-9: RuntimeException'], $this->warnings);
		$this->assertSame([], $this->calls);

	}//end testLandedImport()
}//end class
