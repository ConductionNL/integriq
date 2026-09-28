<?php

/**
 * ExchangeTargetDispatcher: records reach the right adapter, one at a time.
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
use OCA\Integriq\Exception\RodProviderException;
use OCA\Integriq\Exception\VerzuimloketTranslationException;
use OCA\Integriq\Service\Exchange\ExchangeTargetDispatcher;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\RodService;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Service\VerzuimloketService;
use OCA\Integriq\Sources\Swv\SwvHandoffSourceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-005 scenarios.
 */
class ExchangeTargetDispatcherTest extends TestCase {

	/**
	 * @var RodService&MockObject
	 */
	private $rod;

	/**
	 * @var VerzuimloketService&MockObject
	 */
	private $verzuimloket;

	/**
	 * @var OsoService&MockObject
	 */
	private $oso;

	/**
	 * @var UwlrEduVService&MockObject
	 */
	private $uwlr;

	/**
	 * @var SwvHandoffClient&MockObject
	 */
	private $swvClient;

	/**
	 * The dispatcher under test.
	 *
	 * @var ExchangeTargetDispatcher
	 */
	private ExchangeTargetDispatcher $dispatcher;

	/**
	 * Set up adapter doubles. The SWV facade is final, so it is built for real
	 * around a client double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->rod = $this->createMock(RodService::class);
		$this->verzuimloket = $this->createMock(VerzuimloketService::class);
		$this->oso = $this->createMock(OsoService::class);
		$this->uwlr = $this->createMock(UwlrEduVService::class);
		$this->swvClient = $this->createMock(SwvHandoffClient::class);

		$swv = new SwvHandoffSourceAdapter(
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->swvClient
		);

		$this->dispatcher = new ExchangeTargetDispatcher($this->rod, $this->verzuimloket, $this->oso, $this->uwlr, $swv);

	}//end setUp()

	/**
	 * Two allowed learner records.
	 *
	 * @return array<int, array<string, mixed>> The records.
	 */
	private function records(): array {
		return [
			['recordId' => 'a', 'sourceKind' => 'learner-profile', 'data' => ['voornamen' => 'Sanne', 'bsn' => '111']],
			['recordId' => 'b', 'sourceKind' => 'learner-profile', 'data' => ['voornamen' => 'Daan', 'bsn' => '222']],
		];

	}//end records()

	/**
	 * A ROD export sends one bericht per record with the scope's berichtsoort
	 * and the kenmerk job:record.
	 *
	 * @return void
	 */
	public function testARodExportSendsOneBerichtPerRecord(): void {
		$calls = [];
		$this->rod->expects($this->exactly(2))->method('sendBericht')->willReturnCallback(
			static function (string $berichtsoort, string $kenmerk, array $payload) use (&$calls): array {
				$calls[] = [$berichtsoort, $kenmerk, $payload['voornamen']];
				return ['ref' => $kenmerk, 'berichtsoort' => $berichtsoort, 'status' => 'sent'];
			}
		);

		$outcome = $this->dispatcher->dispatch('job-1', 'bron-rod', 'export', ['berichtsoort' => 'inschrijving'], $this->records());

		$this->assertSame(['a', 'b'], $outcome['accepted']);
		$this->assertSame([], $outcome['rejected']);
		$this->assertSame([['inschrijving', 'job-1:a', 'Sanne'], ['inschrijving', 'job-1:b', 'Daan']], $calls);

	}//end testARodExportSendsOneBerichtPerRecord()

	/**
	 * A record's own parameter beats the scope's.
	 *
	 * @return void
	 */
	public function testARecordParameterBeatsTheScope(): void {
		$this->rod->expects($this->once())->method('sendBericht')
			->with('uitschrijving', 'job-1:a', $this->anything())
			->willReturn(['status' => 'sent']);

		$this->dispatcher->dispatch(
			'job-1',
			'bron-rod',
			'export',
			['berichtsoort' => 'inschrijving'],
			[['recordId' => 'a', 'sourceKind' => 'learner-profile', 'data' => ['berichtsoort' => 'uitschrijving']]]
		);

	}//end testARecordParameterBeatsTheScope()

	/**
	 * One record fails translation, the other is sent.
	 *
	 * @return void
	 */
	public function testOneRecordFailsTranslation(): void {
		$this->verzuimloket->method('sendMelding')->willReturnCallback(
			static function (string $type, string $kenmerk, array $payload): array {
				if ($kenmerk === 'job-2:b') {
					throw new VerzuimloketTranslationException('Required field "bsn" is missing for eerste-melding.');
				}

				return ['status' => 'sent'];
			}
		);

		$outcome = $this->dispatcher->dispatch('job-2', 'leerplicht', 'export', [], $this->records());

		$this->assertSame(['a'], $outcome['accepted']);
		$this->assertCount(1, $outcome['rejected']);
		$this->assertSame('translation-failed', $outcome['rejected'][0]['errorCode']);
		$this->assertSame(['bsn'], $outcome['rejected'][0]['offendingFields']);
		$this->assertSame('b', $outcome['rejected'][0]['recordId']);

	}//end testOneRecordFailsTranslation()

	/**
	 * A transport failure is send-failed, with no field names.
	 *
	 * @return void
	 */
	public function testATransportFailureIsSendFailed(): void {
		$this->rod->method('sendBericht')->willThrowException(new RodProviderException('No active ROD source'));

		$outcome = $this->dispatcher->dispatch('job-3', 'bron-rod', 'export', [], $this->records());

		$this->assertSame([], $outcome['accepted']);
		$this->assertSame('send-failed', $outcome['rejected'][0]['errorCode']);
		$this->assertSame([], $outcome['rejected'][0]['offendingFields']);

	}//end testATransportFailureIsSendFailed()

	/**
	 * The UWLR, Edu-V, Basispoort, Entree and OSO handlers call their own sends.
	 *
	 * @return void
	 */
	public function testTheOtherHandledTargetsCallTheirAdapters(): void {
		$record = [['recordId' => 'a', 'sourceKind' => 'learner-profile', 'data' => ['eckId' => 'x']]];

		$this->uwlr->expects($this->once())->method('sendUwlrExport')->with('j:a', 'group', $this->anything())->willReturn([]);
		$this->uwlr->expects($this->once())->method('sendEduVExport')->with('j:a', 'onderwijsdeelnemers', $this->anything())->willReturn([]);
		$this->uwlr->expects($this->once())->method('syncBasispoort')->willReturn([]);
		$this->uwlr->expects($this->once())->method('syncEntreeContent')->willReturn([]);
		$this->oso->expects($this->once())->method('sendExport')->with('j:a', ['eckId' => 'x'])->willReturn([]);

		$this->assertSame(['a'], $this->dispatcher->dispatch('j', 'uwlr', 'export', ['subtype' => 'group'], $record)['accepted']);
		$this->assertSame(['a'], $this->dispatcher->dispatch('j', 'edu-v', 'export', [], $record)['accepted']);
		$this->assertSame(['a'], $this->dispatcher->dispatch('j', 'basispoort', 'sync', [], $record)['accepted']);
		$this->assertSame(['a'], $this->dispatcher->dispatch('j', 'entree-content', 'sync', [], $record)['accepted']);
		$this->assertSame(['a'], $this->dispatcher->dispatch('j', 'oso', 'export', [], $record)['accepted']);

	}//end testTheOtherHandledTargetsCallTheirAdapters()

	/**
	 * An SWV hand-off needs a receiver; with one, an acknowledgement counts.
	 *
	 * @return void
	 */
	public function testAnSwvHandOffNeedsAReceiver(): void {
		$record = [['recordId' => 's', 'sourceKind' => 'support-request', 'data' => ['hulpvraagDomein' => 'gedrag']]];

		$this->assertSame('source-missing', $this->dispatcher->dispatch('j', 'swv', 'export', [], $record)['refusal']);

		$this->swvClient->expects($this->once())->method('handOff')->with('swv-kindkans', ['hulpvraagDomein' => 'gedrag'])
			->willReturn(['acceptedStatus' => 'received']);
		$outcome = $this->dispatcher->dispatch('j', 'swv', 'export', ['receiverId' => 'swv-kindkans'], $record);
		$this->assertSame(['s'], $outcome['accepted']);

	}//end testAnSwvHandOffNeedsAReceiver()

	/**
	 * A target without an adapter, and an import direction, have no handler.
	 *
	 * @return void
	 */
	public function testATargetWithoutAnAdapter(): void {
		$this->assertFalse($this->dispatcher->supports('surfconext', 'sync'));
		$this->assertFalse($this->dispatcher->supports('oso', 'import'));
		$this->assertTrue($this->dispatcher->supports('oso', 'export'));
		$this->assertSame('no-handler', $this->dispatcher->dispatch('j', 'lvs-results', 'import', [], [])['refusal']);

	}//end testATargetWithoutAnAdapter()
}//end class
