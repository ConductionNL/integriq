<?php

/**
 * Integriq — the Berichtenbox binding: subscription first, schema second, results decide.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\DigitalPost
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

namespace OCA\Integriq\Tests\Unit\Service\DigitalPost;

use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxBatch;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClient;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientHttp;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientMock;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxException;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxValidatieClient;
use OCA\Integriq\Adapters\Berichtenbox\EbmsAdapterClient;
use OCA\Integriq\Service\DigitalPost\BerichtenboxProvider;
use OCA\Integriq\Service\DigitalPost\DigitalPostResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A client that records what the binding asked and answers what the test set.
 */
class RecordingBerichtenboxClient extends BerichtenboxClient {
	/** @var array<int,array{0:string,1:mixed}> */
	public array $calls = [];

	/** @var array<string,bool> */
	public array $subscriptions = [];

	public ?BerichtenboxException $subscriptionFault = null;

	/** @var array<string,array{code:string,stadium:string,resultMessageId:string}> */
	public array $results = [];

	/** @var array<string,string> */
	public array $events = [];

	/**
	 * @inheritDoc
	 */
	public function flavour(): string {
		return 'https';
	}

	/**
	 * @inheritDoc
	 */
	public function configurationRefusals(array $config): array {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function checkSubscriptions(array $bsns, string $berichtType, array $config): array {
		$this->calls[] = ['checkSubscriptions', [$bsns, $berichtType]];
		if ($this->subscriptionFault !== null) {
			throw $this->subscriptionFault;
		}

		return array_intersect_key($this->subscriptions, array_flip($bsns));
	}

	/**
	 * @inheritDoc
	 */
	public function deliver(BerichtenboxBatch $batch, array $config): string {
		$this->calls[] = ['deliver', $batch];
		return EbmsAdapterClient::messageIdFor($batch->berichtId);
	}

	/**
	 * @inheritDoc
	 */
	public function results(array $config): array {
		return $this->results;
	}

	/**
	 * @inheritDoc
	 */
	public function transportEvents(array $config): array {
		return $this->events;
	}

	/**
	 * @inheritDoc
	 */
	public function resultProcessed(string $resultMessageId, array $config): void {
		$this->calls[] = ['resultProcessed', $resultMessageId];
	}

	/**
	 * @inheritDoc
	 */
	public function eventProcessed(string $transportMessageId, array $config): void {
		$this->calls[] = ['eventProcessed', $transportMessageId];
	}

	/**
	 * The names of the calls made, in order.
	 *
	 * @return array<int,string>
	 */
	public function callNames(): array {
		return array_column($this->calls, 0);
	}
}//end class

/**
 * REQ-DPA-008 to REQ-DPA-012 and REQ-DPA-014, on the provider seam.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-subscription-is-checked-before-every-send-req-dpa-009
 */
class BerichtenboxProviderTest extends TestCase {
	private const BSN = '999993653';

	/**
	 * A complete live source.
	 *
	 * @return array<string,mixed>
	 */
	private function config(): array {
		return [
			'providerId' => 'berichtenbox',
			'certificateRef' => 'sha256:ab12',
			'senderOin' => '00000001234567890000',
			'adapterUrl' => 'http://ebms:8080/service/rest/v19/ebms',
			'cpaId' => 'cpa-1',
			'toPartyId' => '00000004003214345001',
			'service' => 'urn:osb:services:GLOBE-R',
			'wusEndpoint' => 'https://wus.example/BerichtenboxValidatieService',
			'berichtTypes' => ['besluit' => 'BESLUIT', 'case-update' => 'ZAAK'],
		];
	}//end config()

	/**
	 * A letter as DigitalPostService hands it over.
	 *
	 * @param array<string,mixed> $override Fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private function letter(array $override = []): array {
		return array_merge(
			['recipient' => self::BSN, 'subject' => 'Besluit op uw aanvraag', 'body' => 'Beste heer De Vries,', 'attachments' => [], 'category' => 'besluit', 'caseRef' => 'Z-1'],
			$override
		);
	}//end letter()

	/**
	 * A provider over a recording client.
	 *
	 * @param RecordingBerichtenboxClient $client The client.
	 *
	 * @return BerichtenboxProvider
	 */
	private function provider(RecordingBerichtenboxClient $client): BerichtenboxProvider {
		return new BerichtenboxProvider($client, $this->createMock(LoggerInterface::class));
	}//end provider()

	/**
	 * A subscribed citizen's letter is checked, built and delivered, in that order.
	 *
	 * @return void
	 */
	public function testASubscribedLetterIsCheckedThenDelivered(): void {
		$client = new RecordingBerichtenboxClient();
		$client->subscriptions = [self::BSN => true];

		$result = $this->provider($client)->send($this->letter(), $this->config());

		$this->assertSame(DigitalPostResult::STATUS_SENT, $result->getStatus());
		$this->assertFalse($result->isSimulated());
		$this->assertSame(['checkSubscriptions', 'deliver'], $client->callNames());
		$this->assertSame([[self::BSN], 'BESLUIT'], $client->calls[0][1]);
		$stored = $result->toArray();
		$this->assertSame($client->calls[1][1]->berichtId, $stored['providerReference']);
		$this->assertSame($client->calls[1][1]->batchId, $stored['batchId']);
		$this->assertSame(EbmsAdapterClient::messageIdFor($stored['providerReference']), $stored['transportMessageId']);
	}//end testASubscribedLetterIsCheckedThenDelivered()

	/**
	 * A citizen who is not subscribed is refused before anything is delivered.
	 *
	 * @return void
	 */
	public function testANotSubscribedCitizenIsRefusedBeforeSending(): void {
		$client = new RecordingBerichtenboxClient();
		$client->subscriptions = [self::BSN => false];

		$result = $this->provider($client)->send($this->letter(), $this->config());

		$this->assertTrue($result->isRefused());
		$this->assertSame(BerichtenboxProvider::CODE_NOT_SUBSCRIBED, $result->getCode());
		$this->assertNotContains('deliver', $client->callNames());
	}//end testANotSubscribedCitizenIsRefusedBeforeSending()

	/**
	 * A WUS fault is a refusal with the fault text, and nothing is delivered.
	 *
	 * @return void
	 */
	public function testAWusFaultIsARefusalNotASend(): void {
		$client = new RecordingBerichtenboxClient();
		$client->subscriptionFault = new BerichtenboxException('Logius refused the subscription check: ApplicationFault LeverancierNietGeautoriseerd', BerichtenboxException::CODE_SUBSCRIPTION_FAULT);

		$result = $this->provider($client)->send($this->letter(), $this->config());

		$this->assertSame(BerichtenboxException::CODE_SUBSCRIPTION_FAULT, $result->getCode());
		$this->assertStringContainsString('LeverancierNietGeautoriseerd', $result->getError());
		$this->assertNotContains('deliver', $client->callNames());
	}//end testAWusFaultIsARefusalNotASend()

	/**
	 * A subject over 50 characters is refused, not cut, and nothing is delivered.
	 *
	 * @return void
	 */
	public function testASubjectThatIsTooLongIsRefusedAndNothingIsDelivered(): void {
		$client = new RecordingBerichtenboxClient();
		$client->subscriptions = [self::BSN => true];

		$result = $this->provider($client)->send($this->letter(['subject' => str_repeat('a', 51)]), $this->config());

		$this->assertSame(BerichtenboxException::CODE_INVALID_LETTER, $result->getCode());
		$this->assertStringContainsString('Onderwerp', $result->getError());
		$this->assertNotContains('deliver', $client->callNames());
	}//end testASubjectThatIsTooLongIsRefusedAndNothingIsDelivered()

	/**
	 * A category with no BerichtType is refused, naming the category.
	 *
	 * @return void
	 */
	public function testACategoryWithNoBerichtTypeIsRefused(): void {
		$client = new RecordingBerichtenboxClient();

		$result = $this->provider($client)->send($this->letter(['category' => 'statutory']), $this->config());

		$this->assertTrue($result->isRefused());
		$this->assertStringContainsString('"statutory"', $result->getError());
		$this->assertSame([], $client->callNames());
	}//end testACategoryWithNoBerichtTypeIsRefused()

	/**
	 * A flagged instance with an incomplete source refuses, naming each missing value, and simulates nothing.
	 *
	 * @return void
	 */
	public function testAFlaggedSourceWithoutAnAdapterRefusesAndSaysWhy(): void {
		$live = new BerichtenboxClientHttp(
			$this->createMock(BerichtenboxValidatieClient::class),
			$this->createMock(EbmsAdapterClient::class),
			$this->createMock(LoggerInterface::class)
		);
		$config = $this->config();
		unset($config['adapterUrl'], $config['cpaId']);

		$result = (new BerichtenboxProvider($live, $this->createMock(LoggerInterface::class)))->send($this->letter(), $config);

		$this->assertTrue($result->isRefused());
		$this->assertFalse($result->isSimulated());
		$this->assertSame(BerichtenboxException::CODE_NOT_CONFIGURED, $result->getCode());
		$this->assertStringContainsString('ebMS adapter (adapterUrl)', $result->getError());
		$this->assertStringContainsString('(cpaId)', $result->getError());
		$this->assertStringContainsString('Nothing was sent', $result->getError());
	}//end testAFlaggedSourceWithoutAnAdapterRefusesAndSaysWhy()

	/**
	 * A source without a certificate cannot activate, and the refusal says where to upload it.
	 *
	 * @return void
	 */
	public function testASourceWithoutACertificateCannotActivate(): void {
		$config = $this->config();
		unset($config['certificateRef']);

		$refusals = $this->provider(new RecordingBerichtenboxClient())->activationRefusals($config);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('PKIoverheid certificate', $refusals[0]);
	}//end testASourceWithoutACertificateCannotActivate()

	/**
	 * The mock still sends nothing and says so, even with no BerichtType mapped.
	 *
	 * @return void
	 */
	public function testAMockBackedSendSaysSimulated(): void {
		$result = (new BerichtenboxProvider(new BerichtenboxClientMock(), $this->createMock(LoggerInterface::class)))
			->send($this->letter(), ['certificateRef' => 'x', 'senderOin' => '00000001234567890000']);

		$this->assertSame(DigitalPostResult::STATUS_SENT, $result->getStatus());
		$this->assertTrue($result->isSimulated());
	}//end testAMockBackedSendSaysSimulated()

	/**
	 * A result `Verwerkt` makes the letter delivered; the adapter is told only once the status is stored.
	 *
	 * @return void
	 */
	public function testAProcessedLetterBecomesDeliveredAndIsAcknowledgedAfterStoring(): void {
		$client = new RecordingBerichtenboxClient();
		$client->results = ['b1' => ['code' => 'Verwerkt', 'stadium' => 'NA', 'resultMessageId' => 'r1@logius']];
		$provider = $this->provider($client);

		$result = $provider->status('B1', $this->config());

		$this->assertSame(DigitalPostResult::STATUS_DELIVERED, $result->getStatus());
		$this->assertSame([], $client->callNames(), 'Nothing is acknowledged before the status is stored.');

		$provider->statusRecorded('B1', $this->config());
		$this->assertSame([['resultProcessed', 'r1@logius']], $client->calls);
	}//end testAProcessedLetterBecomesDeliveredAndIsAcknowledgedAfterStoring()

	/**
	 * A letter whose result arrived lets go of its transport event too, so nothing waits at the adapter (bbx-live).
	 *
	 * @return void
	 */
	public function testAResultAlsoReleasesTheTransportEvent(): void {
		$client = new RecordingBerichtenboxClient();
		$client->results = ['b1' => ['code' => 'Verwerkt', 'stadium' => 'NA', 'resultMessageId' => 'r1']];
		$client->events = [EbmsAdapterClient::messageIdFor('b1') => 'DELIVERED'];
		$provider = $this->provider($client);

		$provider->status('b1', $this->config());
		$provider->statusRecorded('b1', $this->config());

		$this->assertSame([['resultProcessed', 'r1'], ['eventProcessed', EbmsAdapterClient::messageIdFor('b1')]], $client->calls);
	}//end testAResultAlsoReleasesTheTransportEvent()

	/**
	 * Any other code makes the letter failed, with the code and stage, keeping its reference.
	 *
	 * @return void
	 */
	public function testARefusedLetterBecomesFailedWithTheLogiusCode(): void {
		$client = new RecordingBerichtenboxClient();
		$client->results = ['b1' => ['code' => 'NietActiefOfGeabonneerd', 'stadium' => 'ValidatieGebruiker', 'resultMessageId' => 'r1']];

		$result = $this->provider($client)->status('b1', $this->config());

		$this->assertSame(DigitalPostResult::STATUS_FAILED, $result->getStatus());
		$this->assertStringContainsString('NietActiefOfGeabonneerd', $result->getError());
		$this->assertStringContainsString('ValidatieGebruiker', $result->getError());
		$this->assertSame('b1', $result->getProviderReference());
	}//end testARefusedLetterBecomesFailedWithTheLogiusCode()

	/**
	 * An expired transport event makes the letter failed.
	 *
	 * @return void
	 */
	public function testAnExpiredTransportEventBecomesFailed(): void {
		$client = new RecordingBerichtenboxClient();
		$client->events = [EbmsAdapterClient::messageIdFor('b1') => 'EXPIRED'];
		$provider = $this->provider($client);

		$result = $provider->status('b1', $this->config());

		$this->assertSame('transport_expired', $result->getCode());
		$provider->statusRecorded('b1', $this->config());
		$this->assertSame([['eventProcessed', EbmsAdapterClient::messageIdFor('b1')]], $client->calls);
	}//end testAnExpiredTransportEventBecomesFailed()

	/**
	 * Logius acknowledging the batch leaves the letter sent.
	 *
	 * @return void
	 */
	public function testATransportDeliveredEventLeavesTheLetterSent(): void {
		$client = new RecordingBerichtenboxClient();
		$client->events = [EbmsAdapterClient::messageIdFor('b1') => 'DELIVERED'];

		$result = $this->provider($client)->status('b1', $this->config());

		$this->assertSame(DigitalPostResult::STATUS_SENT, $result->getStatus());
		$this->assertSame(['eventProcessed'], $client->callNames());
	}//end testATransportDeliveredEventLeavesTheLetterSent()

	/**
	 * No result code, from the full Logius list, ever makes a letter read.
	 *
	 * @return void
	 */
	public function testNoResultCodeEverMakesALetterRead(): void {
		$codes = ['Verwerkt', 'TechnischProbleem', 'NietActiefOfGeabonneerd', 'BerichtTypeNietOndersteund', 'PublicatieDatumLigtTeVerInDeToekomst',
			'AanmaakDatumLigtTeVerInHetVerleden', 'BerichtBestaatAl', 'BijlageTeGroot', 'OinInCPAKomtNietOvereenMetOinInBericht', 'XmlValidatieTegenXsdValtNegatiefUit'];
		foreach ($codes as $code) {
			$client = new RecordingBerichtenboxClient();
			$client->results = ['b1' => ['code' => $code, 'stadium' => 'NA', 'resultMessageId' => 'r']];
			$status = $this->provider($client)->status('b1', $this->config())->getStatus();

			$this->assertNotSame(DigitalPostResult::STATUS_READ, $status, $code);
			$this->assertSame(($code === 'Verwerkt' ? DigitalPostResult::STATUS_DELIVERED : DigitalPostResult::STATUS_FAILED), $status, $code);
		}
	}//end testNoResultCodeEverMakesALetterRead()

	/**
	 * With nothing back yet, the letter stays sent.
	 *
	 * @return void
	 */
	public function testNoResultYetLeavesTheLetterSent(): void {
		$this->assertSame(DigitalPostResult::STATUS_SENT, $this->provider(new RecordingBerichtenboxClient())->status('b1', $this->config())->getStatus());
	}//end testNoResultYetLeavesTheLetterSent()

	/**
	 * A live source has no inbound post, whatever its configuration says.
	 *
	 * @return void
	 */
	public function testALiveSourceHasNoInboundPost(): void {
		$config = $this->config() + ['inboundFixture' => [['sender' => 'x', 'subject' => 'y']]];

		$this->assertSame([], $this->provider(new RecordingBerichtenboxClient())->pollInbound($config));
		$this->assertCount(1, (new BerichtenboxProvider(new BerichtenboxClientMock(), $this->createMock(LoggerInterface::class)))->pollInbound($config));
	}//end testALiveSourceHasNoInboundPost()
}//end class
