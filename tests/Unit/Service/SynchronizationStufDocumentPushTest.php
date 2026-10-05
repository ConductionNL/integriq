<?php

/**
 * A push with `targetConfig.stufDocument` delivers over StUF-ZDS.
 *
 * Driven through the real SynchronizationService::updateTarget() with a real
 * StufZdsDocumentDelivery, a real OutboundDocumentTranslator and a real
 * StufZknClient over a Guzzle MockHandler that answers with the recorded ZDS
 * messages, so the wiring is asserted from the caller: the case system hands
 * out the identificatie (genereerDocumentIdentificatie), the document goes in
 * with that identificatie and the file inline (voegZaakdocumentToe), the
 * identificatie becomes the contract's target id and is written back, a
 * fault is written back as failed, and a contract that already holds a
 * document sends nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\StufZkn\OutboundDocumentTranslator;
use OCA\Integriq\Service\StufZkn\StufZdsDocumentDelivery;
use OCA\Integriq\Service\StufZkn\StufZknClient;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
class SynchronizationStufDocumentPushTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../fixtures/stuf-zds/';

	private const ENDPOINT = 'https://zsh.gemeente-x.example.nl/stuf-zds';

	private const IDENTIFICATIE = '0363-DOC-2026-000042';

	private const DELIVERY = [
		'titel' => 'Besluit bezwaar (geanonimiseerd)',
		'creatiedatum' => '2026-10-05',
		'vertrouwelijkheidaanduiding' => 'openbaar',
		'zaakIdentificatie' => '0363-ZAAK-2026-0099',
		'processingStatus' => 'ready_for_writeback',
	];

	/**
	 * Requests the case system received.
	 *
	 * @var array<int,array{request:\Psr\Http\Message\RequestInterface}>
	 */
	private array $history = [];

	private array $saves = [];

	private array $httpCalls = [];

	/**
	 * The queued answers of the case system.
	 *
	 * @var list<Response>
	 */
	private array $answers = [];

	/**
	 * The StUF-ZKN source's configuration for one test.
	 *
	 * @var array<string,mixed>
	 */
	private array $configuration = [
		'provider' => 'rest',
		'baseUrl' => self::ENDPOINT,
		'organisatie' => '0363',
		'authentication' => ['encryptedToken' => 'ciphertext-blob'],
	];

	/**
	 * Set up the recorded answers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->answers = [
			new Response(200, ['Content-Type' => 'text/xml'], (string)file_get_contents(self::FIXTURES . 'genereerDocumentIdentificatie_Du02.xml')),
			new Response(200, ['Content-Type' => 'text/xml'], (string)file_get_contents(self::FIXTURES . 'voegZaakdocumentToe_Bv03.xml')),
		];
	}//end setUp()

	/**
	 * The push synchronization.
	 *
	 * @return array
	 */
	private function push(): array {
		return [
			'id' => 'push-uuid',
			'sourceType' => 'register/schema',
			'sourceId' => 'filinq/externalDocument',
			'targetType' => 'api',
			'targetId' => 'stuf-uuid',
			'targetConfig' => ['stufDocument' => ['documenttype' => 'Besluit']],
			'writeBack' => [
				'onSuccess' => ['processingStatus' => 'written_back', 'resultExternalId' => '{{ response.identificatie }}'],
				'onFailure' => ['processingStatus' => 'writeback_failed', 'writeBackError' => '{{ error.message }}'],
			],
		];
	}//end push()

	/**
	 * The service over recording fakes and the real StUF-ZDS leg.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$or = $this->createMock(ORObjectService::class);
		$or->method('find')->willReturnCallback(
			function ($id) {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject(match ((string)$id) {
					'push-uuid' => $this->push(),
					'delivery-1' => self::DELIVERY,
					default => ['type' => 'stuf-zkn', 'location' => self::ENDPOINT, 'name' => 'Zaaksysteem', 'configuration' => $this->configuration, 'ontvangerOrganisatie' => '0363', 'ontvangerApplicatie' => 'ZSH'],
				});
				return $entity;
			}
		);
		$or->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$or->method('saveObject')->willReturnCallback(
			function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false) {
				$this->saves[] = ['object' => $object, 'uuid' => $uuid, 'silent' => $silent];
				return new ObjectEntity();
			}
		);

		$calls = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnArgument(0);
		$calls->method('call')->willReturnCallback(
			function ($source, string $endpoint='', string $method='GET') {
				$this->httpCalls[] = [$method, $endpoint];
				throw new \RuntimeException('A StUF-ZDS document push must not make the generic JSON call.');
			}
		);

		$stack = HandlerStack::create(new MockHandler($this->answers));
		$this->history = [];
		$stack->push(Middleware::history($this->history));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('raw-token-value');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$client = new StufZknClient(
			new Client(['handler' => $stack]),
			$crypto,
			$l10n,
			$logger,
			new MtlsConfigResolver($crypto),
			new MtlsTransportService(new MtlsTransportOptionsBuilder(), $logger)
		);

		$file = new class {
			public function getContent(): string {
				return 'ANON!';
			}

			public function getName(): string {
				return 'besluit-geanonimiseerd.pdf';
			}

			public function getMimeType(): string {
				return 'application/pdf';
			}
		};
		$fileService = new class($file) {
			public function __construct(private object $file) {
			}

			public function getFiles(mixed $object): array {
				return [$this->file];
			}

			public function getFileById(int $id): ?object {
				return null;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): mixed => match ($id) {
				StufZdsDocumentDelivery::class => new StufZdsDocumentDelivery(client: $client, translator: new OutboundDocumentTranslator()),
				'OCA\OpenRegister\Service\FileService' => $fileService,
				'OCA\OpenRegister\Service\ObjectService' => $or,
				default => null,
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$sourceMapping = $this->createMock(ObjectService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn($or);

		return new SynchronizationService(
			$calls,
			$this->createMock(MappingService::class),
			$container,
			$or,
			$sourceMapping,
			$this->createMock(LoggerInterface::class),
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * Push the delivery once.
	 *
	 * @param string|null $targetId The identificatie the contract already holds.
	 *
	 * @return array The contract.
	 */
	private function pushOnce(?string $targetId=null): array {
		$mapped = self::DELIVERY;
		return $this->service()->updateTarget(
			synchronizationContract: ['synchronizationId' => 'push-uuid', 'originId' => 'delivery-1', 'targetId' => $targetId],
			targetObject: $mapped
		);
	}//end pushOnce()

	/**
	 * The SOAPAction and body of each request the case system received.
	 *
	 * @return list<array{action:string,body:string,url:string}>
	 */
	private function received(): array {
		return array_map(
			static fn (array $entry): array => [
				'action' => $entry['request']->getHeaderLine('SOAPAction'),
				'body' => (string)$entry['request']->getBody(),
				'url' => (string)$entry['request']->getUri(),
			],
			$this->history
		);
	}//end received()

	/**
	 * An anonymised copy goes back: identificatie asked, document added with it, identificatie written back.
	 *
	 * @return void
	 */
	public function testAnAnonymisedCopyGoesBackAndItsIdentificatieIsWrittenBack(): void {
		$contract = $this->pushOnce();

		$this->assertSame(self::IDENTIFICATIE, $contract['targetId']);
		$this->assertSame([], $this->httpCalls);

		$received = $this->received();
		$this->assertCount(2, $received);
		$this->assertSame('"http://www.egem.nl/StUF/sector/zkn/0310/genereerDocumentIdentificatie_Di02"', $received[0]['action']);
		$this->assertStringContainsString('genereerDocumentIdentificatie_Di02', $received[0]['body']);
		$this->assertSame('"http://www.egem.nl/StUF/sector/zkn/0310/voegZaakdocumentToe_Lk01"', $received[1]['action']);
		$this->assertSame(self::ENDPOINT, $received[1]['url']);
		$this->assertStringContainsString('<zkn:identificatie>' . self::IDENTIFICATIE . '</zkn:identificatie>', $received[1]['body']);
		$this->assertStringContainsString('<zkn:titel>Besluit bezwaar (geanonimiseerd)</zkn:titel>', $received[1]['body']);
		$this->assertStringContainsString(base64_encode('ANON!'), $received[1]['body']);
		$this->assertStringContainsString('<zkn:identificatie>0363-ZAAK-2026-0099</zkn:identificatie>', $received[1]['body']);
		$this->assertStringContainsString('<StUF:applicatie>ZSH</StUF:applicatie>', $received[1]['body']);
		$this->assertSame('Bearer raw-token-value', $this->history[1]['request']->getHeaderLine('Authorization'));

		$this->assertCount(1, $this->saves);
		$this->assertTrue($this->saves[0]['silent']);
		$this->assertSame('written_back', $this->saves[0]['object']['processingStatus']);
		$this->assertSame(self::IDENTIFICATIE, $this->saves[0]['object']['resultExternalId']);
	}//end testAnAnonymisedCopyGoesBackAndItsIdentificatieIsWrittenBack()

	/**
	 * A delivery that already has its document sends nothing.
	 *
	 * @return void
	 */
	public function testADeliveryWithADocumentSendsNothing(): void {
		$contract = $this->pushOnce(targetId: self::IDENTIFICATIE);

		$this->assertSame(self::IDENTIFICATIE, $contract['targetId']);
		$this->assertSame([], $this->history);
		$this->assertSame([], $this->saves);
	}//end testADeliveryWithADocumentSendsNothing()

	/**
	 * A Fo03 fault writes the case system's own words back once and fails the push.
	 *
	 * @return void
	 */
	public function testAFaultIsWrittenBackAsFailed(): void {
		$this->answers[1] = new Response(500, ['Content-Type' => 'text/xml'], (string)file_get_contents(self::FIXTURES . 'voegZaakdocumentToe_Fo03.xml'));

		try {
			$this->pushOnce();
			$this->fail('A refused document must fail the push.');
		} catch (StufZknProviderException $e) {
			$this->assertStringContainsString('Zaak 0363-ZAAK-2026-0099 bestaat niet', $e->getMessage());
		}

		$this->assertCount(1, $this->saves);
		$this->assertSame('writeback_failed', $this->saves[0]['object']['processingStatus']);
		$this->assertStringContainsString('StUF058', $this->saves[0]['object']['writeBackError']);
		$this->assertStringContainsString('Zaak 0363-ZAAK-2026-0099 bestaat niet', $this->saves[0]['object']['writeBackError']);
	}//end testAFaultIsWrittenBackAsFailed()

	/**
	 * A source in log mode delivers nothing and says so, instead of writing back a made-up identificatie.
	 *
	 * @return void
	 */
	public function testASourceInLogModeIsRefused(): void {
		$this->configuration['provider'] = 'log';

		try {
			$this->pushOnce();
			$this->fail('A log-mode source must not deliver a document.');
		} catch (StufZknProviderException $e) {
			$this->assertStringContainsString('provider', $e->getMessage());
		}

		$this->assertSame([], $this->history);
		$this->assertSame('writeback_failed', $this->saves[0]['object']['processingStatus']);
	}//end testASourceInLogModeIsRefused()

	/**
	 * A delivery without the case's identificatie is refused before anything is sent.
	 *
	 * @return void
	 */
	public function testADeliveryWithoutACaseIsRefusedBeforeSending(): void {
		$service = $this->service();
		$mapped  = array_diff_key(self::DELIVERY, ['zaakIdentificatie' => true]);

		try {
			$service->updateTarget(
				synchronizationContract: ['synchronizationId' => 'push-uuid', 'originId' => 'delivery-1', 'targetId' => null],
				targetObject: $mapped
			);
			$this->fail('A delivery without a case must fail the push.');
		} catch (\Exception $e) {
			$this->assertStringContainsString('zaakIdentificatie', $e->getMessage());
		}

		$this->assertSame([], $this->history);
		$this->assertSame('writeback_failed', $this->saves[0]['object']['processingStatus']);
	}//end testADeliveryWithoutACaseIsRefusedBeforeSending()
}//end class
