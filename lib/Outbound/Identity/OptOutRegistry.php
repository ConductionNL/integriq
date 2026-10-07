<?php

/**
 * Integriq OptOutRegistry.
 *
 * One opt-out per recipient address per instance, checked by every sender in
 * the product. A recipient who asked not to be written to should not have to
 * ask each app separately, and the AVG duty is the sender's, not the app's.
 *
 * Some things cannot be stopped. A besluit, a statutory notice, an account
 * message and a security message are a fixed floor (OptOutCategories): the
 * send proceeds and the override is recorded, so it can be shown afterwards.
 *
 * The opt-outs live in integriq's own table (OptOutMapper), not in
 * OpenRegister. The unsubscribe link writes one from a public request with no
 * session, which OpenRegister refuses, and the read that decides whether to
 * send must not be one a permission check can empty (integriq#2114). The
 * `recipient_opt_out` schema is read-only history: MigrateOptOutsToTable copies
 * it into the table and nothing writes it any more.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
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
 * Every sender asks here before it sends (opt-out-before-send): integriq's
 * own senders through DI, sibling apps through OutboundSendDecisionRequestedEvent.
 * decideMany() is the one decision function. Every suppression, every exempt
 * override and every recorded change goes to the append-only opt-out log.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use InvalidArgumentException;
use OCA\Integriq\Db\OptOut;
use OCA\Integriq\Db\OptOutLogEntry;
use OCA\Integriq\Db\OptOutLogMapper;
use OCA\Integriq\Db\OptOutMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Holds and honours recipient opt-outs and consent.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the one decision function needs the
 * table, the log, the categories, the recipient key and the link material.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- the decision rules of design section 1
 * live in one class on purpose, so there is one place a decision is made.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) -- decide, record, erase, list and the
 * fail-closed wrapper are the vocabulary the spec names.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md
 */
class OptOutRegistry {

	/**
	 * The OpenRegister schema opt-outs were stored under before the table.
	 * Read-only history: only MigrateOptOutsToTable reads it.
	 *
	 * @var string
	 */
	public const SCHEMA = 'recipient_opt_out';

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
	 * Every scope.
	 *
	 * @var array<int,string>
	 */
	public const SCOPES = [self::SCOPE_INSTANCE, self::SCOPE_CHANNEL, self::SCOPE_CASE, self::SCOPE_LIST];

	/**
	 * The state that clears a contact's link and evidence and keeps the opt-out.
	 *
	 * @var string
	 */
	public const STATE_ERASE_CONTACT = 'erase-contact';

	/**
	 * The detail fields a redacted log entry keeps: the decision, nothing about the person.
	 *
	 * @var list<string>
	 */
	private const REDACTION_KEEPS = ['state', 'previousState', 'keptState', 'scope', 'code'];

	/**
	 * The app-config key that turns the check off in integriq's own senders.
	 *
	 * @var string
	 */
	public const CONFIG_AUTHORITY = 'outbound.optout_authority';

	/**
	 * The app-config key once holding the protected categories; now only aliases.
	 *
	 * @var string
	 */
	public const CONFIG_PROTECTED = OptOutCategories::CONFIG_PROTECTED;

	/**
	 * The exempt floor, kept under its old name.
	 *
	 * @var array<int,string>
	 */
	public const DEFAULT_PROTECTED = OptOutCategories::FLOOR;

	/**
	 * Decision code: send.
	 *
	 * @var string
	 */
	public const CODE_ALLOWED = 'allowed';

	/**
	 * Decision code: an opt-out matched.
	 *
	 * @var string
	 */
	public const CODE_OPTED_OUT = 'opted-out';

	/**
	 * Decision code: consent was required and none permits the send.
	 *
	 * @var string
	 */
	public const CODE_NO_CONSENT = 'no-consent';

	/**
	 * Decision code: sent despite an opt-out, because the category is exempt.
	 *
	 * @var string
	 */
	public const CODE_EXEMPT_OVERRIDE = 'exempt-override';

	/**
	 * Decision code: the address does not normalise for this channel.
	 *
	 * @var string
	 */
	public const CODE_INVALID_ADDRESS = 'invalid-address';

	/**
	 * Decision code: no answer could be had, so a non-exempt send is refused.
	 *
	 * @var string
	 */
	public const CODE_AUTHORITY_UNAVAILABLE = 'authority-unavailable';

	/**
	 * How many addresses one table read covers.
	 *
	 * @var int
	 */
	public const CHUNK = 500;

	/**
	 * The matching and consent rules. Pure, so built here rather than injected.
	 *
	 * @var OptOutMatcher
	 */
	private readonly OptOutMatcher $matcher;

	/**
	 * Constructor.
	 *
	 * @param OptOutMapper $mapper Reads and writes the opt-out table.
	 * @param IAppConfig $appConfig Holds the authority flag.
	 * @param ITimeFactory $time Stamps rows and log entries.
	 * @param OptOutCategories $categories The exempt floor and the aliases.
	 * @param RecipientKey $recipientKey Normalises a recipient per channel.
	 * @param OptOutLogMapper $log The append-only decision log.
	 * @param UnsubscribeTokenService $tokens Builds the unsubscribe material.
	 * @param LoggerInterface $logger Warns about failures that are answered closed.
	 * @param OptOutRowBuilder $rows Builds and changes rows for record().
	 */
	public function __construct(
		private readonly OptOutMapper $mapper,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly OptOutCategories $categories,
		private readonly RecipientKey $recipientKey,
		private readonly OptOutLogMapper $log,
		private readonly UnsubscribeTokenService $tokens,
		private readonly LoggerInterface $logger,
		private readonly OptOutRowBuilder $rows,
	) {
		$this->matcher = new OptOutMatcher();

	}//end __construct()

	/**
	 * Whether integriq's senders and listeners ask at all (the rollback flag).
	 *
	 * @return bool True unless an administrator set the flag to false.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function isAuthorityEnabled(): bool {
		$raw = strtolower(trim($this->appConfig->getValueString('integriq', self::CONFIG_AUTHORITY, 'true')));

		return in_array($raw, ['0', 'false', 'no', 'off'], true) === false;

	}//end isAuthorityEnabled()

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
	public function decide(string $address, string $category, ?string $caseRef = null): array {
		$decisions = $this->decideMany(
			channel: '',
			category: $category,
			requiresConsent: false,
			recipients: [['address' => $address, 'caseRef' => (string)$caseRef]],
			sourceApp: 'integriq',
			correlationId: ''
		);
		$decision = $decisions[$address];

		return [
			'send' => $decision['send'],
			'overridden' => $decision['overridden'],
			'reason' => $decision['reason'],
			'code' => $decision['code'],
		];

	}//end decide()

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
	): array {
		$channel = strtolower(trim($channel));
		$canonical = $this->categories->canonical(category: $category, inReplyTo: $inReplyTo, sourceApp: $sourceApp);
		$context = [
			'channel' => $channel,
			'category' => $canonical,
			'sourceApp' => $sourceApp,
			'correlationId' => $correlationId,
			'probe' => $probe,
		];

		$decisions = [];
		foreach (array_chunk($recipients, self::CHUNK) as $chunk) {
			$decisions += $this->decideChunk(
				chunk: $chunk,
				context: $context,
				requiresConsent: $requiresConsent,
				baseUrl: $baseUrl
			);
		}

		return $decisions;

	}//end decideMany()

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
	public function decideForSend(string $channel, string $category, array $recipients, array $options = []): array {
		$sourceApp = (string)($options['sourceApp'] ?? 'integriq');
		$inReplyTo = null;
		if (isset($options['inReplyTo']) === true && $options['inReplyTo'] !== '') {
			$inReplyTo = (string)$options['inReplyTo'];
		}

		if ($this->isAuthorityEnabled() === false) {
			return $this->uniform(
				recipients: $recipients,
				send: true,
				code: self::CODE_ALLOWED,
				reason: 'The opt-out check is turned off on this instance (' . self::CONFIG_AUTHORITY . ').',
				category: $category
			);
		}

		try {
			return $this->decideMany(
				channel: $channel,
				category: $category,
				requiresConsent: (bool)($options['requiresConsent'] ?? false),
				recipients: $recipients,
				sourceApp: $sourceApp,
				correlationId: (string)($options['correlationId'] ?? ''),
				baseUrl: (string)($options['baseUrl'] ?? ''),
				inReplyTo: $inReplyTo
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[OptOutRegistry] the opt-out list could not be read; non-exempt sends are refused',
				['channel' => $channel, 'category' => $category, 'sourceApp' => $sourceApp, 'exception' => $exception->getMessage()]
			);
		}

		if ($this->categories->isExempt($category) === true) {
			return $this->uniform(
				recipients: $recipients,
				send: true,
				code: self::CODE_ALLOWED,
				reason: 'Category "' . $category . '" is sent without the opt-out list.',
				category: $category
			);
		}

		return $this->uniform(
			recipients: $recipients,
			send: false,
			code: self::CODE_AUTHORITY_UNAVAILABLE,
			reason: 'The opt-out list could not be read, so this message was not sent.',
			category: $category
		);

	}//end decideForSend()

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
	public function record(array $request): int {
		$state = (string)($request['state'] ?? '');
		if ($state === self::STATE_ERASE_CONTACT) {
			return $this->eraseContact(
				contactRef: (string)($request['contactRef'] ?? ''),
				sourceApp: (string)($request['sourceApp'] ?? ''),
				correlationId: (string)($request['correlationId'] ?? '')
			);
		}

		$legacyRef = trim((string)($request['legacyRef'] ?? ''));
		if ($legacyRef !== '') {
			$existing = $this->mapper->findByLegacyRef(legacyRef: $legacyRef);
			if ($existing !== null) {
				return (int)$existing->getId();
			}
		}

		$row = $this->rows->rowFor(request: $request);
		$stored = $this->mapper->findByKey(dedupeKey: $row->getDedupeKey());
		$previous = '';
		if ($stored !== null) {
			$previous = (string)$stored->getState();
			$this->rows->applyChange(stored: $stored, row: $row, previous: $previous);
			$stored = $this->mapper->update($stored);
		}

		if ($stored === null) {
			$stored = $this->mapper->insertIfAbsent($row)['optOut'];
		}

		$this->append(
			kind: OptOutLogEntry::KIND_CHANGE,
			address: (string)$stored->getAddress(),
			context: [
				'category' => '',
				'channel' => (string)$stored->getChannel(),
				'sourceApp' => (string)($request['sourceApp'] ?? ''),
				'correlationId' => (string)($request['correlationId'] ?? ''),
			],
			detail: [
				'state' => (string)$stored->getState(),
				'previousState' => $previous,
				'scope' => (string)$stored->getScope(),
				'ref' => (string)($request['ref'] ?? ''),
				'source' => (string)$stored->getSource(),
				'lawfulBasis' => (string)$stored->getLawfulBasis(),
				'evidence' => $stored->evidenceArray(),
			]
		);

		return (int)$stored->getId();

	}//end record()

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
	): OptOut {
		$address = strtolower(trim($address));
		$caseRef = (string)$caseRef;
		if ($scope === self::SCOPE_INSTANCE) {
			$caseRef = '';
		}

		$optOut = new OptOut();
		$optOut->setAddress($address);
		$optOut->setScope($scope);
		$optOut->setCaseRef($caseRef);
		$optOut->setSource($source);
		$optOut->setState(OptOut::STATE_OPTED_OUT);
		$optOut->setSourceApp('integriq');
		$optOut->setCreatedAt($this->time->getTime());
		$optOut->setUpdatedAt($this->time->getTime());
		$optOut->assignDedupeKey();

		return $this->mapper->insertIfAbsent($optOut)['optOut'];

	}//end add()

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
	public function page(int $limit = 50, int $offset = 0): array {
		$rows = array_map(
			static fn (OptOut $optOut): array => $optOut->jsonSerialize(),
			$this->mapper->findPage(limit: $limit, offset: $offset)
		);

		return ['results' => $rows, 'total' => $this->mapper->countAll()];

	}//end page()

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
	public function logPage(int $limit = 50, int $offset = 0, string $correlationId = ''): array {
		$rows = array_map(
			static fn (OptOutLogEntry $entry): array => $entry->jsonSerialize(),
			$this->log->findPage(limit: $limit, offset: $offset, correlationId: $correlationId)
		);

		return ['results' => $rows];

	}//end logPage()

	/**
	 * Whether a category may never be stopped.
	 *
	 * @param string $category The category.
	 *
	 * @return bool True when it is exempt.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-exempt-categories-are-a-fixed-floor-req-ooa-004
	 */
	public function isProtected(string $category): bool {
		return $this->categories->isExempt($category);

	}//end isProtected()

	/**
	 * Decide one chunk with one table read.
	 *
	 * @param list<array<string,mixed>> $chunk The recipients.
	 * @param array{channel:string,category:string,sourceApp:string,correlationId:string,probe:bool} $context The batch.
	 * @param bool $requiresConsent Whether consent is required.
	 * @param string $baseUrl The instance url.
	 *
	 * @return array<string,array<string,mixed>> The decisions.
	 */
	private function decideChunk(array $chunk, array $context, bool $requiresConsent, string $baseUrl): array {
		$keys = [];
		$contacts = [];
		foreach ($chunk as $index => $recipient) {
			$keys[$index] = $this->recipientKey->normalise(
				channel: $context['channel'],
				address: (string)($recipient['address'] ?? '')
			);
			$contacts[] = (string)($recipient['contactRef'] ?? '');
		}

		$rows = [];
		if ($context['category'] !== OptOutCategories::REPLY) {
			$rows = $this->readRows(keys: array_values(array_filter($keys)), contacts: $contacts);
		}

		$decisions = [];
		$allowed = 0;
		foreach ($chunk as $index => $recipient) {
			$given = (string)($recipient['address'] ?? '');
			$decision = $this->decideOne(
				key: $keys[$index],
				recipient: $recipient,
				rows: $rows,
				context: $context,
				requiresConsent: $requiresConsent,
				baseUrl: $baseUrl
			);
			if ($decision['code'] === self::CODE_ALLOWED) {
				$allowed++;
			}

			$decisions[$given] = $decision;
		}

		if ($allowed > 0) {
			$this->append(kind: OptOutLogEntry::KIND_ALLOWED_COUNT, address: '', context: $context, detail: ['count' => $allowed]);
		}

		return $decisions;

	}//end decideChunk()

	/**
	 * Decide one recipient against the rows already read.
	 *
	 * @param string|null $key The normalised address.
	 * @param array<string,mixed> $recipient The recipient.
	 * @param list<OptOut> $rows The rows of the chunk.
	 * @param array{channel:string,category:string,sourceApp:string,correlationId:string,probe:bool} $context The batch.
	 * @param bool $requiresConsent Whether consent is required.
	 * @param string $baseUrl The instance url.
	 *
	 * @return array<string,mixed> The decision: send, overridden, code, reason, unsubscribe, address, category.
	 */
	private function decideOne(
		?string $key,
		array $recipient,
		array $rows,
		array $context,
		bool $requiresConsent,
		string $baseUrl,
	): array {
		$category = $context['category'];
		if ($key === null) {
			$reason = 'This address cannot be used on the "' . $context['channel'] . '" channel.';
			return $this->suppress(code: self::CODE_INVALID_ADDRESS, reason: $reason, key: '', context: $context, detail: []);
		}

		if ($category === OptOutCategories::REPLY) {
			$reason = 'A direct reply is sent whatever the opt-outs say.';
			return $this->decision(send: true, code: self::CODE_ALLOWED, reason: $reason, key: $key, category: $category);
		}

		$mine = $this->matcher->rowsOf(rows: $rows, key: $key, contactRef: (string)($recipient['contactRef'] ?? ''));
		$optOut = $this->matcher->matchingOptOut(rows: $mine, recipient: $recipient, channel: $context['channel'], category: $category);

		if ($this->categories->isExempt($category) === true) {
			if ($optOut === null) {
				return $this->decision(send: true, code: self::CODE_ALLOWED, reason: '', key: $key, category: $category);
			}

			$detail = ['scope' => (string)$optOut->getScope(), 'optOutId' => $optOut->getId()];
			$this->append(kind: OptOutLogEntry::KIND_OVERRIDE, address: $key, context: $context, detail: $detail);
			$reason = 'Category "' . $category . '" cannot be stopped by an opt-out.';
			$decision = $this->decision(send: true, code: self::CODE_EXEMPT_OVERRIDE, reason: $reason, key: $key, category: $category);
			$decision['overridden'] = true;
			return $decision;
		}

		if ($optOut !== null) {
			$reason = 'This address opted out (' . $optOut->getScope() . ').';
			$detail = ['scope' => (string)$optOut->getScope()];
			return $this->suppress(code: self::CODE_OPTED_OUT, reason: $reason, key: $key, context: $context, detail: $detail);
		}

		$consented = $requiresConsent === false
			|| $this->matcher->hasConsent(rows: $mine, recipient: $recipient, channel: $context['channel'], category: $category) === true;
		if ($consented === false) {
			$reason = 'No recorded consent permits this message.';
			return $this->suppress(code: self::CODE_NO_CONSENT, reason: $reason, key: $key, context: $context, detail: []);
		}

		$decision = $this->decision(send: true, code: self::CODE_ALLOWED, reason: '', key: $key, category: $category);
		if ($context['probe'] === true) {
			// A probe only shows a state: no token, no short link.
			return $decision;
		}

		$decision['unsubscribe'] = $this->material(
			key: $key,
			recipient: $recipient,
			channel: $context['channel'],
			category: $category,
			baseUrl: $baseUrl
		);

		return $decision;

	}//end decideOne()

	/**
	 * Refuse one recipient and log the suppression.
	 *
	 * @param string $code The decision code.
	 * @param string $reason Why.
	 * @param string $key The normalised address, or empty.
	 * @param array{channel:string,category:string,sourceApp:string,correlationId:string,probe:bool} $context The batch.
	 * @param array<string,mixed> $detail What else the log row says.
	 *
	 * @return array<string,mixed> The decision.
	 */
	private function suppress(string $code, string $reason, string $key, array $context, array $detail): array {
		$this->append(kind: OptOutLogEntry::KIND_SUPPRESSED, address: $key, context: $context, detail: ['code' => $code] + $detail);

		return $this->decision(send: false, code: $code, reason: $reason, key: $key, category: $context['category']);

	}//end suppress()

	/**
	 * Read every row of a chunk: by address and by contact, in one query each.
	 *
	 * @param list<string> $keys The addresses.
	 * @param list<string> $contacts The contact refs.
	 *
	 * @return list<OptOut> The rows.
	 */
	private function readRows(array $keys, array $contacts): array {
		$rows = [];
		foreach ($this->mapper->findForAddresses(addresses: $keys) as $row) {
			$rows[(int)$row->getId()] = $row;
		}

		$contacts = array_values(array_filter($contacts, static fn (string $ref): bool => $ref !== ''));
		if ($contacts !== []) {
			foreach ($this->mapper->findForContactRefs(contactRefs: $contacts) as $row) {
				$rows[(int)$row->getId()] = $row;
			}
		}

		return array_values($rows);

	}//end readRows()

	/**
	 * The unsubscribe material for an allowed recipient.
	 *
	 * A list send stops the list, a case send stops the case, anything else
	 * stops this channel.
	 *
	 * @param string $key The address.
	 * @param array<string,mixed> $recipient The recipient.
	 * @param string $channel The channel.
	 * @param string $category The category.
	 * @param string $baseUrl The instance url.
	 *
	 * @return array<string,mixed>|null The material.
	 */
	private function material(string $key, array $recipient, string $channel, string $category, string $baseUrl): ?array {
		$scope = self::SCOPE_INSTANCE;
		$ref = '';
		if ($channel !== '') {
			$scope = self::SCOPE_CHANNEL;
		}

		if ((string)($recipient['caseRef'] ?? '') !== '') {
			$scope = self::SCOPE_CASE;
			$ref = (string)$recipient['caseRef'];
		}

		if ((string)($recipient['listRef'] ?? '') !== '') {
			$scope = self::SCOPE_LIST;
			$ref = (string)$recipient['listRef'];
		}

		return $this->tokens->materialFor(
			address: $key,
			scope: $scope,
			channel: $channel,
			ref: $ref,
			category: $category,
			baseUrl: $baseUrl
		);

	}//end material()

	/**
	 * One decision.
	 *
	 * @param bool $send Whether to send.
	 * @param string $code The code.
	 * @param string $reason Why.
	 * @param string $key The normalised address.
	 * @param string $category The category it was decided as.
	 *
	 * @return array<string,mixed> The decision: send, overridden, code, reason, unsubscribe, address, category.
	 */
	private function decision(bool $send, string $code, string $reason, string $key, string $category): array {
		return [
			'send' => $send,
			'overridden' => false,
			'code' => $code,
			'reason' => $reason,
			'unsubscribe' => null,
			'address' => $key,
			'category' => $category,
		];

	}//end decision()

	/**
	 * The same decision for every recipient, with no table read.
	 *
	 * @param list<array<string,mixed>> $recipients The recipients.
	 * @param bool $send Whether to send.
	 * @param string $code The code.
	 * @param string $reason Why.
	 * @param string $category The category.
	 *
	 * @return array<string,array<string,mixed>> The decisions.
	 */
	private function uniform(array $recipients, bool $send, string $code, string $reason, string $category): array {
		$decisions = [];
		foreach ($recipients as $recipient) {
			$given = (string)($recipient['address'] ?? '');
			$decisions[$given] = $this->decision(
				send: $send,
				code: $code,
				reason: $reason,
				key: $given,
				category: $category
			);
		}

		return $decisions;

	}//end uniform()

	/**
	 * Clear a contact's link and evidence on every row, keeping the opt-out.
	 *
	 * @param string $contactRef The contact.
	 * @param string $sourceApp The erasing app.
	 * @param string $correlationId The correlation id.
	 *
	 * @return int How many rows were cleared.
	 *
	 * @throws InvalidArgumentException When there is no contact ref.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-contact-erasure-keeps-the-opt-out-req-ooa-010
	 */
	private function eraseContact(string $contactRef, string $sourceApp, string $correlationId): int {
		$contactRef = trim($contactRef);
		if ($contactRef === '') {
			throw new InvalidArgumentException('An erasure needs a contactRef.');
		}

		$rows = $this->mapper->findForContactRefs(contactRefs: [$contactRef]);
		$this->redactLog(addresses: array_map(static fn ($row): string => (string)$row->getAddress(), $rows));
		foreach ($rows as $row) {
			$row->setContactRef('');
			$row->setEvidence(null);
			$row->setUpdatedAt($this->time->getTime());
			$this->mapper->update($row);
			$this->append(
				kind: OptOutLogEntry::KIND_CHANGE,
				address: $this->recipientKey->hashKey(key: (string)$row->getAddress()),
				context: ['category' => '', 'channel' => (string)$row->getChannel(), 'sourceApp' => $sourceApp, 'correlationId' => $correlationId],
				detail: ['state' => self::STATE_ERASE_CONTACT, 'keptState' => (string)$row->getState(), 'scope' => (string)$row->getScope()]
			);
		}

		return count($rows);

	}//end eraseContact()

	/**
	 * Redact every earlier log entry of these addresses.
	 *
	 * The entry keeps its date, kind, category, channel and decision, under a
	 * hashed key. The address, the correlation id and the evidence go. This is
	 * the one change a log entry ever gets; new entries stay append-only.
	 *
	 * @param list<string> $addresses The erased rows' addresses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/outbound-opt-out-authority/spec.md#requirement-an-erasure-redacts-the-earlier-log-entries-req-ooa-013
	 */
	private function redactLog(array $addresses): void {
		foreach ($this->log->findForAddresses(addresses: $addresses) as $entry) {
			$kept = array_intersect_key($entry->detailArray(), array_flip(self::REDACTION_KEEPS));
			$entry->setAddress($this->recipientKey->hashKey(key: (string)$entry->getAddress()));
			$entry->setCorrelationId('');
			$entry->setDetail((string)json_encode($kept + ['redacted' => true]));
			$this->log->redact(entry: $entry);
		}

	}//end redactLog()

	/**
	 * Append one log row.
	 *
	 * @param string $kind The kind.
	 * @param string $address The recipient key, or empty.
	 * @param array<string,string|bool> $context `category`, `channel`, `sourceApp`, `correlationId`, and `probe` (true writes nothing).
	 * @param array<string,mixed> $detail What else there is to say.
	 *
	 * @return void
	 */
	private function append(string $kind, string $address, array $context, array $detail): void {
		if (($context['probe'] ?? false) === true) {
			// A probe asked nothing that will be sent, so nothing is logged.
			return;
		}

		$entry = new OptOutLogEntry();
		$entry->setAt($this->time->getTime());
		$entry->setKind($kind);
		$entry->setAddress($address);
		$entry->setCategory((string)($context['category'] ?? ''));
		$entry->setChannel((string)($context['channel'] ?? ''));
		$entry->setSourceApp((string)($context['sourceApp'] ?? ''));
		$entry->setCorrelationId((string)($context['correlationId'] ?? ''));
		$entry->setDetail((string)json_encode($detail));
		$this->log->append(entry: $entry);

	}//end append()

}//end class
