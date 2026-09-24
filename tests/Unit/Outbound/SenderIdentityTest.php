<?php

/**
 * Unit tests for the sender identity, the domain alignment check, the
 * quoting level and the signature stripper.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\Identity\DnsResolverInterface;
use OCA\Integriq\Outbound\Identity\DomainAlignmentChecker;
use OCA\Integriq\Outbound\Identity\MessageComposer;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\Integriq\Outbound\Identity\SignatureStripper;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\RenderBoundarySimulatingObjectService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A SenderIdentityService whose broker access is supplied by the test.
 *
 * The real seams probe for an OpenRegister class that is not autoloadable in a
 * pure-unit run, so without this every brokered path would report "unresolvable"
 * and the tests would pass for the wrong reason.
 */
class BrokeredSenderIdentityService extends SenderIdentityService {

	/**
	 * The broker double, or null to simulate an unavailable broker.
	 *
	 * @var object|null
	 */
	public ?object $brokerDouble = null;

	/**
	 * Whether the broker class is loadable.
	 *
	 * @return bool Whether a double was supplied.
	 */
	protected function isBrokerClassAvailable(): bool {
		return $this->brokerDouble !== null;

	}//end isBrokerClassAvailable()

	/**
	 * The broker double.
	 *
	 * @return object The double.
	 */
	protected function resolveBroker(): object {
		return $this->brokerDouble;

	}//end resolveBroker()
}//end class

/**
 * A DNS zone a test hands the checker instead of the internet.
 */
class FixtureDnsResolver implements DnsResolverInterface {

	/**
	 * Constructor.
	 *
	 * @param array<string,array<int,string>> $zone Host to its TXT records.
	 */
	public function __construct(private readonly array $zone) {

	}//end __construct()

	/**
	 * The TXT records published for one host.
	 *
	 * @param string $host The host.
	 *
	 * @return array<int,string> The records.
	 */
	public function txt(string $host): array {
		return ($this->zone[$host] ?? []);

	}//end txt()

}//end class

/**
 * Tests what a recipient sees, and what the domain says about it.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class SenderIdentityTest extends TestCase {

	/**
	 * The OR object service double.
	 *
	 * @var ORObjectService|MockObject
	 */
	private $objectService;

	/**
	 * The identities the double holds, by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $identities = [];

	/**
	 * Set up a double that holds identities.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->identities = [];
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id): ObjectEntity {
				if (isset($this->identities[$id]) === false) {
					throw new DoesNotExistException('no such identity');
				}

				return ObjectServiceMockBuilder::objectEntity($this, $this->identities[$id], $id);
			}
		);
		$this->objectService->method('findAll')->willReturnCallback(
			function (): array {
				$rows = [];
				foreach ($this->identities as $uuid => $identity) {
					$rows[] = ObjectServiceMockBuilder::objectEntity($this, $identity, $uuid);
				}

				return ['results' => $rows, 'total' => count($rows)];
			}
		);

	}//end setUp()

	/**
	 * Two teams send under their own address, signature and all.
	 *
	 * @return void
	 */
	public function testTwoTeamsSendUnderTheirOwnAddress(): void {
		$this->seedIdentities();
		$service = $this->service();

		$belastingen = $service->resolve('belastingen');
		$envelope = $service->envelopeFor($belastingen['identity']);

		$this->assertFalse($belastingen['fallback']);
		$this->assertSame('Belastingen <belastingen@gemeente.nl>', $envelope['from']);
		$this->assertSame('Team Belastingen', $envelope['signature']);
		$this->assertSame('mail-account-1', $envelope['account']);

	}//end testTwoTeamsSendUnderTheirOwnAddress()

	/**
	 * A message naming no identity falls back to the instance default, and
	 * the fallback is reported so the log row can record it.
	 *
	 * @return void
	 */
	public function testAMessageWithoutAnIdentityFallsBack(): void {
		$this->seedIdentities();
		$service = $this->service();

		$resolved = $service->resolve(null);

		$this->assertTrue($resolved['fallback']);
		$this->assertSame('vergunningen', $resolved['id']);

	}//end testAMessageWithoutAnIdentityFallsBack()

	/**
	 * With no default at all, a message naming no identity is refused rather
	 * than leaving under whatever address happens to be first.
	 *
	 * @return void
	 */
	public function testWithoutADefaultAMessageIsRefused(): void {
		$this->identities = [];
		$service = $this->service();

		$this->expectException(RuntimeException::class);
		$service->resolve(null);

	}//end testWithoutADefaultAMessageIsRefused()

	/**
	 * A missing DKIM record is reported absent, with the record to publish.
	 *
	 * @return void
	 */
	public function testAMissingDkimRecordSaysWhatToPublish(): void {
		$checker = new DomainAlignmentChecker(
			new FixtureDnsResolver(
				[
					'gemeente.nl' => ['v=spf1 include:mail.gemeente.nl -all'],
					'_dmarc.gemeente.nl' => ['v=DMARC1; p=quarantine; rua=mailto:dmarc@gemeente.nl'],
				]
			)
		);

		$alignment = $checker->check(
			['address' => 'belastingen@gemeente.nl', 'sendingHost' => 'mail.gemeente.nl']
		);

		$this->assertSame(DomainAlignmentChecker::ABSENT, $alignment['dkim']['state']);
		$this->assertStringContainsString('default._domainkey.gemeente.nl', $alignment['dkim']['publish']);
		$this->assertStringContainsString('v=DKIM1', $alignment['dkim']['publish']);
		$this->assertSame(DomainAlignmentChecker::ALIGNED, $alignment['spf']['state']);
		$this->assertSame(DomainAlignmentChecker::ALIGNED, $alignment['dmarc']['state']);
		$this->assertTrue($checker->isAtRisk($alignment));

	}//end testAMissingDkimRecordSaysWhatToPublish()

	/**
	 * A DMARC record asking the receiver to do nothing is not alignment, so
	 * the screen does not show a green tick for a record with no effect.
	 *
	 * @return void
	 */
	public function testADmarcPolicyOfNoneIsMisaligned(): void {
		$checker = new DomainAlignmentChecker(
			new FixtureDnsResolver(['_dmarc.gemeente.nl' => ['v=DMARC1; p=none']])
		);

		$alignment = $checker->check(['address' => 'post@gemeente.nl']);

		$this->assertSame(DomainAlignmentChecker::MISALIGNED, $alignment['dmarc']['state']);

	}//end testADmarcPolicyOfNoneIsMisaligned()

	/**
	 * An SPF record that does not cover the sending host is misaligned, and
	 * says what to publish instead.
	 *
	 * @return void
	 */
	public function testAnSpfRecordMissingTheSendingHostIsMisaligned(): void {
		$checker = new DomainAlignmentChecker(
			new FixtureDnsResolver(['gemeente.nl' => ['v=spf1 include:someone-else.example -all']])
		);

		$alignment = $checker->check(
			['address' => 'post@gemeente.nl', 'sendingHost' => 'mail.gemeente.nl']
		);

		$this->assertSame(DomainAlignmentChecker::MISALIGNED, $alignment['spf']['state']);
		$this->assertStringContainsString('mail.gemeente.nl', $alignment['spf']['publish']);

	}//end testAnSpfRecordMissingTheSendingHostIsMisaligned()

	/**
	 * A reply to a bezwaarmaker quotes nothing when the identity says so.
	 *
	 * @return void
	 */
	public function testQuotingNoneQuotesNothing(): void {
		$composed = $this->composer()->compose(
			['signature' => 'Team Bezwaar', 'quotingLevel' => SenderIdentityService::QUOTING_NONE],
			'Uw bezwaar is ontvangen.',
			$this->history()
		);

		$this->assertSame(SenderIdentityService::QUOTING_NONE, $composed['quotingLevel']);
		$this->assertStringNotContainsString('>', $composed['body']);
		$this->assertStringContainsString('Team Bezwaar', $composed['body']);

	}//end testQuotingNoneQuotesNothing()

	/**
	 * The default level quotes the message being replied to, and no more.
	 *
	 * @return void
	 */
	public function testQuotingLastQuotesOnlyTheLastMessage(): void {
		$composed = $this->composer()->compose(
			['quotingLevel' => SenderIdentityService::QUOTING_LAST],
			'Dank voor uw bericht.',
			$this->history()
		);

		$this->assertStringContainsString('derde bericht', $composed['body']);
		$this->assertStringNotContainsString('eerste bericht', $composed['body']);

	}//end testQuotingLastQuotesOnlyTheLastMessage()

	/**
	 * Full history quotes all of it, which is why it is a choice and not the
	 * default.
	 *
	 * @return void
	 */
	public function testQuotingFullQuotesEverything(): void {
		$composed = $this->composer()->compose(
			['quotingLevel' => SenderIdentityService::QUOTING_FULL],
			'Dank voor uw bericht.',
			$this->history()
		);

		$this->assertStringContainsString('eerste bericht', $composed['body']);
		$this->assertStringContainsString('derde bericht', $composed['body']);

	}//end testQuotingFullQuotesEverything()

	/**
	 * An identity naming a level nothing recognises falls back to the default
	 * rather than quoting everything.
	 *
	 * @return void
	 */
	public function testAnUnknownQuotingLevelFallsBackToTheDefault(): void {
		$composed = $this->composer()->compose(['quotingLevel' => 'alles-behalve'], 'Tekst', $this->history());

		$this->assertSame(SenderIdentityService::QUOTING_LAST, $composed['quotingLevel']);
		$this->assertStringNotContainsString('eerste bericht', $composed['body']);

	}//end testAnUnknownQuotingLevelFallsBackToTheDefault()

	/**
	 * A four line disclaimer is absent from the timeline entry, and the
	 * original is still whole.
	 *
	 * @return void
	 */
	public function testADisclaimerIsStrippedFromTheTimelineOnly(): void {
		$message = "Beste mevrouw,\n\nHierbij mijn reactie.\n\nMet vriendelijke groet,\nJan\n\n"
			. "Disclaimer: dit bericht is vertrouwelijk en uitsluitend bestemd voor de geadresseerde.\n"
			. "Als u dit bericht ten onrechte ontvangt, verzoeken wij u het te vernietigen.";

		$stripped = (new SignatureStripper())->strip($message);

		$this->assertTrue($stripped['stripped']);
		$this->assertStringNotContainsString('Disclaimer', $stripped['text']);
		$this->assertStringContainsString('Hierbij mijn reactie', $stripped['text']);
		$this->assertSame($message, $stripped['original']);

	}//end testADisclaimerIsStrippedFromTheTimelineOnly()

	/**
	 * A message with no signature loses nothing, which is the assertion that
	 * proves the stripper is not simply cutting the tail off every message.
	 *
	 * @return void
	 */
	public function testAMessageWithoutASignatureLosesNothing(): void {
		$message = "Beste mevrouw,\n\nHierbij mijn reactie op uw brief van 3 september.\n\nJan Burger";

		$stripped = (new SignatureStripper())->strip($message);

		$this->assertFalse($stripped['stripped']);
		$this->assertSame($message, $stripped['text']);

	}//end testAMessageWithoutASignatureLosesNothing()

	/**
	 * The RFC 3676 separator ends the message for the timeline.
	 *
	 * @return void
	 */
	public function testAQuotedSignatureBlockIsStripped(): void {
		$message = "Hierbij mijn reactie.\n\n-- \nJan Burger\nAdviseur\n06 12345678";

		$stripped = (new SignatureStripper())->strip($message);

		$this->assertTrue($stripped['stripped']);
		$this->assertStringNotContainsString('06 12345678', $stripped['text']);
		$this->assertStringContainsString('Hierbij mijn reactie', $stripped['text']);

	}//end testAQuotedSignatureBlockIsStripped()

	/**
	 * A composer over an opt-out registry that protects nothing extra.
	 *
	 * @return MessageComposer The composer.
	 */
	private function composer(): MessageComposer {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new MessageComposer(
			new UnsubscribeTokenService(
				$appConfig,
				$this->createMock(ISecureRandom::class),
				new OptOutRegistry($this->objectService, $appConfig),
			)
		);

	}//end composer()

	/**
	 * Three earlier messages on the case.
	 *
	 * @return array<int,array<string,mixed>> The history, newest last.
	 */
	private function history(): array {
		return [
			['at' => '2026-08-01', 'from' => 'Jan', 'text' => 'Dit is het eerste bericht.'],
			['at' => '2026-08-15', 'from' => 'Behandelaar', 'text' => 'Dit is het tweede bericht.'],
			['at' => '2026-09-01', 'from' => 'Jan', 'text' => 'Dit is het derde bericht.'],
		];

	}//end history()

	/**
	 * Two identities, one of them the instance default.
	 *
	 * @return void
	 */
	private function seedIdentities(): void {
		$this->identities = [
			'vergunningen' => [
				'displayName' => 'Vergunningen',
				'address' => 'vergunningen@gemeente.nl',
				'signature' => 'Team Vergunningen',
				'mailAccount' => 'mail-account-1',
				'isDefault' => true,
			],
			'belastingen' => [
				'displayName' => 'Belastingen',
				'address' => 'belastingen@gemeente.nl',
				'signature' => 'Team Belastingen',
				'mailAccount' => 'mail-account-1',
				'isDefault' => false,
			],
		];

	}//end seedIdentities()


	/**
	 * The service under test, with the collaborators the signing path needs.
	 *
	 * @return SenderIdentityService The service.
	 */
	private function service(): SenderIdentityService {
		return new SenderIdentityService(
			objectService: $this->objectService,
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * A brokered reference yields key material, so signing keeps working once the
	 * inline field is write-only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	public function testABrokeredReferenceYieldsKeyMaterial(): void {
		$broker = new class {
			/**
			 * Resolve a credential.
			 *
			 * @param string $credentialId The credential.
			 * @param string $appId The app.
			 * @param string|null $userId The acting user.
			 * @param string|null $organisationId The acting organisation.
			 *
			 * @return string The secret.
			 */
			public function resolveInjectable($credentialId, $appId, $userId = null, $organisationId = null) {
				return 'RESOLVED_KEY_MATERIAL';
			}
		};

		$service = $this->brokeredService(broker: $broker, storedKey: '');

		$material = $service->signingMaterial(
			identity: ['smimePrivateKeyRef' => 'cred-1'],
			identityId: 'identity-1'
		);

		$this->assertSame(SenderIdentityService::SIGNING_KEY_AVAILABLE, $material['state']);
		$this->assertSame('RESOLVED_KEY_MATERIAL', $material['key']);

	}//end testABrokeredReferenceYieldsKeyMaterial()

	/**
	 * A reference the broker refuses reports UNRESOLVABLE, never ABSENT.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	public function testARefusedReferenceIsUnresolvableNotAbsent(): void {
		$broker = new class {
			/**
			 * Refuse to resolve.
			 *
			 * @param string $credentialId The credential.
			 * @param string $appId The app.
			 * @param string|null $userId The acting user.
			 * @param string|null $organisationId The acting organisation.
			 *
			 * @return string Never returns.
			 *
			 * @throws RuntimeException Always.
			 */
			public function resolveInjectable($credentialId, $appId, $userId = null, $organisationId = null) {
				throw new RuntimeException('refused');
			}
		};

		$service = $this->brokeredService(broker: $broker, storedKey: '');

		$material = $service->signingMaterial(
			identity: ['smimePrivateKeyRef' => 'cred-1'],
			identityId: 'identity-1'
		);

		$this->assertSame(SenderIdentityService::SIGNING_KEY_UNRESOLVABLE, $material['state']);
		$this->assertNull($material['key']);

	}//end testARefusedReferenceIsUnresolvableNotAbsent()

	/**
	 * An identity whose key is not yet migrated still signs, because the signing
	 * path reads outside RBAC AND unrendered.
	 *
	 * Write-only stripping is NOT gated on `_rbac`: `RenderObject::doWriteOnly`
	 * is computed from the schema alone, so `_rbac: false` does not defeat it and
	 * `find()` renders by default. `_render: false` is the flag that keeps the
	 * value readable here. This docblock asserted the opposite until
	 * integriq#2104 review 5266971176 — the fourth copy of a claim the test
	 * below disproves, and the copy a reader trusts most.
	 *
	 * This is the regression that would otherwise ship silently: mail keeps
	 * sending, just unsigned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	public function testAnUnmigratedIdentityStillYieldsItsInlineKey(): void {
		$service = $this->brokeredService(broker: null, storedKey: 'INLINE_KEY_MATERIAL');

		$material = $service->signingMaterial(identity: [], identityId: 'identity-1');

		$this->assertSame(SenderIdentityService::SIGNING_KEY_AVAILABLE, $material['state']);
		$this->assertSame('INLINE_KEY_MATERIAL', $material['key']);

	}//end testAnUnmigratedIdentityStillYieldsItsInlineKey()

	/**
	 * An identity with neither a reference nor an inline key reports ABSENT.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	public function testAnIdentityWithNoKeyAtAllReportsAbsent(): void {
		$service = $this->brokeredService(broker: null, storedKey: '');

		$material = $service->signingMaterial(identity: [], identityId: 'identity-1');

		$this->assertSame(SenderIdentityService::SIGNING_KEY_ABSENT, $material['state']);
		$this->assertNull($material['key']);

	}//end testAnIdentityWithNoKeyAtAllReportsAbsent()

	/**
	 * Build the service with a broker double and a stored identity.
	 *
	 * @param object|null $broker The broker double, or null for "unavailable".
	 * @param string $storedKey The inline key the system-context read finds.
	 *
	 * @return BrokeredSenderIdentityService The service.
	 */
	private function brokeredService(?object $broker, string $storedKey): BrokeredSenderIdentityService {
		// The render-boundary double, NOT a plain mock. A `createMock(...)` whose
		// `find()` ignores its arguments returns the inline key whatever read
		// context the production code used, so the test passes even when
		// `_render: false` is missing — which is how integriq#2104 review
		// 5264751700 blocker 1 shipped green. This double strips `writeOnly`
		// fields on any RENDERED read, so forgetting either flag fails here the
		// way it fails in production.
		$objectService = new RenderBoundarySimulatingObjectService();
		$objectService->stored['identity-1'] = ['smimePrivateKey' => $storedKey];

		$service = new BrokeredSenderIdentityService(
			objectService: $objectService,
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);
		$service->brokerDouble = $broker;

		return $service;

	}//end brokeredService()
}//end class
