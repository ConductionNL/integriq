<?php

/**
 * The XML webhooks on the consumer model: ROD, OSO, UWLR/Edu-V, Verzuimloket,
 * iWMO/iJW and StUF-ZKN.
 *
 * Each case drives the real controller and the real service (with its real
 * translators) over the connection world: a fake OpenRegister that honours
 * RBAC and records who wrote. The signed XML reaches the controller through
 * `php://input`, WITHOUT a session, as the partner sends it. Before this
 * change every one of them read its trust from an admin-only `source` with
 * RBAC on, found nothing, and answered 401 (proven live on 2026-10-04).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-a-signed-webhook-acts-as-its-consumers-account-req-cm-020
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\IwmoIjwController;
use OCA\Integriq\Controller\OsoController;
use OCA\Integriq\Controller\RodController;
use OCA\Integriq\Controller\StufZknController;
use OCA\Integriq\Controller\UwlrEduVController;
use OCA\Integriq\Controller\VerzuimloketController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Intake\WebhookProfile;
use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCA\Integriq\Service\IwmoIjw\InboundReturnTranslator;
use OCA\Integriq\Service\IwmoIjw\IStandardsClient;
use OCA\Integriq\Service\IwmoIjw\LogIwmoIjwProvider;
use OCA\Integriq\Service\IwmoIjw\OutboundMessageTranslator;
use OCA\Integriq\Service\IwmoIjwSyncService;
use OCA\Integriq\Service\Oso\OsoAcknowledgementTranslator;
use OCA\Integriq\Service\Oso\OsoExportEnvelopeTranslator;
use OCA\Integriq\Service\Oso\OsoImportTranslator;
use OCA\Integriq\Service\Oso\OsoProviderRegistry;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\Rod\RodAcknowledgementTranslator;
use OCA\Integriq\Service\Rod\RodEnvelopeTranslator;
use OCA\Integriq\Service\Rod\RodProviderRegistry;
use OCA\Integriq\Service\RodService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\StufZkn\InboundMessageTranslator;
use OCA\Integriq\Service\StufZkn\LogStufZknProvider;
use OCA\Integriq\Service\StufZkn\OutboundNotificationTranslator;
use OCA\Integriq\Service\StufZkn\StufZknAcknowledgementBuilder;
use OCA\Integriq\Service\StufZkn\StufZknClient;
use OCA\Integriq\Service\StufZknSyncService;
use OCA\Integriq\Service\UwlrEduV\BasispoortSyncTranslator;
use OCA\Integriq\Service\UwlrEduV\EduVExportEnvelopeTranslator;
use OCA\Integriq\Service\UwlrEduV\EntreeContentSyncTranslator;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVAcknowledgementTranslator;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVProviderRegistry;
use OCA\Integriq\Service\UwlrEduV\UwlrExportEnvelopeTranslator;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketAcknowledgementTranslator;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketEnvelopeTranslator;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketProviderRegistry;
use OCA\Integriq\Service\VerzuimloketService;
use OCA\Integriq\Tests\Helpers\PhpInputStream;
use OCA\Integriq\Tests\Helpers\WebhookWorld;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every XML webhook stores as its connection's account, and refuses with 503 without one.
 */
class XmlWebhooksConsumerTest extends TestCase {
	use WebhookWorld;

	/**
	 * The account every case's connection names.
	 *
	 * @var string
	 */
	private const ACCOUNT = 'partner-intake';

	/**
	 * The retour of a message integriq sent: kenmerk K-1, accepted.
	 *
	 * @var string
	 */
	private const RETOUR = '<Retour><stuurgegevens><kenmerk>K-1</kenmerk><signaalcode>0</signaalcode></stuurgegevens>'
		. '<body><omschrijving>Verwerkt</omschrijving></body></Retour>';

	/**
	 * Put php://input back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		PhpInputStream::restore();
		parent::tearDown();

	}//end tearDown()

	/**
	 * The cases: webhook, controller method, body, outbound message to match, written schema.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>|null, 4: string}>
	 */
	public static function webhooks(): array {
		$oso = (string)file_get_contents(__DIR__ . '/../../fixtures/oso/import-complete.xml');
		$stuf = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:StUF="http://www.egem.nl/StUF/StUF0301"'
			. ' xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"><soap:Body><zkn:zakLk01><zkn:stuurgegevens>'
			. '<StUF:berichtcode>Lk01</StUF:berichtcode><StUF:zender><StUF:organisatie>Gemeente X</StUF:organisatie></StUF:zender>'
			. '<StUF:ontvanger><StUF:organisatie>Integriq</StUF:organisatie></StUF:ontvanger>'
			. '<StUF:referentienummer>REF-1</StUF:referentienummer><StUF:tijdstipBericht>20261004120000</StUF:tijdstipBericht>'
			. '<StUF:entiteittype>ZAK</StUF:entiteittype></zkn:stuurgegevens><zkn:parameters><StUF:mutatiesoort>T</StUF:mutatiesoort>'
			. '</zkn:parameters><zkn:object StUF:entiteittype="ZAK" StUF:verwerkingssoort="T"><zkn:identificatie>ZAAK-1</zkn:identificatie>'
			. '<zkn:omschrijving>Kapvergunning</zkn:omschrijving><zkn:zaaktype><zkn:code>B0337</zkn:code></zkn:zaaktype>'
			. '<zkn:registratiedatum>20261004</zkn:registratiedatum><zkn:startdatum>20261004</zkn:startdatum>'
			. '</zkn:object></zkn:zakLk01></soap:Body></soap:Envelope>';

		return [
			'rod retour' => ['rod', 'retour', self::RETOUR, ['berichtsoort' => 'inschrijving'], 'rod_message'],
			'oso import' => ['oso', 'import', $oso, null, 'oso_message'],
			'oso retour' => ['oso', 'retour', self::RETOUR, [], 'oso_message'],
			'uwlr-eduv retour' => ['uwlrEduV', 'retour', self::RETOUR, ['target' => 'uwlr'], 'uwlr_eduv_message'],
			'verzuimloket retour' => ['verzuimloket', 'retour', self::RETOUR, ['meldingType' => 'ziekmelding'], 'verzuim_message'],
			'iwmo-ijw retour' => [
				'iwmoIjw',
				'inbound',
				'<Bericht><stuurgegevens><berichtcode>Wmo305</berichtcode><kenmerk>K-1</kenmerk></stuurgegevens><body><resultaat>akkoord</resultaat></body></Bericht>',
				null,
				'iwmo_ijw_message',
			],
			'stuf-zkn kennisgeving' => ['stufZkn', 'inbound', $stuf, null, 'stuf_message'],
		];

	}//end webhooks()

	/**
	 * A signed delivery without a session is stored, and only as the connection's account.
	 *
	 * @param string                    $profile  The WebhookProfiles factory.
	 * @param string                    $method   The controller method.
	 * @param string                    $body     The signed XML.
	 * @param array<string, mixed>|null $outbound The outbound message the retour answers, or null.
	 * @param string                    $schema   The schema the delivery writes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-a-signed-delivery-without-a-session-is-stored-as-the-consumers-account
	 */
	#[DataProvider('webhooks')]
	public function testASignedDeliveryIsStoredAsTheConnectionsAccount(string $profile, string $method, string $body, ?array $outbound, string $schema): void {
		$objectService = $this->world(profile: WebhookProfiles::$profile(), outbound: $outbound, schema: $schema);

		$response = $this->controller(profile: $profile, objectService: $objectService, body: $body, signature: $this->signWebhook($body))->$method();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$writes = $this->writesTo($schema);
		$this->assertNotSame([], $writes, 'the delivery was stored');
		$this->assertSame([], array_values(array_filter($writes, static fn (string $write): bool => str_ends_with($write, ' by ' . self::ACCOUNT) === false)), 'every write runs as the connection account');
		$this->assertNull($this->worldSession->getUser(), 'the account is restored after the delivery');
		if ($profile === 'stufZkn') {
			// StUF-ZKN answers in StUF: the sync service's reply goes back verbatim.
			$this->assertStringContainsString('Bv03Bericht', (string)$response->render());
		}

	}//end testASignedDeliveryIsStoredAsTheConnectionsAccount()

	/**
	 * Without an account: 503 with the webhook's error code, an alert, and nothing written.
	 *
	 * @param string                    $profile  The WebhookProfiles factory.
	 * @param string                    $method   The controller method.
	 * @param string                    $body     The signed XML.
	 * @param array<string, mixed>|null $outbound The outbound message the retour answers, or null.
	 * @param string                    $schema   The schema the delivery writes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-a-connection-without-a-usable-account-refuses-with-503
	 */
	#[DataProvider('webhooks')]
	public function testAConnectionWithoutAnAccountAnswers503(string $profile, string $method, string $body, ?array $outbound, string $schema): void {
		$webhook = WebhookProfiles::$profile();
		$objectService = $this->world(profile: $webhook, outbound: $outbound, schema: $schema);
		$this->addWebhookConsumer(profile: $webhook, userId: '');

		$response = $this->controller(profile: $profile, objectService: $objectService, body: $body, signature: $this->signWebhook($body))->$method();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame($webhook->channel . '_account_unavailable', $response->getData()['error']);
		$this->assertSame([], $this->worldWrites);
		$this->assertSame([$webhook->channel . '/no_account'], $this->webhookAlerts);

	}//end testAConnectionWithoutAnAccountAnswers503()

	/**
	 * A wrong signature: 401, nothing written.
	 *
	 * @param string                    $profile  The WebhookProfiles factory.
	 * @param string                    $method   The controller method.
	 * @param string                    $body     The signed XML.
	 * @param array<string, mixed>|null $outbound The outbound message the retour answers, or null.
	 * @param string                    $schema   The schema the delivery writes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-a-wrong-signature-is-refused-with-401
	 */
	#[DataProvider('webhooks')]
	public function testAWrongSignatureAnswers401(string $profile, string $method, string $body, ?array $outbound, string $schema): void {
		$objectService = $this->world(profile: WebhookProfiles::$profile(), outbound: $outbound, schema: $schema);

		$response = $this->controller(
			profile: $profile,
			objectService: $objectService,
			body: $body,
			signature: $this->signWebhook($body, secret: 'not-the-secret')
		)->$method();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->worldWrites);

	}//end testAWrongSignatureAnswers401()

	/**
	 * A connection whose account may write the schema, and the outbound message a retour answers.
	 *
	 * @param WebhookProfile            $profile  The webhook.
	 * @param array<string, mixed>|null $outbound Extra fields of the outbound message, or null for none.
	 * @param string                    $schema   The message schema.
	 *
	 * @return ORObjectService The world's ObjectService.
	 */
	private function world(WebhookProfile $profile, ?array $outbound, string $schema): ORObjectService {
		$this->resetWorld();
		$this->addAccount(uid: self::ACCOUNT, grants: ['create', 'read', 'update']);
		$this->addWebhookConsumer(profile: $profile, userId: self::ACCOUNT);
		if ($outbound !== null) {
			$this->addOther(
				schema: $schema,
				uuid: 'outbound-1',
				data: ['direction' => 'outbound', 'status' => 'sent', 'kenmerk' => 'K-1'] + $outbound,
				adminOnly: false
			);
		}

		return $this->buildWorldObjectService();

	}//end world()

	/**
	 * The real controller of a webhook over its real service and the world.
	 *
	 * @param string          $profile       The WebhookProfiles factory name.
	 * @param ORObjectService $objectService The world's ObjectService.
	 * @param string          $body          The raw body.
	 * @param string          $signature     The signature header.
	 *
	 * @return object The controller.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) one constructor per webhook, kept side by side.
	 */
	private function controller(string $profile, ORObjectService $objectService, string $body, string $signature): object {
		$common = [
			'appName' => 'integriq',
			'request' => $this->webhookRequest(body: $body, signature: $signature),
			'gate' => $this->buildWorldGate(objectService: $objectService),
			'userSession' => $this->createMock(IUserSession::class),
			'actionAuth' => $this->createMock(ActionAuthService::class),
			'l' => $this->webhookL10n(),
			'logger' => new NullLogger(),
		];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$raw = $this->createMock(RawSourceResolver::class);

		return match ($profile) {
			'rod' => new RodController(
				...$common,
				rodService: new RodService(
					objectService: $objectService,
					providers: $this->createMock(RodProviderRegistry::class),
					envelopeTranslator: new RodEnvelopeTranslator(),
					ackTranslator: new RodAcknowledgementTranslator(),
					eventDispatcher: $dispatcher,
					l: $this->webhookL10n(),
					logger: new NullLogger(),
					rawSourceResolver: $raw
				)
			),
			'oso' => new OsoController(
				...$common,
				osoService: new OsoService(
					objectService: $objectService,
					providers: $this->createMock(OsoProviderRegistry::class),
					exportTranslator: new OsoExportEnvelopeTranslator(),
					importTranslator: new OsoImportTranslator(),
					ackTranslator: new OsoAcknowledgementTranslator(),
					eventDispatcher: $dispatcher,
					l: $this->webhookL10n(),
					logger: new NullLogger(),
					rawSourceResolver: $raw
				)
			),
			'uwlrEduV' => new UwlrEduVController(
				...$common,
				uwlrEduVService: new UwlrEduVService(
					objectService: $objectService,
					providers: $this->createMock(UwlrEduVProviderRegistry::class),
					uwlrTranslator: new UwlrExportEnvelopeTranslator(),
					eduVTranslator: new EduVExportEnvelopeTranslator(),
					basispoortTranslator: $this->createMock(BasispoortSyncTranslator::class),
					entreeTranslator: $this->createMock(EntreeContentSyncTranslator::class),
					ackTranslator: new UwlrEduVAcknowledgementTranslator(),
					eventDispatcher: $dispatcher,
					l: $this->webhookL10n(),
					logger: new NullLogger(),
					rawSourceResolver: $raw
				)
			),
			'verzuimloket' => new VerzuimloketController(
				...$common,
				verzuimloketService: new VerzuimloketService(
					objectService: $objectService,
					providers: $this->createMock(VerzuimloketProviderRegistry::class),
					envelopeTranslator: new VerzuimloketEnvelopeTranslator(),
					ackTranslator: new VerzuimloketAcknowledgementTranslator(),
					eventDispatcher: $dispatcher,
					l: $this->webhookL10n(),
					logger: new NullLogger(),
					rawSourceResolver: $raw
				)
			),
			'iwmoIjw' => new IwmoIjwController(
				...$common,
				syncService: new IwmoIjwSyncService(
					objectService: $objectService,
					logProvider: $this->createMock(LogIwmoIjwProvider::class),
					restProvider: $this->createMock(IStandardsClient::class),
					outboundTranslator: new OutboundMessageTranslator(),
					inboundTranslator: new InboundReturnTranslator(),
					l: $this->webhookL10n(),
					logger: new NullLogger(),
					rawSourceResolver: $raw
				)
			),
			'stufZkn' => new StufZknController(
				...$common,
				syncService: new StufZknSyncService(
					objectService: $objectService,
					logProvider: $this->createMock(LogStufZknProvider::class),
					restProvider: $this->createMock(StufZknClient::class),
					inboundTranslator: new InboundMessageTranslator(),
					outboundTranslator: new OutboundNotificationTranslator(),
					ackBuilder: new StufZknAcknowledgementBuilder(),
					logger: new NullLogger(),
					rawSourceResolver: $raw
				)
			),
		};

	}//end controller()
}//end class
