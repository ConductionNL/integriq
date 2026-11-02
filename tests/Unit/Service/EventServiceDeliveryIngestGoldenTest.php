<?php

/**
 * Golden characterization of EventService::ingestDeliveryRequest().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\Integriq\Outbound\OutboundSendGateInterface;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Every opt-out path of a delivery request, recorded against a golden file:
 * each send-gate call with its arguments, the saved CloudEvent, each log
 * line, whether routing ran, and the result.
 *
 * Regenerate the golden file on the code it must describe with
 * DELIVERY_INGEST_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\EventService
 * @uses   \OCA\Integriq\Event\DeliveryRequestedEvent
 */
class EventServiceDeliveryIngestGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/event/delivery-ingest.json';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * A send gate that records each call and answers as the case says.
	 *
	 * @return OutboundSendGateInterface
	 */
	private function gate(): OutboundSendGateInterface {
		$test = $this;
		return new class($test) implements OutboundSendGateInterface {
			/**
			 * @param EventServiceDeliveryIngestGoldenTest $test The recording test.
			 */
			public function __construct(private readonly EventServiceDeliveryIngestGoldenTest $test) {
			}

			public function check(string $channel, string $category, string $address, array $options = []): array {
				$this->test->record(['gate.check', $channel, $category, $address, $options]);
				return $this->test->decision($address, $category);
			}

			public function compose(string $body, array $decision, string $channel, string $caseRef = ''): array {
				$this->test->record(['gate.compose', $body, $decision['code'], $channel, $caseRef]);
				return ['body' => $body . ' [unsubscribe]', 'unsubscribeLink' => null, 'headers' => []];
			}

			public function open(
				string $channel,
				string $subjectRef,
				string $subject,
				string $body,
				string $address,
				array $options = [],
				?array $decision = null,
			): ?string {
				$this->test->record(['gate.open', $channel, $subjectRef, $subject, $body, $address, $options, $decision]);
				return $this->test->answer('open', 'row-1');
			}

			public function handedOver(?string $uuid, string $address, ?string $reference = null): void {
				$this->test->record(['gate.handedOver', $uuid, $address, $reference]);
			}

			public function failed(?string $uuid, string $address, string $step, string $reason): void {
				$this->test->record(['gate.failed', $uuid, $address, $step, $reason]);
			}

			public function recordRefusal(string $channel, string $subjectRef, array $decision, array $options = []): string {
				$this->test->record(['gate.recordRefusal', $channel, $subjectRef, $decision, $options]);
				return 'row-r';
			}
		};
	}//end gate()

	/**
	 * Record one collaborator call.
	 *
	 * @param array<int, mixed> $entry The call.
	 *
	 * @return void
	 */
	public function record(array $entry): void {
		$this->log[] = $entry;
	}//end record()

	/**
	 * The case's answer for one collaborator, or the default.
	 *
	 * @param string $name The collaborator call.
	 * @param string|null $default The default answer.
	 *
	 * @return string|null
	 */
	public function answer(string $name, ?string $default): ?string {
		if (array_key_exists($name, ($this->case['answers'] ?? [])) === true) {
			return $this->case['answers'][$name];
		}

		return $default;
	}//end answer()

	/**
	 * The decision the gate gives, shaped like the real gate's.
	 *
	 * @param string $address The recipient.
	 * @param string $category The category.
	 *
	 * @return array<string, mixed>
	 */
	public function decision(string $address, string $category): array {
		if (array_key_exists('sendValue', $this->case) === true) {
			return [
				'send' => $this->case['sendValue'],
				'overridden' => false,
				'code' => 'allowed',
				'reason' => '',
				'unsubscribe' => null,
				'address' => $address,
				'category' => $category,
			];
		}

		$send = (($this->case['answers']['send'] ?? 'yes') === 'yes');
		$unsubscribe = null;
		if ($send === true && ($this->case['answers']['unsubscribe'] ?? 'yes') === 'yes') {
			$unsubscribe = ['url' => 'https://gem.nl/unsubscribe/t-1', 'headers' => ['List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']];
		}

		$code = 'allowed';
		$reason = '';
		if ($send === false) {
			$code = 'opted-out';
			$reason = 'This person asked not to receive these messages.';
		}

		return [
			'send' => $send,
			'overridden' => false,
			'code' => $code,
			'reason' => $reason,
			'unsubscribe' => $unsubscribe,
			'address' => $address,
			'category' => $category,
		];
	}//end decision()

	/**
	 * The service over recording collaborators, with routing replaced by a recorder.
	 *
	 * @return EventService
	 */
	private function service(): EventService {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null, $_rbac = true, $_multitenancy = true) {
				$masked = $object;
				$masked['time'] = (preg_match('/^\d{4}-\d{2}-\d{2}T/', (string)$object['time']) === 1) ? '<date>' : $object['time'];
				$this->log[] = ['or.saveObject', $masked, $register, $schema, $_rbac, $_multitenancy];
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'evt-1');
			}
		);

		$logger = new class($this) extends AbstractLogger {
			/**
			 * @param EventServiceDeliveryIngestGoldenTest $test The recording test.
			 */
			public function __construct(private readonly EventServiceDeliveryIngestGoldenTest $test) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->record(['log.' . $level, (string)$message, $context]);
			}
		};

		$gate = null;
		if (($this->case['gate'] ?? true) === true) {
			$gate = $this->gate();
		}

		$service = $this->getMockBuilder(EventService::class)
			->setConstructorArgs(
				[
					$objects,
					$this->createMock(IClientService::class),
					$logger,
					$this->createMock(WebhookSignatureService::class),
					$this->createMock(SynchronizationService::class),
					$this->createMock(JobService::class),
					$this->createMock(CallService::class),
					$this->createMock(FlowRunnerService::class),
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					$gate,
				]
			)
			->onlyMethods(['processEvent'])
			->getMock();
		$service->method('processEvent')->willReturnCallback(
			function (ObjectEntity $event) {
				$this->log[] = ['processEvent', $event->getUuid()];
				$messages = [];
				for ($i = 1; $i <= (int)($this->case['answers']['messages'] ?? 0); $i++) {
					$messages[] = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'msg-' . $i);
				}

				return $messages;
			}
		);

		return $service;
	}//end service()

	/**
	 * The cases: payload, request extras, gate wiring and collaborator answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		return [
			'no-recipient' => ['payload' => ['caseId' => 'case-1'], 'answers' => ['messages' => '1']],
			'no-recipient-no-gate' => ['gate' => false, 'payload' => ['caseId' => 'case-1']],
			'blank-recipient' => ['payload' => ['recipient' => '   ', 'recipientChannel' => 'email']],
			'recipient-not-string' => ['payload' => ['recipient' => 42]],
			'recipient-array-no-address' => ['payload' => ['recipient' => ['channel' => 'sms']]],
			'allowed-body-composed-routed' => ['payload' => ['recipient' => 'piet@example.org', 'recipientChannel' => 'email', 'body' => 'Uw afspraak', 'caseRef' => 'Z-1'], 'answers' => ['messages' => '2']],
			'allowed-array-recipient-not-matched' => ['payload' => ['recipient' => ['address' => 'jan@example.org', 'channel' => 'sms'], 'recipientChannel' => 'email', 'category' => 'case-update']],
			'allowed-array-recipient-default-channel' => ['payload' => ['recipient' => ['address' => 'jan@example.org'], 'recipientChannel' => 'post'], 'answers' => ['messages' => '1']],
			'allowed-no-unsubscribe' => ['payload' => ['recipient' => 'piet@example.org', 'body' => 'Tekst', 'category' => 'besluit'], 'answers' => ['unsubscribe' => 'no', 'messages' => '1']],
			'allowed-body-not-string' => ['payload' => ['recipient' => 'piet@example.org', 'body' => 7], 'answers' => ['messages' => '1']],
			'allowed-open-fails' => ['payload' => ['recipient' => 'piet@example.org'], 'answers' => ['open' => null, 'messages' => '1']],
			'opted-out' => ['payload' => ['recipient' => ['address' => 'jan@example.org', 'channel' => 'email'], 'category' => 'case-update', 'body' => 'Uw zaak'], 'answers' => ['send' => 'no']],
			'no-gate-service-refused' => ['gate' => false, 'payload' => ['recipient' => 'jan@example.org', 'category' => 'service', 'recipientChannel' => 'email']],
			'no-gate-default-category-refused' => ['gate' => false, 'payload' => ['recipient' => 'jan@example.org']],
			'no-gate-floor-sent' => ['gate' => false, 'payload' => ['recipient' => 'jan@example.org', 'category' => ' BESLUIT ', 'body' => 'b'], 'answers' => ['messages' => '1']],
			'no-gate-alias-sent' => ['gate' => false, 'payload' => ['recipient' => 'jan@example.org', 'category' => 'Invordering']],
			'send-truthy-string-refused' => ['payload' => ['recipient' => 'piet@example.org', 'body' => 'b'], 'sendValue' => 'no', 'answers' => ['messages' => '1']],
			'send-truthy-int-refused' => ['payload' => ['recipient' => 'piet@example.org', 'body' => 'b'], 'sendValue' => 1, 'answers' => ['messages' => '1']],
			'forged-user-in-payload' => [
				'payload' => ['recipient' => 'piet@example.org', 'userId' => 'mallory', 'requestedBy' => 'mallory', 'owner' => 'mallory'],
				'request' => ['userId' => 'alice', 'externalReference' => 'ext-9'],
				'answers' => ['messages' => '1'],
			],
			'forged-user-opted-out' => [
				'payload' => ['recipient' => 'jan@example.org', 'userId' => 'mallory'],
				'request' => ['userId' => 'alice'],
				'answers' => ['send' => 'no'],
			],
		];
	}//end cases()

	/**
	 * Run one case and describe what happened.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->case = $case;
		$this->log = [];
		$service = $this->service();

		$request = new DeliveryRequestedEvent(
			sourceApp: 'dossiq',
			subjectRegister: 'dossiq',
			subjectSchema: 'case',
			subjectId: 'case-1',
			subjectLabel: 'Kapvergunning',
			deliveryKind: 'notice',
			channel: 'webhook',
			payload: $case['payload'],
			correlationId: 'corr-1',
			externalReference: ($case['request']['externalReference'] ?? null),
			userId: ($case['request']['userId'] ?? null),
		);

		try {
			$result = $service->ingestDeliveryRequest(request: $request);
			$outcome = [
				'event' => $result['event']->getUuid(),
				'messages' => array_map(static fn (ObjectEntity $m): ?string => $m->getUuid(), $result['messages']),
				'refusal' => $result['refusal'],
				'keys' => array_keys($result),
			];
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
		}

		return ['outcome' => $outcome, 'log' => $this->log];
	}//end runCase()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase($case);
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n";
		if (getenv('DELIVERY_INGEST_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()

	/**
	 * The stored event carries the request's user, never one named in the payload.
	 *
	 * @return void
	 */
	public function testTheStoredEventCarriesTheRequestUserNotAPayloadOne(): void {
		$cases = $this->cases();
		foreach (['forged-user-in-payload', 'forged-user-opted-out'] as $name) {
			$run = $this->runCase($cases[$name]);
			$saved = array_values(array_filter($run['log'], static fn (array $e): bool => $e[0] === 'or.saveObject'));
			$this->assertCount(1, $saved, $name);
			$this->assertSame('alice', $saved[0][1]['userId'], $name);
		}
	}//end testTheStoredEventCarriesTheRequestUserNotAPayloadOne()

	/**
	 * Only a decision whose send is exactly true routes: a truthy non-boolean is refused.
	 *
	 * @return void
	 */
	public function testATruthyNonBooleanSendIsRefusedNotRouted(): void {
		$cases = $this->cases();
		foreach (['send-truthy-string-refused', 'send-truthy-int-refused'] as $name) {
			$run = $this->runCase($cases[$name]);
			$calls = array_column($run['log'], 0);
			$this->assertSame(['code' => 'allowed', 'reason' => ''], $run['outcome']['refusal'], $name);
			$this->assertSame([], $run['outcome']['messages'], $name);
			$this->assertContains('gate.recordRefusal', $calls, $name);
			$this->assertNotContains('processEvent', $calls, $name);
			$this->assertNotContains('gate.open', $calls, $name);
			$this->assertNotContains('gate.handedOver', $calls, $name);
			$this->assertNotContains('gate.failed', $calls, $name);
		}
	}//end testATruthyNonBooleanSendIsRefusedNotRouted()
}//end class
