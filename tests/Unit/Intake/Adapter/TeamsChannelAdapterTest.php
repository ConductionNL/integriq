<?php

/**
 * Unit tests for TeamsChannelAdapter.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Intake\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Intake\Adapter;

use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Intake\Adapter\TeamsChannelAdapter;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelSourceResolver;
use OCA\Integriq\Intake\ReplyResult;
use OCA\Integriq\Service\Mail\CaseReferenceDetector;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the Teams intake channel adapter.
 *
 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
 */
class TeamsChannelAdapterTest extends TestCase {

	/**
	 * Build an adapter over a given source configuration and HTTP client.
	 *
	 * The reference detector is the real one: a double that answers whatever
	 * the test wants would make the detection assertion unfalsifiable.
	 *
	 * @param array<string,mixed>|null $configuration The source configuration, or null for none.
	 * @param IClient|null $client The HTTP client, or null for an unused one.
	 *
	 * @return TeamsChannelAdapter The adapter.
	 */
	private function adapter(?array $configuration = null, ?IClient $client = null): TeamsChannelAdapter {
		$resolver = $this->createMock(IntakeChannelSourceResolver::class);
		$resolver->method('configurationFor')->willReturn($configuration);

		$clientService = $this->createMock(IClientService::class);
		if ($client === null) {
			$client = $this->createMock(IClient::class);
		}

		$clientService->method('newClient')->willReturn($client);

		return new TeamsChannelAdapter(
			sourceResolver: $resolver,
			clientService: $clientService,
			referenceDetector: new CaseReferenceDetector()
		);

	}//end adapter()

	/**
	 * One Teams activity, with the text the caller wants.
	 *
	 * @param string $text The message text.
	 *
	 * @return array<string,mixed> The activity.
	 */
	private function activity(string $text): array {
		return [
			'type' => 'message',
			'id' => '1485983408511',
			'timestamp' => '2026-09-22T10:11:12.437Z',
			'serviceUrl' => 'https://smba.trafficmanager.net/emea/',
			'channelId' => 'msteams',
			'from' => ['id' => '29:author', 'name' => 'Jamila Bakker', 'aadObjectId' => 'aad-1'],
			'conversation' => ['id' => '19:thread@thread.tacv2', 'conversationType' => 'channel'],
			'text' => $text,
			'channelData' => ['tenant' => ['id' => 'tenant-1'], 'teamsTeamId' => '19:team'],
		];

	}//end activity()

	/**
	 * A message naming a case number is normalised and its reference detected.
	 *
	 * @return void
	 */
	public function testAMessageNamingACaseNumberCarriesTheReference(): void {
		$message = $this->adapter()->receive(
			$this->activity('<at>Zaakbot</at> graag een update op ZAAK-2026-0042 &amp; dank')
		);

		$this->assertSame('teams', $message->getChannelId());
		$this->assertSame('1485983408511', $message->getExternalId());
		$this->assertSame('Zaakbot graag een update op ZAAK-2026-0042 & dank', $message->getText());
		$this->assertSame('ZAAK-2026-0042', $message->getFields()['caseReference']);
		$this->assertSame('29:author', $message->getCorrespondent()['id']);
		$this->assertSame('Jamila Bakker', $message->getCorrespondent()['name']);
		$this->assertSame('19:thread@thread.tacv2', $message->getFields()['conversationId']);
		$this->assertSame('tenant-1', $message->getFields()['tenantId']);

	}//end testAMessageNamingACaseNumberCarriesTheReference()

	/**
	 * A message naming no case number carries an empty reference rather than
	 * an invented one, and keeps its raw payload whole.
	 *
	 * @return void
	 */
	public function testAMessageNamingNothingCarriesNoReference(): void {
		$activity = $this->activity('kan iemand hier even naar kijken?');
		$message = $this->adapter()->receive($activity);

		$this->assertSame('', $message->getFields()['caseReference']);
		$this->assertSame('kan iemand hier even naar kijken?', $message->getText());
		$this->assertSame($activity, $message->getRawPayload());

	}//end testAMessageNamingNothingCarriesNoReference()

	/**
	 * A configured `casePattern` is the one the detector reads.
	 *
	 * @return void
	 */
	public function testAConfiguredPatternIsTheOneRead(): void {
		$adapter = $this->adapter(configuration: ['casePattern' => '/\b(D-\d{6})\b/']);
		$message = $adapter->receive($this->activity('zie D-123456 en ZAAK-2026-0042'));

		$this->assertSame('D-123456', $message->getFields()['caseReference']);

	}//end testAConfiguredPatternIsTheOneRead()

	/**
	 * An activity with no conversation is refused rather than normalised into
	 * a message nothing can answer.
	 *
	 * @return void
	 */
	public function testAnActivityWithoutAConversationIsRefused(): void {
		$activity = $this->activity('hoi');
		unset($activity['conversation']);

		$this->expectException(IntakeChannelException::class);
		$this->adapter()->receive($activity);

	}//end testAnActivityWithoutAConversationIsRefused()

	/**
	 * A file upload travels as its download URL; a card is not a file.
	 *
	 * @return void
	 */
	public function testFileAttachmentsTravelAsTheirDownloadUrl(): void {
		$activity = $this->activity('met bijlage');
		$activity['attachments'] = [
			[
				'contentType' => 'application/vnd.microsoft.card.adaptive',
				'content' => ['type' => 'AdaptiveCard'],
			],
			[
				'contentType' => 'application/vnd.microsoft.teams.file.download.info',
				'name' => 'bezwaar.pdf',
				'content' => ['downloadUrl' => 'https://tenant.sharepoint.com/pre-auth'],
			],
		];

		$attachments = $this->adapter()->receive($activity)->getAttachments();

		$this->assertCount(1, $attachments);
		$this->assertSame('bezwaar.pdf', $attachments[0]['name']);
		$this->assertSame('https://tenant.sharepoint.com/pre-auth', $attachments[0]['downloadUrl']);

	}//end testFileAttachmentsTravelAsTheirDownloadUrl()

	/**
	 * The channel says it can reply, because it can.
	 *
	 * @return void
	 */
	public function testTheChannelSaysItCanReply(): void {
		$described = $this->adapter()->describe();

		$this->assertSame('teams', $described->getChannelId());
		$this->assertTrue($described->canReply());

	}//end testTheChannelSaysItCanReply()

	/**
	 * In mock mode the reply is recorded and nothing leaves.
	 *
	 * @return void
	 */
	public function testMockModeRecordsTheReplyAndSendsNothing(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('post');

		$adapter = $this->adapter(configuration: ['mock' => true], client: $client);
		$result = $adapter->reply($adapter->receive($this->activity('hoi')), 'ZAAK-2026-0099 is aangemaakt.');

		$this->assertSame(ReplyResult::STATUS_SENT, $result->getStatus());
		$this->assertStringStartsWith('MOCK-REPLY-', (string)$result->getReference());

	}//end testMockModeRecordsTheReplyAndSendsNothing()

	/**
	 * A reply posts into the conversation the message came from.
	 *
	 * @return void
	 */
	public function testTheReplyGoesIntoTheSameConversation(): void {
		$seen = [];
		$response = $this->createMock(\OCP\Http\Client\IResponse::class);
		$response->method('getBody')->willReturn('{"id":"1485983408600"}');

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$seen, $response) {
				$seen = ['url' => $url, 'options' => $options];
				return $response;
			}
		);

		$adapter = $this->adapter(
			configuration: ['accessToken' => 'connector-token'],
			client: $client
		);
		$result = $adapter->reply($adapter->receive($this->activity('hoi')), 'Aangemaakt.');

		$this->assertSame(ReplyResult::STATUS_SENT, $result->getStatus());
		$this->assertSame('1485983408600', $result->getReference());
		$this->assertSame(
			'https://smba.trafficmanager.net/emea/v3/conversations/19%3Athread%40thread.tacv2/activities',
			$seen['url']
		);
		$this->assertSame('Aangemaakt.', $seen['options']['json']['text']);
		$this->assertSame('1485983408511', $seen['options']['json']['replyToId']);
		$this->assertSame('Bearer connector-token', $seen['options']['headers']['Authorization']);

	}//end testTheReplyGoesIntoTheSameConversation()

	/**
	 * A gateway that refuses the post is reported as a refusal, never as a
	 * send.
	 *
	 * @return void
	 */
	public function testARefusedReplyIsReportedAsARefusal(): void {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new RuntimeException('403 Forbidden'));

		$adapter = $this->adapter(configuration: ['accessToken' => 'connector-token'], client: $client);
		$result = $adapter->reply($adapter->receive($this->activity('hoi')), 'Aangemaakt.');

		$this->assertSame(ReplyResult::STATUS_FAILED, $result->getStatus());
		$this->assertStringContainsString('403 Forbidden', (string)$result->getDetail());

	}//end testARefusedReplyIsReportedAsARefusal()

	/**
	 * With no source configured there is no connector, and the adapter says so
	 * rather than answering over some other route.
	 *
	 * @return void
	 */
	public function testNoConfiguredSourceMeansNoReply(): void {
		$adapter = $this->adapter();
		$result = $adapter->reply($adapter->receive($this->activity('hoi')), 'Aangemaakt.');

		$this->assertSame(ReplyResult::STATUS_FAILED, $result->getStatus());
		$this->assertStringContainsString('No Teams source is configured', (string)$result->getDetail());

	}//end testNoConfiguredSourceMeansNoReply()

	/**
	 * A message rebuilt from its stored object still answers into its
	 * conversation, because the conversation id travels in `fields`.
	 *
	 * @return void
	 */
	public function testAStoredMessageStillNamesItsConversation(): void {
		$adapter = $this->adapter(configuration: ['mock' => true]);
		$stored = InboundMessage::fromObject($adapter->receive($this->activity('hoi'))->toObject());

		$this->assertSame('19:thread@thread.tacv2', $stored->getFields()['conversationId']);
		$this->assertSame(ReplyResult::STATUS_SENT, $adapter->reply($stored, 'Aangemaakt.')->getStatus());

	}//end testAStoredMessageStillNamesItsConversation()

	/**
	 * A reply is never sent to a serviceUrl outside the trusted connector hosts.
	 *
	 * The reply carries `Authorization: Bearer <connector access token>`, and
	 * `serviceUrl` arrives on the activity, which is attacker-influenceable.
	 * Without a list this method posts the bot's credential wherever the payload
	 * says (integriq#1983 security re-review).
	 *
	 * Asserting the client is NEVER asked for a request, not merely that the
	 * result failed, so the refusal is proven to happen before the egress.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function testAReplyIsNotSentToAnUntrustedServiceUrl(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('post');

		$activity = $this->activity('hoi');
		$activity['serviceUrl'] = 'https://attacker.example/';

		$adapter = $this->adapter(['accessToken' => 'connector-token'], $client);
		$result = $adapter->reply($adapter->receive($activity), 'ZAAK-2026-0099 is aangemaakt.');

		$this->assertFalse($result->isSent());

	}//end testAReplyIsNotSentToAnUntrustedServiceUrl()

	/**
	 * A host that merely CONTAINS a trusted name is not a trusted host.
	 *
	 * The check is a suffix match on the parsed host, so
	 * `https://evil.example/smba.trafficmanager.net` and
	 * `https://smba.trafficmanager.net.evil.example/` both fail. A substring
	 * match on the URL would admit both.
	 *
	 * @param string $serviceUrl The candidate destination.
	 *
	 * @return void
	 *
	 * @dataProvider untrustedServiceUrlProvider
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function testALookalikeHostIsNotTrusted(string $serviceUrl): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('post');

		$activity = $this->activity('hoi');
		$activity['serviceUrl'] = $serviceUrl;

		$adapter = $this->adapter(['accessToken' => 'connector-token'], $client);

		$this->assertFalse($adapter->reply($adapter->receive($activity), 'x')->isSent());

	}//end testALookalikeHostIsNotTrusted()

	/**
	 * Destinations that must never be reached.
	 *
	 * @return array<string, array{string}> The cases.
	 */
	public static function untrustedServiceUrlProvider(): array {
		return [
			'trusted name in the path' => ['https://evil.example/smba.trafficmanager.net'],
			'trusted name as a prefix of the host' => ['https://smba.trafficmanager.net.evil.example/'],
			'plain http to a trusted host' => ['http://smba.trafficmanager.net/emea/'],
			'loopback' => ['https://127.0.0.1/'],
			'link-local metadata' => ['https://169.254.169.254/'],
			'not a url at all' => ['not-a-url'],
			// parse_url() itself returns false for these two, rather than an
			// array with a missing host, so they take a different branch than
			// the cases above and are not a duplicate of them.
			'unparseable, no authority' => ['https:///emea/'],
			'a scheme and nothing else' => ['https:'],
		];

	}//end untrustedServiceUrlProvider()

	/**
	 * The configured serviceUrl wins over the one on the activity.
	 *
	 * The precedence was the other way round, so a value on the payload
	 * overrode the operator's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function testTheConfiguredServiceUrlWinsOverThePayload(): void {
		$seen = null;
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url) use (&$seen) {
				$seen = $url;
				throw new \RuntimeException('stop after the destination is known');
			}
		);

		$activity = $this->activity('hoi');
		$activity['serviceUrl'] = 'https://smba.trafficmanager.net/attacker-region/';

		$adapter = $this->adapter(
			[
				'accessToken' => 'connector-token',
				'serviceUrl' => 'https://smba.trafficmanager.net/emea/',
			],
			$client
		);
		$adapter->reply($adapter->receive($activity), 'x');

		$this->assertIsString($seen);
		$this->assertStringStartsWith('https://smba.trafficmanager.net/emea/', $seen);

	}//end testTheConfiguredServiceUrlWinsOverThePayload()

	/**
	 * A configured host list REPLACES the default, it does not extend it.
	 *
	 * An instance on a sovereign or air-gapped Azure cloud talks to a host
	 * Microsoft's public list does not name, so the list has to be configurable
	 * or the allow-list is a Dutch-government-instance outage waiting to happen.
	 *
	 * Replacing rather than extending is the deliberate half: an operator who
	 * narrows the list to their own tenant's host expects the public ones to
	 * STOP being trusted. Both halves are asserted here, because a bug that made
	 * this additive would leave the narrowing silently ineffective.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function testAConfiguredHostListReplacesTheDefaultOne(): void {
		$seen = null;
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url) use (&$seen) {
				$seen = $url;
				throw new \RuntimeException('stop after the destination is known');
			}
		);

		$configuration = [
			'accessToken' => 'connector-token',
			'trustedServiceHosts' => ['teams.sovereign.example'],
		];

		$admitted = $this->activity('hoi');
		$admitted['serviceUrl'] = 'https://eu.teams.sovereign.example/emea/';
		$adapter = $this->adapter($configuration, $client);
		$adapter->reply($adapter->receive($admitted), 'x');
		$this->assertIsString($seen, 'A host on the configured list must be reachable.');

		$seen = null;
		$refused = $this->activity('hoi');
		$refused['serviceUrl'] = 'https://smba.trafficmanager.net/emea/';
		$adapter = $this->adapter($configuration, $client);
		$result = $adapter->reply($adapter->receive($refused), 'x');

		$this->assertNull($seen, 'A configured list REPLACES the defaults; the public host must no longer pass.');
		$this->assertFalse($result->isSent());

	}//end testAConfiguredHostListReplacesTheDefaultOne()

	/**
	 * A host list that is not a usable list falls back to the default.
	 *
	 * `trustedServiceHosts: ""` or `[]` is a half-finished edit, not a statement
	 * that nothing is trusted. Reading it as "trust nobody" would take Teams
	 * replies down on a typo; reading it as "trust anybody" would delete the
	 * control. It falls back to the shipped list, which is neither.
	 *
	 * @param mixed $configured The malformed value.
	 *
	 * @return void
	 *
	 * @dataProvider unusableHostListProvider
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function testAnUnusableHostListFallsBackToTheDefault(mixed $configured): void {
		$seen = null;
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url) use (&$seen) {
				$seen = $url;
				throw new \RuntimeException('stop after the destination is known');
			}
		);

		$configuration = [
			'accessToken' => 'connector-token',
			'trustedServiceHosts' => $configured,
		];

		$activity = $this->activity('hoi');
		$activity['serviceUrl'] = 'https://smba.trafficmanager.net/emea/';
		$adapter = $this->adapter($configuration, $client);
		$adapter->reply($adapter->receive($activity), 'x');
		$this->assertIsString($seen, 'The shipped default must still apply.');

		$seen = null;
		$activity['serviceUrl'] = 'https://attacker.example/';
		$adapter = $this->adapter($configuration, $client);
		$adapter->reply($adapter->receive($activity), 'x');
		$this->assertNull($seen, 'Falling back must not mean trusting everything.');

	}//end testAnUnusableHostListFallsBackToTheDefault()

	/**
	 * A list of nothing but blanks trusts nobody, rather than everybody.
	 *
	 * `['', '']` survives the is-it-a-usable-list check — it is a non-empty
	 * array — so it reaches the match loop, where every entry is skipped. The
	 * loop must then fall through to a refusal. An implementation that treated
	 * an unmatched-because-skipped entry as a pass would turn a whitespace typo
	 * in configuration into an open redirect for the bot's bearer token.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function testAListOfBlankEntriesTrustsNobody(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('post');

		$activity = $this->activity('hoi');
		$activity['serviceUrl'] = 'https://smba.trafficmanager.net/emea/';

		$adapter = $this->adapter(
			[
				'accessToken' => 'connector-token',
				'trustedServiceHosts' => ['', '  '],
			],
			$client
		);

		$this->assertFalse($adapter->reply($adapter->receive($activity), 'x')->isSent());

	}//end testAListOfBlankEntriesTrustsNobody()

	/**
	 * Values that are not a usable host list.
	 *
	 * @return array<string,array<int,mixed>> The cases.
	 */
	public static function unusableHostListProvider(): array {
		return [
			'empty list'  => [[]],
			'empty string' => [''],
			'a bare string' => ['teams.sovereign.example'],
			'null'        => [null],
		];

	}//end unusableHostListProvider()

}//end class
