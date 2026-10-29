<?php

/**
 * Contract of the OptOutRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound\Identity
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

namespace OCA\Integriq\Outbound\Identity;

use InvalidArgumentException;
use OCA\Integriq\Db\OptOut;

/**
 * Public operations of {@see OptOutRegistry} that other components call.
 */
interface OptOutRegistryInterface {
	/**
	 * An opt-out that stops everything but the exempt categories.
	 *
	 * @var string
	 */
	public const SCOPE_INSTANCE = 'instance';

	/**
	 * An opt-out or consent for one channel.
	 *
	 * @var string
	 */
	public const SCOPE_CHANNEL = 'channel';

	/**
	 * An opt-out that stops the updates on one case.
	 *
	 * @var string
	 */
	public const SCOPE_CASE = 'case';

	/**
	 * An opt-out or consent for one list.
	 *
	 * @var string
	 */
	public const SCOPE_LIST = 'list';

	/**
	 * Whether integriq's senders and listeners ask at all (the rollback flag).
	 *
	 * @return bool True unless an administrator set the flag to false.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function isAuthorityEnabled(): bool;

	/**
	 * Whether a message may be sent to one address, and whether sending it
	 * overrides an opt-out. A batch of one through decideMany().
	 *
	 * @param string $address The recipient.
	 * @param string $category What kind of message this is.
	 * @param string|null $caseRef The case, when the message is about one.
	 *
	 * @return array{send:bool,overridden:bool,reason:string,code:string} The decision.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 */
	public function decide(string $address, string $category, ?string $caseRef = null): array;

	/**
	 * Decide for a batch of recipients on one channel and one category.
	 *
	 * The rules, in order (design section 1): normalise the address; a reply
	 * with `inReplyTo` is allowed; an exempt category is sent, flagged as an
	 * override when an opt-out matched; a matching opt-out refuses; when
	 * consent is required a matching opt-in with a permitting lawful basis
	 * must exist; otherwise the send is allowed with unsubscribe material.
	 *
	 * @param string $channel The channel, for example `email` or `sms`.
	 * @param string $category What kind of message this is.
	 * @param bool $requiresConsent True for marketing and business-initiated WhatsApp.
	 * @param list<array<string,mixed>> $recipients Each `{address, caseRef?, listRef?, contactRef?}`.
	 * @param string $sourceApp The asking app.
	 * @param string $correlationId The sender's correlation id.
	 * @param string $baseUrl The instance url the link is built on.
	 * @param string|null $inReplyTo The inbound message a reply answers.
	 * @param bool $probe True to answer only: no log row, no link material.
	 *
	 * @return array<string,array<string,mixed>> One decision
	 *         per recipient, keyed by the address as given.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- probe is the decision event's contract field, passed through.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-a-probe-answers-without-writing-req-ooa-012
	 */
	public function decideMany(
		string $channel,
		string $category,
		bool $requiresConsent,
		array $recipients,
		string $sourceApp,
		string $correlationId,
		string $baseUrl = '',
		?string $inReplyTo = null,
		bool $probe = false,
	): array;

	/**
	 * decideMany() for integriq's own senders: never throws, fails closed.
	 *
	 * When the authority flag is off, every recipient is allowed without a
	 * link (the rollback). When the table cannot be read, an exempt category
	 * is sent and everything else is refused with `authority-unavailable`.
	 *
	 * @param string $channel The channel.
	 * @param string $category The category.
	 * @param list<array<string,mixed>> $recipients The recipients.
	 * @param array<string,mixed> $options `sourceApp`, `correlationId`, `baseUrl`, `inReplyTo`,
	 *                                     `requiresConsent`.
	 *
	 * @return array<string,array<string,mixed>> One decision per recipient, keyed by the address as given.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function decideForSend(string $channel, string $category, array $recipients, array $options = []): array;

	/**
	 * Record a person's wish: an opt-out, a consent, or a contact erasure.
	 *
	 * An opt-out or consent is upserted on the dedupe key, so STOP then START
	 * leaves one row reading `opted-in`; the history is in the log. A request
	 * with a `legacyRef` already recorded writes nothing and returns the row
	 * it wrote before.
	 *
	 * @param array<string,mixed> $request `address`, `state`, `scope`, `channel`, `ref`,
	 *                                     `contactRef`, `lawfulBasis`, `evidence`, `source`,
	 *                                     `purpose`, `sourceApp`, `correlationId`, `legacyRef`.
	 *
	 * @return int The record id. For an erasure, the number of rows cleared.
	 *
	 * @throws InvalidArgumentException When the request is incomplete.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
	 */
	public function record(array $request): int;

	/**
	 * Add an opt-out. Kept for the unsubscribe link and the admin path.
	 *
	 * @param string $address The recipient.
	 * @param string $scope Instance wide or one case.
	 * @param string|null $caseRef The case, for a case scoped opt-out.
	 * @param string $source Who or what added it.
	 *
	 * @return OptOut The stored opt-out. Adding the same one twice returns the first.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function add(
		string $address,
		string $scope = self::SCOPE_INSTANCE,
		?string $caseRef = null,
		string $source = 'unsubscribe-link',
	): OptOut;

	/**
	 * One page of the opt-out list, newest first.
	 *
	 * @param int $limit  At most this many rows.
	 * @param int $offset Skip this many.
	 *
	 * @return array{results:list<array<string,mixed>>,total:int} The page.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function page(int $limit = 50, int $offset = 0): array;

	/**
	 * One page of the decision log, newest first.
	 *
	 * @param int    $limit         At most this many rows.
	 * @param int    $offset        Skip this many.
	 * @param string $correlationId Only this correlation id, when not empty.
	 *
	 * @return array{results:list<array<string,mixed>>} The page.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function logPage(int $limit = 50, int $offset = 0, string $correlationId = ''): array;

	/**
	 * Whether a category may never be stopped.
	 *
	 * @param string $category The category.
	 *
	 * @return bool True when it is exempt.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-exempt-categories-are-a-fixed-floor-req-ooa-004
	 */
	public function isProtected(string $category): bool;
}
