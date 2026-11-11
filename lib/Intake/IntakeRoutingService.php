<?php

/**
 * Integriq IntakeRoutingService.
 *
 * Routing is configuration, not code: a rule names a channel, an optional
 * condition over the normalised message, and the case type the message opens.
 * A message that matches no rule is held in a reviewable inbox with its
 * reason. It is never dropped, and it never opens a default case: a lost
 * report and a case list full of noise are both worse than a queue somebody
 * reads.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

use OCA\Integriq\Event\IntakeMessageRoutedEvent;
use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Routes normalised messages onto case types, or holds them for review.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-routing-rule-maps-a-channel-and-a-payload-onto-a-case-type-req-ic-002
 */
class IntakeRoutingService implements IntakeRoutingServiceInterface {

	/**
	 * The message is stored and not yet routed.
	 *
	 * @var string
	 */
	public const STATUS_RECEIVED = 'received';

	/**
	 * A rule matched and an app opened the target.
	 *
	 * @var string
	 */
	public const STATUS_ROUTED = 'routed';

	/**
	 * Nothing matched, or nothing claimed the target: a human decides.
	 *
	 * @var string
	 */
	public const STATUS_HELD = 'held';

	/**
	 * This one message could not be handled; the rest of its batch was.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Persists messages and reads rules.
	 * @param IEventDispatcher $eventDispatcher Offers a routed message to the owning app.
	 * @param SchemaMapper $schemaMapper Reads the target case type's declared fields.
	 * @param LoggerInterface $logger Records refusals.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Route one message.
	 *
	 * @param InboundMessage $message The normalised message.
	 *
	 * @return ObjectEntity The stored `intake_message` object, in its final state.
	 */
	public function route(InboundMessage $message): ObjectEntity {
		$payload = array_merge($message->toObject(), ['status' => self::STATUS_RECEIVED]);
		$stored = $this->objectService->saveObject(
			object: $payload,
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE,
		);

		$rule = $this->firstMatchingRule(message: $message);
		if ($rule === null) {
			return $this->hold(
				payload: $payload,
				uuid: (string)$stored->getUuid(),
				reason: 'No routing rule matched channel "' . $message->getChannelId() . '".'
			);
		}

		$event = new IntakeMessageRoutedEvent(
			message: $message->toObject(),
			targetSchema: (string)($rule['targetSchema'] ?? ''),
			targetPayload: $this->buildTargetPayload(rule: $rule, message: $message),
			files: $this->transferableFiles(message: $message),
			messageUuid: (string)$stored->getUuid(),
			ruleName: (string)($rule['name'] ?? ''));
		$this->eventDispatcher->dispatchTyped($event);

		if ($event->getCreatedRef() === null) {
			return $this->hold(
				payload: $payload,
				uuid: (string)$stored->getUuid(),
				reason: 'No app opened a "' . (string)($rule['targetSchema'] ?? '') . '" for this message.'
			);
		}

		$payload['status'] = self::STATUS_ROUTED;
		$payload['ruleName'] = (string)($rule['name'] ?? '');
		$payload['targetSchema'] = (string)($rule['targetSchema'] ?? '');
		$payload['targetRef'] = (string)$event->getCreatedRef();
		$payload['reason'] = '';

		return $this->objectService->saveObject(
			object: $payload,
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE,
			uuid: (string)$stored->getUuid(),
		);

	}//end route()

	/**
	 * Route a batch, one item at a time.
	 *
	 * One message that cannot be handled is captured with its reason and the
	 * rest of the batch still goes through: per-item isolation, as the
	 * synchronization engine has it.
	 *
	 * @param array<int,InboundMessage> $messages The messages.
	 *
	 * @return array{routed:int,held:int,failed:int,failures:array<int,string>} What the batch did.
	 */
	public function routeBatch(array $messages): array {
		$result = ['routed' => 0, 'held' => 0, 'failed' => 0, 'failures' => []];
		foreach ($messages as $message) {
			try {
				$stored = $this->route(message: $message);
				$status = (string)($stored->getObject()['status'] ?? self::STATUS_HELD);
				if ($status === self::STATUS_ROUTED) {
					$result['routed']++;
					continue;
				}

				$result['held']++;
			} catch (Throwable $exception) {
				$result['failed']++;
				$result['failures'][] = $message->getExternalId() . ': ' . $exception->getMessage();
				$this->captureFailure(message: $message, reason: $exception->getMessage());
			}
		}

		return $result;

	}//end routeBatch()

	/**
	 * Check a rule before it is saved.
	 *
	 * A mapping naming a field the case type does not have fails once, in
	 * front of the administrator who wrote it, rather than on every submission
	 * afterwards.
	 *
	 * @param array<string,mixed> $rule The rule as the administrator wrote it.
	 * @param IntakeChannelRegistryInterface $registry The channels this instance has.
	 *
	 * @return void
	 *
	 * @throws IntakeRoutingException When the channel, the target or a mapped field is unknown.
	 */
	public function validateRule(array $rule, IntakeChannelRegistryInterface $registry): void {
		$this->assertKnownChannel(rule: $rule, registry: $registry);
		$targetSchema = $this->requiredTargetSchema(rule: $rule);
		$properties = $this->targetProperties(targetSchema: $targetSchema);
		foreach ($this->mappedTargets(rule: $rule) as $field) {
			if (array_key_exists((string)$field, $properties) === false) {
				throw new IntakeRoutingException(
					message: 'Case type "' . $targetSchema . '" has no field "' . $field . '".'
				);
			}
		}

	}//end validateRule()

	/**
	 * Refuse a rule whose channel no adapter answers to.
	 *
	 * @param array<string,mixed>            $rule     The rule.
	 * @param IntakeChannelRegistryInterface $registry The channels this instance has.
	 *
	 * @return void
	 *
	 * @throws IntakeRoutingException When the channel is unknown.
	 */
	private function assertKnownChannel(array $rule, IntakeChannelRegistryInterface $registry): void {
		$channelId = trim((string)($rule['channelId'] ?? ''));
		if ($registry->has($channelId) === false) {
			throw new IntakeRoutingException(
				message: 'No intake channel adapter answers to "' . $channelId . '".'
			);
		}

	}//end assertKnownChannel()

	/**
	 * The case type a rule opens.
	 *
	 * @param array<string,mixed> $rule The rule.
	 *
	 * @return string The target schema.
	 *
	 * @throws IntakeRoutingException When the rule names none.
	 */
	private function requiredTargetSchema(array $rule): string {
		$targetSchema = trim((string)($rule['targetSchema'] ?? ''));
		if ($targetSchema === '') {
			throw new IntakeRoutingException(message: 'A routing rule must name the case type it opens.');
		}

		return $targetSchema;

	}//end requiredTargetSchema()

	/**
	 * The target fields a rule writes: its mapped fields, then its location field.
	 *
	 * @param array<string,mixed> $rule The rule.
	 *
	 * @return array<int,int|string> The field names.
	 *
	 * @throws IntakeRoutingException When the field mapping is not an object.
	 */
	private function mappedTargets(array $rule): array {
		$mapped = ($rule['fieldMapping'] ?? []);
		if (is_array($mapped) === false) {
			throw new IntakeRoutingException(message: 'The field mapping must be an object of target field to source.');
		}

		$targets = array_keys($mapped);
		$locationField = trim((string)($rule['locationField'] ?? ''));
		if ($locationField !== '') {
			$targets[] = $locationField;
		}

		return $targets;

	}//end mappedTargets()

	/**
	 * The first enabled rule whose channel and condition match.
	 *
	 * The rules are administrator configuration, and `intake_routing_rule`
	 * is deny-all for everyone else. A message arrives on the intake account
	 * of its connection, which is no administrator, so a read through RBAC
	 * returns no rule and every message would be held. The rules are read as
	 * the engine (`_rbac: false`), exactly like the DSO and webhook
	 * connections read their consumers. Read only: writing a rule stays an
	 * administrator action (IntakeChannelsController::saveRule()).
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return array<string,mixed>|null The rule, or null when none matches.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/intake-channels/spec.md
	 */
	public function firstMatchingRule(InboundMessage $message): ?array {
		$results = $this->readChannelRules(message: $message);
		if (is_array($results) === false) {
			return null;
		}

		foreach ($this->enabledChannelRules(rows: $results, message: $message) as $rule) {
			if ($this->matches(rule: $rule, message: $message) === true) {
				return $rule;
			}
		}

		return null;

	}//end firstMatchingRule()

	/**
	 * The stored rules of the message's channel, read as the engine (see
	 * firstMatchingRule()).
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return mixed The result rows, or whatever the object service answered.
	 */
	private function readChannelRules(InboundMessage $message): mixed {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_RULE,
					'channelId' => $message->getChannelId(),
					'isEnabled' => true,
				],
			],
			_rbac: false,
			_multitenancy: false
		);

		return ($matches['results'] ?? $matches);

	}//end readChannelRules()

	/**
	 * The enabled rules of the message's channel, lowest order first.
	 *
	 * The channel is both a filter and a check here on purpose. A filter
	 * the objects endpoint does not apply comes back as a wider result
	 * set, not as an error, and a rule for another channel matching this
	 * message would open the wrong case type without anything failing.
	 *
	 * @param array          $rows    The rows read.
	 * @param InboundMessage $message The message.
	 *
	 * @return array<int,array<string,mixed>> The rules.
	 */
	private function enabledChannelRules(array $rows, InboundMessage $message): array {
		$rules = [];
		foreach ($rows as $row) {
			$rule = $this->enabledChannelRule(row: $row, message: $message);
			if ($rule !== null) {
				$rules[] = $rule;
			}
		}

		usort(
			$rules,
			static fn (array $left, array $right): int => ((int)($left['order'] ?? 0) <=> (int)($right['order'] ?? 0))
		);

		return $rules;

	}//end enabledChannelRules()

	/**
	 * One row's rule when it is an enabled rule of the message's channel.
	 *
	 * @param mixed          $row     The row read.
	 * @param InboundMessage $message The message.
	 *
	 * @return array<string,mixed>|null The rule, or null.
	 */
	private function enabledChannelRule(mixed $row, InboundMessage $message): ?array {
		if (($row instanceof ObjectEntity) === false) {
			return null;
		}

		$rule = $row->getObject();
		if ((string)($rule['channelId'] ?? '') !== $message->getChannelId() || ($rule['isEnabled'] ?? true) === false) {
			return null;
		}

		return $rule;

	}//end enabledChannelRule()

	/**
	 * Whether one rule's condition holds for a message.
	 *
	 * A rule with no condition matches every message on its channel.
	 *
	 * @param array<string,mixed> $rule The rule.
	 * @param InboundMessage $message The message.
	 *
	 * @return bool True when the rule applies.
	 */
	public function matches(array $rule, InboundMessage $message): bool {
		$condition = ($rule['condition'] ?? null);
		if (is_array($condition) === false || $condition === []) {
			return true;
		}

		$actual = $this->readSource(source: (string)($condition['field'] ?? ''), message: $message);
		$expected = ($condition['value'] ?? null);

		return match ((string)($condition['operator'] ?? 'equals')) {
			'contains' => $this->containsText(actual: $actual, expected: $expected),
			'exists' => ($actual !== null && $actual !== ''),
			'in' => $this->isOneOf(actual: $actual, expected: $expected),
			default => ((string)$actual === (string)$expected),
		};

	}//end matches()

	/**
	 * Whether a text value contains the expected text, ignoring case.
	 *
	 * @param mixed $actual   The value read from the message.
	 * @param mixed $expected The condition's value.
	 *
	 * @return bool
	 */
	private function containsText(mixed $actual, mixed $expected): bool {
		return is_string($actual) === true && str_contains(
			mb_strtolower($actual),
			mb_strtolower((string)$expected)
		) === true;

	}//end containsText()

	/**
	 * Whether the value is one of the condition's list, compared loosely.
	 *
	 * @param mixed $actual   The value read from the message.
	 * @param mixed $expected The condition's value.
	 *
	 * @return bool
	 */
	private function isOneOf(mixed $actual, mixed $expected): bool {
		return is_array($expected) === true && in_array($actual, $expected, false) === true;

	}//end isOneOf()

	/**
	 * Build the payload the target is opened with.
	 *
	 * The location is written only when the message actually carries one: a
	 * channel that supplies no location writes nothing, rather than a zero
	 * coordinate that looks measured.
	 *
	 * @param array<string,mixed> $rule The matching rule.
	 * @param InboundMessage $message The message.
	 *
	 * @return array<string,mixed> The target payload.
	 */
	public function buildTargetPayload(array $rule, InboundMessage $message): array {
		$payload = [];
		foreach (($rule['fieldMapping'] ?? []) as $targetField => $source) {
			$value = $this->readSource(source: (string)$source, message: $message);
			if ($value === null) {
				continue;
			}

			$payload[(string)$targetField] = $value;
		}

		$locationField = trim((string)($rule['locationField'] ?? ''));
		if ($locationField !== '' && $message->getLocation() !== null) {
			$payload[$locationField] = $message->getLocation();
		}

		return $payload;

	}//end buildTargetPayload()

	/**
	 * Read one source expression off a message.
	 *
	 * `text`, `channelId`, `externalId`, `correspondent.<key>`, `fields.<key>`
	 * and `location` are the expressions a rule may name.
	 *
	 * @param string $source The expression.
	 * @param InboundMessage $message The message.
	 *
	 * @return mixed The value, or null when the message does not carry it.
	 */
	private function readSource(string $source, InboundMessage $message): mixed {
		$source = trim($source);
		if ($source === '') {
			return null;
		}

		return match (true) {
			$source === 'text' => $message->getText(),
			$source === 'channelId' => $message->getChannelId(),
			$source === 'externalId' => $message->getExternalId(),
			$source === 'location' => $message->getLocation(),
			$source === 'receivedAt' => $message->getReceivedAt(),
			str_starts_with($source, 'correspondent.') => ($message->getCorrespondent()[substr($source, 14)] ?? null),
			str_starts_with($source, 'fields.') => ($message->getFields()[substr($source, 7)] ?? null),
			default => null,
		};

	}//end readSource()

	/**
	 * Attachments and media, bytes and all, for the hand-off.
	 *
	 * Media stay files: a photo pasted into a text field is a photo nobody can
	 * open.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return array<int,array<string,mixed>> The files.
	 */
	private function transferableFiles(InboundMessage $message): array {
		return array_merge($message->getAttachments(), $message->getMedia());

	}//end transferableFiles()

	/**
	 * Hold a message for review.
	 *
	 * @param array<string,mixed> $payload The message payload.
	 * @param string $uuid The stored message's uuid.
	 * @param string $reason Why it is held.
	 *
	 * @return ObjectEntity The held message.
	 */
	private function hold(array $payload, string $uuid, string $reason): ObjectEntity {
		$payload['status'] = self::STATUS_HELD;
		$payload['reason'] = $reason;

		return $this->objectService->saveObject(
			object: $payload,
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE,
			uuid: $uuid,
		);

	}//end hold()

	/**
	 * Record a message the batch could not handle.
	 *
	 * @param InboundMessage $message The message.
	 * @param string $reason What went wrong.
	 *
	 * @return void
	 */
	private function captureFailure(InboundMessage $message, string $reason): void {
		try {
			$this->objectService->saveObject(
				object: array_merge(
					$message->toObject(),
					['status' => self::STATUS_FAILED, 'reason' => $reason]
				),
				register: self::REGISTER,
				schema: self::SCHEMA_MESSAGE,
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'Integriq intake: a failed message could not be captured either.',
				['channel' => $message->getChannelId(), 'reason' => $exception->getMessage()]
			);
		}

	}//end captureFailure()

	/**
	 * The declared fields of a target case type.
	 *
	 * @param string $targetSchema The target schema slug.
	 *
	 * @return array<string,mixed> The properties.
	 *
	 * @throws IntakeRoutingException When the schema is unknown or does not declare its fields.
	 */
	private function targetProperties(string $targetSchema): array {
		$schema = $this->schemaMapper->find($targetSchema);
		if ($schema === null) {
			throw new IntakeRoutingException(message: 'No case type "' . $targetSchema . '" on this instance.');
		}

		if (method_exists($schema, 'getProperties') === false) {
			throw new IntakeRoutingException(
				message: 'Case type "' . $targetSchema . '" does not declare its fields, so a mapping cannot be checked.'
			);
		}

		$properties = $schema->getProperties();
		if (is_array($properties) === false) {
			return [];
		}

		return $properties;

	}//end targetProperties()

}//end class
