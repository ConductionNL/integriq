<?php

/**
 * The persoonsgebonden nummer never reaches a log line, an exception message
 * or a stored error on the ROD path.
 *
 * Each test captures EVERY logger call (message and context, serialised) and
 * every saved record, then asserts the number is absent. A provider that
 * echoes the number in its failure message stands in for a DUO fault or an
 * HTTP client error that repeats the request body.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-003-the-persoonsgebonden-nummer-never-reaches-a-log-or-a-stored-error
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Rod;

use OCA\Integriq\Adapters\Swv\SwvHandoffClient;
use OCA\Integriq\Exception\RodProviderException;
use OCA\Integriq\Exception\RodTranslationException;
use OCA\Integriq\Service\Exchange\ExchangeTargetDispatcher;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\Rod\RodAcknowledgementTranslator;
use OCA\Integriq\Service\Rod\RodEnvelopeTranslator;
use OCA\Integriq\Service\Rod\RodPersonalNumberRedactor;
use OCA\Integriq\Service\Rod\RodProviderInterface;
use OCA\Integriq\Service\Rod\RodProviderRegistry;
use OCA\Integriq\Service\RodService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Service\VerzuimloketService;
use OCA\Integriq\Sources\Swv\SwvHandoffSourceAdapter;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\RodService
 * @covers \OCA\Integriq\Service\Rod\RodPersonalNumberRedactor
 * @covers \OCA\Integriq\Service\Rod\RodEnvelopeTranslator
 */
final class RodPersonalNumberLeakTest extends TestCase {

	/**
	 * A valid BSN (elfproef) used only in tests.
	 *
	 * @var string
	 */
	private const NUMBER = '123456782';

	/**
	 * Every logger call, serialised.
	 *
	 * @var array<int, string>
	 */
	private array $logLines = [];

	/**
	 * Every saved record, serialised.
	 *
	 * @var array<int, string>
	 */
	private array $savedRecords = [];

	/**
	 * Provider failure message; null means the provider succeeds.
	 *
	 * @var string|null
	 */
	private ?string $providerFailure = null;

	/**
	 * Build a RodService over a capturing logger and an echoing provider.
	 *
	 * @return RodService
	 */
	private function service(): RodService {
		$test   = $this;
		$logger = new class ($test) extends AbstractLogger {
			/**
			 * @param RodPersonalNumberLeakTest $test The test collecting lines.
			 */
			public function __construct(private RodPersonalNumberLeakTest $test) {
			}

			/**
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array              $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->test->collectLog(line: (string) $message.' '.json_encode($context));
			}
		};

		$objectService = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$source        = ObjectServiceMockBuilder::objectEntity(
			$this,
			['type' => 'rod', 'isEnabled' => true, 'configuration' => ['provider' => 'echo']],
			'source-1'
		);
		$objectService->method('findAll')->willReturn(['results' => [$source]]);
		$objectService->method('find')->willReturn($source);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object, $register=null, $schema=null, $uuid=null): ObjectEntity {
				$this->savedRecords[] = (string) json_encode($object);
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'saved');
			}
		);

		$provider = $this->createMock(RodProviderInterface::class);
		$provider->method('getProviderId')->willReturn('echo');
		$provider->method('send')->willReturnCallback(
			function (array $config, string $berichtsoort, string $kenmerk, string $envelopeXml): string {
				if ($this->providerFailure !== null) {
					throw new RodProviderException(message: $this->providerFailure);
				}

				return 'REF-1';
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new RodService(
			$objectService,
			new RodProviderRegistry([$provider]),
			new RodEnvelopeTranslator(),
			new RodAcknowledgementTranslator(),
			$this->createMock(IEventDispatcher::class),
			$l10n,
			$logger,
			new RawSourceResolver($objectService, $logger)
		);
	}//end service()

	/**
	 * Collect one serialised log line.
	 *
	 * @param string $line The line.
	 *
	 * @return void
	 */
	public function collectLog(string $line): void {
		$this->logLines[] = $line;
	}//end collectLog()

	/**
	 * Assert the number appears in no log line and no saved record.
	 *
	 * @return void
	 */
	private function assertNumberNowhere(): void {
		foreach (array_merge($this->logLines, $this->savedRecords) as $line) {
			$this->assertStringNotContainsString(self::NUMBER, $line);
		}
	}//end assertNumberNowhere()

	/**
	 * A school advice payload as learniq hands it.
	 *
	 * @return array<string, mixed>
	 */
	private function advies(): array {
		return [
			'persoonsgebondenNummer' => self::NUMBER,
			'persoonsgebondenNummerType' => 'burgerservicenummer',
			'adviesvolgnummer' => 'ADV2026001',
			'onderwijsaanbieder' => '100A200',
			'onderwijslocatie' => '100X200',
			'vestigingscode' => '12AB00',
			'adviesjaar' => '2026',
			'advies1' => 'VMBO_KB',
			'advies1Datum' => '2026-01-20',
			'advies2' => null,
			'advies2Datum' => null,
		];
	}//end advies()

	/**
	 * A provider failure that echoes the number is redacted in the thrown
	 * message, in the stored error and in every log line.
	 *
	 * @return void
	 */
	public function testAnEchoedNumberIsRedactedEverywhere(): void {
		$this->providerFailure = 'DUO fault: 020_persoon_niet_gevonden for '.self::NUMBER;

		try {
			$this->service()->sendBericht('schooladvies', 'job-1:rec-1', $this->advies());
			$this->fail('Expected RodProviderException.');
		} catch (RodProviderException $exception) {
			$this->assertStringNotContainsString(self::NUMBER, $exception->getMessage());
			$this->assertStringContainsString('020_persoon_niet_gevonden', $exception->getMessage());
		}

		$this->assertNotEmpty($this->savedRecords, 'a failed send persists a record');
		$this->assertNumberNowhere();
	}//end testAnEchoedNumberIsRedactedEverywhere()

	/**
	 * A successful send stores a hash, never the number.
	 *
	 * @return void
	 */
	public function testASuccessfulSendStoresNoNumber(): void {
		$this->service()->sendBericht('schooladvies', 'job-1:rec-1', $this->advies());

		$this->assertStringContainsString(hash('sha256', self::NUMBER), $this->savedRecords[0]);
		$this->assertNumberNowhere();
	}//end testASuccessfulSendStoresNoNumber()

	/**
	 * Translation failures name the field and never carry the number.
	 *
	 * @return void
	 */
	public function testTranslationFailuresNeverCarryTheNumber(): void {
		$cases = [
			array_merge($this->advies(), ['persoonsgebondenNummerType' => 'paspoort']),
			array_merge($this->advies(), ['persoonsgebondenNummer' => self::NUMBER.'1']),
			array_merge($this->advies(), ['advies1' => 'vmbo kb']),
			array_merge($this->advies(), ['advies1' => null, 'advies1Datum' => null]),
		];
		foreach ($cases as $payload) {
			try {
				$this->service()->sendBericht('schooladvies', 'k', $payload);
				$this->fail('Expected RodTranslationException.');
			} catch (RodTranslationException $exception) {
				$this->assertStringNotContainsString(self::NUMBER, $exception->getMessage());
			}
		}

		$this->assertNumberNowhere();
	}//end testTranslationFailuresNeverCarryTheNumber()

	/**
	 * The exchange dispatcher stores only codes and field names for a rejection.
	 *
	 * @return void
	 */
	public function testAnExchangeRejectionCarriesNoNumber(): void {
		$this->providerFailure = 'HTTP 500 echoing <burgerservicenummer>'.self::NUMBER.'</burgerservicenummer>';
		$dispatcher = new ExchangeTargetDispatcher(
			$this->service(),
			$this->createMock(VerzuimloketService::class),
			$this->createMock(OsoService::class),
			$this->createMock(UwlrEduVService::class),
			new SwvHandoffSourceAdapter(
				$this->createMock(IAppConfig::class),
				$this->createMock(LoggerInterface::class),
				$this->createMock(SwvHandoffClient::class)
			)
		);

		$outcome = $dispatcher->dispatch(
			'job-1',
			'bron-rod',
			'export',
			['berichtsoort' => 'schooladvies'],
			[['recordId' => 'rec-1', 'sourceKind' => 'school-advies', 'data' => $this->advies()]]
		);

		$this->assertSame('send-failed', $outcome['rejected'][0]['errorCode']);
		$this->assertStringNotContainsString(self::NUMBER, (string) json_encode($outcome));
		$this->assertNumberNowhere();
	}//end testAnExchangeRejectionCarriesNoNumber()

	/**
	 * The redactor removes the known number and any unknown nine-digit run.
	 *
	 * @return void
	 */
	public function testTheRedactorRemovesKnownAndUnknownNumbers(): void {
		$redactor = new RodPersonalNumberRedactor();

		$this->assertSame(
			'a '.RodPersonalNumberRedactor::MASK.' b '.RodPersonalNumberRedactor::MASK.' c 1234567890',
			$redactor->redact('a '.self::NUMBER.' b 999999990 c 1234567890', self::NUMBER)
		);
	}//end testTheRedactorRemovesKnownAndUnknownNumbers()
}//end class
