<?php

/**
 * Contract of the IntakeRoutingService.
 *
 * @category Contract
 * @package  OCA\Integriq\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see IntakeRoutingService} that other components call.
 */
interface IntakeRoutingServiceInterface {
	/**
	 * The OpenRegister register holding integriq's objects.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema one received channel message is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA_MESSAGE = 'intake_message';

	/**
	 * The schema one routing rule is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA_RULE = 'intake_routing_rule';

	/**
	 * Route one message.
	 *
	 * @param InboundMessage $message The normalised message.
	 *
	 * @return ObjectEntity The stored `intake_message` object, in its final state.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on IntakeRoutingService::route()
	 */
	public function route(InboundMessage $message): ObjectEntity;

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
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on IntakeRoutingService::routeBatch()
	 */
	public function routeBatch(array $messages): array;

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
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on IntakeRoutingService::validateRule()
	 */
	public function validateRule(array $rule, IntakeChannelRegistryInterface $registry): void;

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
	public function firstMatchingRule(InboundMessage $message): ?array;

	/**
	 * Whether one rule's condition holds for a message.
	 *
	 * A rule with no condition matches every message on its channel.
	 *
	 * @param array<string,mixed> $rule The rule.
	 * @param InboundMessage $message The message.
	 *
	 * @return bool True when the rule applies.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on IntakeRoutingService::matches()
	 */
	public function matches(array $rule, InboundMessage $message): bool;

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
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on IntakeRoutingService::buildTargetPayload()
	 */
	public function buildTargetPayload(array $rule, InboundMessage $message): array;
}
