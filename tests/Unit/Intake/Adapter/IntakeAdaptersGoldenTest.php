<?php

/**
 * Golden master of the intake channel adapters.
 *
 * Every case feeds one adapter a payload and records the InboundMessage it
 * builds (or the refusal it throws), and for the two channels with a reply leg
 * records the ReplyResult and every request sent to the gateway. Set
 * RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Intake\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Intake\Adapter;

use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Intake\Adapter\FormSubmissionAdapter;
use OCA\Integriq\Intake\Adapter\MessagingChannelAdapter;
use OCA\Integriq\Intake\Adapter\PublicSpaceReportAdapter;
use OCA\Integriq\Intake\Adapter\TeamsChannelAdapter;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelSourceResolver;
use OCA\Integriq\Service\Mail\CaseReferenceDetector;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Intake\Adapter\FormSubmissionAdapter
 * @covers \OCA\Integriq\Intake\Adapter\MessagingChannelAdapter
 * @covers \OCA\Integriq\Intake\Adapter\PublicSpaceReportAdapter
 * @covers \OCA\Integriq\Intake\Adapter\TeamsChannelAdapter
 */
class IntakeAdaptersGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/../../../fixtures/golden/intake-adapters.json';

	/**
	 * Every request sent in the current case.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $posted = [];

	/**
	 * A client that records its posts and answers the given body, or throws.
	 *
	 * @param mixed $answer The response body, or 'throw'.
	 *
	 * @return IClientService The client service.
	 */
	private function clients(mixed $answer): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($answer): IResponse {
				$this->posted[] = [$url, $options];
				if ($answer === 'throw') {
					throw new RuntimeException('connection refused');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($answer);
				return $response;
			}
		);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}

	/**
	 * A source resolver that answers the given configuration.
	 *
	 * @param array<string, mixed>|null $configuration The configuration.
	 *
	 * @return IntakeChannelSourceResolver The resolver.
	 */
	private function resolver(?array $configuration): IntakeChannelSourceResolver {
		$resolver = $this->createMock(IntakeChannelSourceResolver::class);
		$resolver->method('configurationFor')->willReturn($configuration);

		return $resolver;
	}

	/**
	 * What receive() answers, as a plain array.
	 *
	 * @param callable $receive The receive call.
	 *
	 * @return array<string, mixed> The message, or the refusal.
	 */
	private static function received(callable $receive): array {
		try {
			$message = $receive();
		} catch (IntakeChannelException $refusal) {
			return ['refused' => $refusal->getMessage()];
		}

		return [
			'object' => $message->toObject(),
			'location' => $message->getLocation(),
			'media' => $message->getMedia(),
			'attachments' => $message->getAttachments(),
		];
	}

	/**
	 * A Teams activity.
	 *
	 * @param array<string, mixed> $overrides Values replacing the defaults.
	 *
	 * @return array<string, mixed> The activity.
	 */
	private static function activity(array $overrides = []): array {
		return array_merge(
			[
				'type' => 'message',
				'id' => '1485983408511',
				'timestamp' => '2026-09-22T10:11:12.437Z',
				'serviceUrl' => 'https://smba.trafficmanager.net/emea/',
				'from' => ['id' => '29:author', 'name' => 'Jamila Bakker', 'aadObjectId' => 'aad-1'],
				'conversation' => ['id' => '19:thread@thread.tacv2', 'conversationType' => 'channel'],
				'text' => '<p>Graag update op <b>ZAAK-2026-0042</b>&nbsp;</p>',
				'channelData' => ['tenant' => ['id' => 'tenant-1'], 'teamsTeamId' => '19:team'],
				'attachments' => [
					['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => []],
					['contentType' => 'application/vnd.microsoft.teams.file.download.info', 'name' => 'scan.pdf', 'content' => ['downloadUrl' => ' https://files.example/scan ']],
					['contentType' => 'image/png', 'contentUrl' => 'https://files.example/img'],
					['contentType' => 'text/plain'],
					'not-an-attachment',
				],
			],
			$overrides
		);
	}

	/**
	 * Every receive case.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function receiveCases(): array {
		$form = new FormSubmissionAdapter();
		$report = new PublicSpaceReportAdapter();
		$messaging = new MessagingChannelAdapter(sourceResolver: $this->resolver(configuration: null), clientService: $this->clients(answer: ''));
		$teams = new TeamsChannelAdapter(sourceResolver: $this->resolver(configuration: ['casePattern' => '  ']), clientService: $this->clients(answer: ''), referenceDetector: new CaseReferenceDetector());
		$teamsPattern = new TeamsChannelAdapter(sourceResolver: $this->resolver(configuration: ['casePattern' => '/\b(D-\d{6})\b/']), clientService: $this->clients(answer: ''), referenceDetector: new CaseReferenceDetector());
		$file = ['name' => 'brief.pdf', 'mime' => 'application/pdf', 'contentBase64' => base64_encode('PDF-bytes')];

		return [
			'form-full' => self::received(fn () => $form->receive(['submissionId' => ' s-1 ', 'formId' => 'f-9', 'data' => ['naam' => 'Piet', 'formId' => 'x'], 'submitter' => ['kvk' => '12345678', 'name' => 'BV', 'email' => 'a@b.nl', 'phone' => '06'], 'summary' => 'Aanvraag', 'submittedAt' => 1700000000, 'attachments' => [$file, ['contentBase64' => base64_encode('x')], 'junk']])),
			'form-bsn-and-defaults' => self::received(fn () => $form->receive(['submissionId' => 's-2', 'formId' => 'f-9', 'data' => 'not-a-map', 'submitter' => ['bsn' => '999993653', 'kvk' => '1']])),
			'form-refused' => self::received(fn () => $form->receive(['submissionId' => 's-3'])),
			'report-full' => self::received(fn () => $report->receive(['id' => 'r-1', 'reporter' => ['name' => 'Jan', 'email' => 'j@x.nl'], 'reportedAt' => 1700000001, 'description' => 'Losse tegel', 'category' => 'straat', 'location' => ['lat' => 52.1, 'lon' => 5.1, 'address' => 'Dorpsstraat 1'], 'photos' => [['contentBase64' => base64_encode('jpg')], ['name' => 'b.png', 'mime' => 'image/png', 'contentBase64' => base64_encode('png')], 7]])),
			'report-bare' => self::received(fn () => $report->receive(['id' => 'r-2', 'reporter' => 'nobody'])),
			'report-refused' => self::received(fn () => $report->receive(['description' => 'x'])),
			'messaging-full' => self::received(fn () => $messaging->receive(['messageId' => 'm-1', 'from' => ['phone' => '+316', 'name' => 'Ali'], 'text' => 'Hoi', 'service' => 'whatsapp', 'timestamp' => 1700000002, 'attachments' => [$file, ['contentBase64' => '']]])),
			'messaging-handle-and-string-from' => self::received(fn () => $messaging->receive(['messageId' => 'm-2', 'from' => '+317'])),
			'messaging-handle-field' => self::received(fn () => $messaging->receive(['messageId' => 'm-3', 'from' => ['handle' => '@ali']])),
			'messaging-refused' => self::received(fn () => $messaging->receive(['messageId' => 'm-4', 'from' => []])),
			'teams-full' => self::received(fn () => $teams->receive(self::activity())),
			'teams-configured-pattern' => self::received(fn () => $teamsPattern->receive(self::activity(['text' => 'zie D-123456 en ZAAK-2026-0042', 'attachments' => 'none', 'timestamp' => null, 'channelData' => 'x', 'conversation' => ['id' => 'c-2']]))),
			'teams-no-text' => self::received(fn () => $teams->receive(self::activity(['text' => '']))),
			'teams-refused' => self::received(fn () => $teams->receive(self::activity(['conversation' => 'none']))),
		];
	}

	/**
	 * Every reply case.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function replyCases(): array {
		$teamsMessage = InboundMessage::fromObject((new TeamsChannelAdapter(sourceResolver: $this->resolver(configuration: null), clientService: $this->clients(answer: ''), referenceDetector: new CaseReferenceDetector()))->receive(self::activity())->toObject());
		$messagingMessage = (new MessagingChannelAdapter(sourceResolver: $this->resolver(configuration: null), clientService: $this->clients(answer: '')))->receive(['messageId' => 'm-1', 'from' => ['phone' => '+316']]);
		$teamsConfig = ['accessToken' => 'tok', 'serviceUrl' => 'https://smba.trafficmanager.net/emea/'];
		$teams = [
			'teams-no-source' => [null, ''],
			'teams-mock' => [['mock' => true], ''],
			'teams-sent' => [$teamsConfig, '{"id":" act-9 "}'],
			'teams-sent-no-reference' => [$teamsConfig, '[]'],
			'teams-gateway-down' => [$teamsConfig, 'throw'],
			'teams-payload-service-url' => [['accessToken' => 'tok'], '{"id":"x"}'],
			'teams-untrusted-http' => [['accessToken' => 'tok', 'serviceUrl' => 'http://smba.trafficmanager.net/'], ''],
			'teams-untrusted-host' => [['accessToken' => 'tok', 'serviceUrl' => 'https://evil.example/'], ''],
			'teams-lookalike-host' => [['accessToken' => 'tok', 'serviceUrl' => 'https://eviltrafficmanager.net/'], ''],
			'teams-lookalike-default-host' => [['accessToken' => 'tok', 'serviceUrl' => 'https://evilbotframework.com/'], ''],
			'teams-subdomain-of-default-host' => [['accessToken' => 'tok', 'serviceUrl' => 'https://Europe.BotFramework.com/x'], '{"id":"e"}'],
			'teams-unparseable' => [['accessToken' => 'tok', 'serviceUrl' => 'https:///'], ''],
			'teams-configured-hosts' => [['accessToken' => 'tok', 'serviceUrl' => 'https://bot.sovereign.example/', 'trustedServiceHosts' => ['', 'Sovereign.Example']], '{"id":"s"}'],
			'teams-configured-hosts-replace-default' => [['accessToken' => 'tok', 'trustedServiceHosts' => ['sovereign.example']], ''],
			'teams-no-token' => [['serviceUrl' => 'https://smba.trafficmanager.net/emea/'], ''],
		];
		$found = [];
		foreach ($teams as $name => [$configuration, $answer]) {
			$this->posted = [];
			$adapter = new TeamsChannelAdapter(sourceResolver: $this->resolver(configuration: $configuration), clientService: $this->clients(answer: $answer), referenceDetector: new CaseReferenceDetector());
			$found[$name] = ['result' => $adapter->reply($teamsMessage, 'Uw zaak is in behandeling')->toArray(), 'posted' => $this->posted];
		}

		$messaging = [
			'messaging-no-source' => [null, ''],
			'messaging-mock' => [['mock' => true], ''],
			'messaging-no-endpoint' => [['replyEndpoint' => ' '], ''],
			'messaging-sent' => [['replyEndpoint' => 'https://gw.example/reply', 'accessToken' => 'tok'], '{"messageId":"g-1"}'],
			'messaging-sent-id' => [['replyEndpoint' => 'https://gw.example/reply'], '{"id":"g-2"}'],
			'messaging-sent-no-reference' => [['replyEndpoint' => 'https://gw.example/reply'], 'not json'],
			'messaging-gateway-down' => [['replyEndpoint' => 'https://gw.example/reply'], 'throw'],
		];
		foreach ($messaging as $name => [$configuration, $answer]) {
			$this->posted = [];
			$adapter = new MessagingChannelAdapter(sourceResolver: $this->resolver(configuration: $configuration), clientService: $this->clients(answer: $answer));
			$found[$name] = ['result' => $adapter->reply($messagingMessage, 'Dank u')->toArray(), 'posted' => $this->posted];
		}

		$found['form-reply'] = ['result' => (new FormSubmissionAdapter())->reply($messagingMessage, 'x')->toArray(), 'posted' => []];
		$found['report-reply'] = ['result' => (new PublicSpaceReportAdapter())->reply($messagingMessage, 'x')->toArray(), 'posted' => []];

		return $found;
	}

	/**
	 * Every case against the recorded fixture.
	 *
	 * @return void
	 */
	public function testTheAdaptersMatchTheRecording(): void {
		$found = $this->receiveCases() + $this->replyCases();

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, json_decode((string)json_encode($found[$name], JSON_PRESERVE_ZERO_FRACTION), true), 'case ' . $name);
		}
	}
}
