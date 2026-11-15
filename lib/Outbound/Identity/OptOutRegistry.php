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
use OCA\Integriq\Db\OptOutLogMapperInterface;
use OCA\Integriq\Db\OptOutMapperInterface;
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
class OptOutRegistry implements OptOutRegistryInterface {

	/**
	 * The OpenRegister schema opt-outs were stored under before the table.
	 * Read-only history: only MigrateOptOutsToTable reads it.
	 *
	 * @var string
	 */
	public const SCHEMA = 'recipient_opt_out';

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
	 * @param OptOutMapperInterface $mapper Reads and writes the opt-out table.
	 * @param IAppConfig $appConfig Holds the authority flag.
	 * @param ITimeFactory $time Stamps rows and log entries.
	 * @param OptOutCategories $categories The exempt floor and the aliases.
	 * @param RecipientKey $recipientKey Normalises a recipient per channel.
	 * @param OptOutLogMapperInterface $log The append-only decision log.
	 * @param UnsubscribeTokenService $tokens Builds the unsubscribe material.
	 * @param LoggerInterface $logger Warns about failures that are answered closed.
	 * @param OptOutRowBuilder $rows Builds and changes rows for record().
	 */
	public function __construct(
		private readonly OptOutMapperInterface $mapper,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly OptOutCategories $categories,
		private readonly RecipientKey $recipientKey,
		private readonly OptOutLogMapperInterface $log,
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
		$run = new SendDecisionRun(
			channel: $channel,
			category: $this->categories->canonical(category: $category, inReplyTo: $inReplyTo, sourceApp: $sourceApp)
		);
		$run->sourceApp = $sourceApp;
		$run->correlationId = $correlationId;
		$run->probe = $probe;
		$run->requiresConsent = $requiresConsent;
		$run->baseUrl = $baseUrl;

		$decisions = [];
		foreach (array_chunk($recipients, self::CHUNK) as $chunk) {
			$decisions += $this->decideChunk(run: $run, chunk: $chunk);
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
		$send = new OwnSendRequest(channel: $channel, category: $category);
		$send->recipients = $recipients;
		$send->withOptions(options: $options);

		return $this->decideOwnSend(send: $send);

	}//end decideForSend()

	/**
	 * Decide an own sender's request: all allowed when the check is off,
	 * otherwise from the list, and without the list when it cannot be read.
	 *
	 * @param OwnSendRequest $send The request.
	 *
	 * @return array<string,array<string,mixed>> One decision per recipient, keyed by the address as given.
	 */
	private function decideOwnSend(OwnSendRequest $send): array {
		if ($this->isAuthorityEnabled() === false) {
			return $this->decideWithAuthorityOff(send: $send);
		}

		try {
			return $this->decideListed(send: $send);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[OptOutRegistry] the opt-out list could not be read; non-exempt sends are refused',
				['channel' => $send->channel, 'category' => $send->category, 'sourceApp' => $send->sourceApp, 'exception' => $exception->getMessage()]
			);
		}

		return $this->decideWithoutList(category: $send->category, recipients: $send->recipients);

	}//end decideOwnSend()

	/**
	 * Decide an own sender's request from the opt-out list.
	 *
	 * @param OwnSendRequest $send The request.
	 *
	 * @return array<string,array<string,mixed>> One decision per recipient, keyed by the address as given.
	 */
	private function decideListed(OwnSendRequest $send): array {
		return $this->decideMany(
			channel: $send->channel,
			category: $send->category,
			requiresConsent: (bool)($send->options['requiresConsent'] ?? false),
			recipients: $send->recipients,
			sourceApp: $send->sourceApp,
			correlationId: (string)($send->options['correlationId'] ?? ''),
			baseUrl: (string)($send->options['baseUrl'] ?? ''),
			inReplyTo: $send->inReplyTo()
		);

	}//end decideListed()

	/**
	 * Allow every recipient without a link: the check is turned off (the rollback).
	 *
	 * @param OwnSendRequest $send The request.
	 *
	 * @return array<string,array<string,mixed>> One decision per recipient, keyed by the address as given.
	 */
	private function decideWithAuthorityOff(OwnSendRequest $send): array {
		$template = (new SendDecision(address: '', category: $send->category))->because(
			code: self::CODE_ALLOWED,
			reason: 'The opt-out check is turned off on this instance (' . self::CONFIG_AUTHORITY . ').'
		);

		return $this->uniform(recipients: $send->recipients, template: $template);

	}//end decideWithAuthorityOff()

	/**
	 * Decide when the opt-out list cannot be read: an exempt category is sent,
	 * everything else is refused.
	 *
	 * @param string                    $category   The category.
	 * @param list<array<string,mixed>> $recipients The recipients.
	 *
	 * @return array<string,array<string,mixed>> One decision per recipient, keyed by the address as given.
	 */
	private function decideWithoutList(string $category, array $recipients): array {
		$template = new SendDecision(address: '', category: $category);
		if ($this->categories->isExempt($category) === true) {
			$template->because(code: self::CODE_ALLOWED, reason: 'Category "' . $category . '" is sent without the opt-out list.');

			return $this->uniform(recipients: $recipients, template: $template);
		}

		$template->because(code: self::CODE_AUTHORITY_UNAVAILABLE, reason: 'The opt-out list could not be read, so this message was not sent.');

		return $this->uniform(recipients: $recipients, template: $template);

	}//end decideWithoutList()

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
			return $this->eraseRequestedContact(request: $request);
		}

		$existingId = $this->legacyRecordId(request: $request);
		if ($existingId !== null) {
			return $existingId;
		}

		[$stored, $previous] = $this->upsert(row: $this->rows->rowFor(request: $request));

		$this->append(
			kind: OptOutLogEntry::KIND_CHANGE,
			address: (string)$stored->getAddress(),
			context: $this->changeContext(stored: $stored, request: $request),
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
	 * Erase the contact a request names.
	 *
	 * @param array<string,mixed> $request The erasure request: `contactRef`, `sourceApp`, `correlationId`.
	 *
	 * @return int How many rows were cleared.
	 *
	 * @throws InvalidArgumentException When there is no contact ref.
	 */
	private function eraseRequestedContact(array $request): int {
		return $this->eraseContact(
			contactRef: (string)($request['contactRef'] ?? ''),
			sourceApp: (string)($request['sourceApp'] ?? ''),
			correlationId: (string)($request['correlationId'] ?? '')
		);

	}//end eraseRequestedContact()

	/**
	 * The id of the row a request's `legacyRef` already wrote, if any.
	 *
	 * @param array<string,mixed> $request The request.
	 *
	 * @return int|null The earlier row's id, or null when there is no such row.
	 */
	private function legacyRecordId(array $request): ?int {
		$legacyRef = trim((string)($request['legacyRef'] ?? ''));
		if ($legacyRef === '') {
			return null;
		}

		$existing = $this->mapper->findByLegacyRef(legacyRef: $legacyRef);
		if ($existing === null) {
			return null;
		}

		return (int)$existing->getId();

	}//end legacyRecordId()

	/**
	 * Store a row on its dedupe key: change the stored row, or insert it.
	 *
	 * @param OptOut $row The row the request describes.
	 *
	 * @return array{0:OptOut,1:string} The stored row and the state it had before, or empty when new.
	 */
	private function upsert(OptOut $row): array {
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

		return [$stored, $previous];

	}//end upsert()

	/**
	 * The log context of a recorded change.
	 *
	 * @param OptOut              $stored  The stored row.
	 * @param array<string,mixed> $request The request.
	 *
	 * @return array<string,string> `category`, `channel`, `sourceApp`, `correlationId`.
	 */
	private function changeContext(OptOut $stored, array $request): array {
		return [
			'category' => '',
			'channel' => (string)$stored->getChannel(),
			'sourceApp' => (string)($request['sourceApp'] ?? ''),
			'correlationId' => (string)($request['correlationId'] ?? ''),
		];

	}//end changeContext()

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
	 * @param SendDecisionRun           $run   The batch.
	 * @param list<array<string,mixed>> $chunk The recipients.
	 *
	 * @return array<string,array<string,mixed>> The decisions.
	 */
	private function decideChunk(SendDecisionRun $run, array $chunk): array {
		$addressed = $this->addressChunk(run: $run, chunk: $chunk);
		$run->rows = $this->readChunkRows(run: $run, addressed: $addressed);

		$decisions = [];
		$allowed = 0;
		foreach ($addressed as $one) {
			$decision = $this->decideOne(run: $run, addressed: $one);
			if ($decision['code'] === self::CODE_ALLOWED) {
				$allowed++;
			}

			$decisions[(string)($one->recipient['address'] ?? '')] = $decision;
		}

		if ($allowed > 0) {
			$this->append(kind: OptOutLogEntry::KIND_ALLOWED_COUNT, address: '', context: $run->logContext(), detail: ['count' => $allowed]);
		}

		return $decisions;

	}//end decideChunk()

	/**
	 * Normalise every address of a chunk for the batch's channel, in order.
	 *
	 * @param SendDecisionRun           $run   The batch.
	 * @param list<array<string,mixed>> $chunk The recipients.
	 *
	 * @return list<AddressedRecipient> The recipients with their normalised addresses.
	 */
	private function addressChunk(SendDecisionRun $run, array $chunk): array {
		$addressed = [];
		foreach ($chunk as $recipient) {
			$key = $this->recipientKey->normalise(
				channel: $run->channel,
				address: (string)($recipient['address'] ?? '')
			);
			$addressed[] = new AddressedRecipient(key: $key, recipient: $recipient);
		}

		return $addressed;

	}//end addressChunk()

	/**
	 * Read the rows of a chunk by address and by contact; a reply reads none.
	 *
	 * @param SendDecisionRun          $run       The batch.
	 * @param list<AddressedRecipient> $addressed The chunk's recipients with their normalised addresses.
	 *
	 * @return list<OptOut> The rows.
	 */
	private function readChunkRows(SendDecisionRun $run, array $addressed): array {
		if ($run->category === OptOutCategories::REPLY) {
			return [];
		}

		return $this->readRows(
			keys: array_values(array_filter(array_map(static fn (AddressedRecipient $one): ?string => $one->key, $addressed))),
			contacts: array_map(static fn (AddressedRecipient $one): string => (string)($one->recipient['contactRef'] ?? ''), $addressed)
		);

	}//end readChunkRows()

	/**
	 * Decide one recipient against the rows already read.
	 *
	 * @param SendDecisionRun    $run       The batch, with the rows of the chunk.
	 * @param AddressedRecipient $addressed The recipient and its normalised address.
	 *
	 * @return array<string,mixed> The decision: send, overridden, code, reason, unsubscribe, address, category.
	 */
	private function decideOne(SendDecisionRun $run, AddressedRecipient $addressed): array {
		if ($addressed->key === null) {
			$reason = 'This address cannot be used on the "' . $run->channel . '" channel.';
			$refusal = $run->decisionFor(addressed: $addressed)->because(code: self::CODE_INVALID_ADDRESS, reason: $reason);
			return $this->suppress(run: $run, decision: $refusal);
		}

		if ($run->category === OptOutCategories::REPLY) {
			$reason = 'A direct reply is sent whatever the opt-outs say.';
			return $this->decision(decision: $run->decisionFor(addressed: $addressed)->because(code: self::CODE_ALLOWED, reason: $reason));
		}

		$this->matchRecipient(run: $run, addressed: $addressed);
		if ($this->categories->isExempt($run->category) === true) {
			return $this->decideExempt(run: $run, addressed: $addressed);
		}

		return $this->decideStoppable(run: $run, addressed: $addressed);

	}//end decideOne()

	/**
	 * Find a usable recipient's own rows and the opt-out among them that stops this send.
	 *
	 * @param SendDecisionRun    $run       The batch, with the rows of the chunk.
	 * @param AddressedRecipient $addressed The recipient, with a normalised address; its rows and opt-out are filled in.
	 *
	 * @return void
	 */
	private function matchRecipient(SendDecisionRun $run, AddressedRecipient $addressed): void {
		$addressed->mine = $this->matcher->rowsOf(run: $run, addressed: $addressed);
		$addressed->optOut = $this->matcher->matchingOptOut(run: $run, addressed: $addressed);

	}//end matchRecipient()

	/**
	 * Decide an exempt category: always sent, logged as an override when an opt-out matched.
	 *
	 * @param SendDecisionRun    $run       The batch.
	 * @param AddressedRecipient $addressed The recipient's rows and its stopping opt-out, if any.
	 *
	 * @return array<string,mixed> The decision.
	 */
	private function decideExempt(SendDecisionRun $run, AddressedRecipient $addressed): array {
		$optOut = $addressed->optOut;
		if ($optOut === null) {
			return $this->decision(decision: $run->decisionFor(addressed: $addressed)->because(code: self::CODE_ALLOWED, reason: ''));
		}

		$detail = ['scope' => (string)$optOut->getScope(), 'optOutId' => $optOut->getId()];
		$this->append(kind: OptOutLogEntry::KIND_OVERRIDE, address: (string)$addressed->key, context: $run->logContext(), detail: $detail);
		$reason = 'Category "' . $run->category . '" cannot be stopped by an opt-out.';
		$decision = $run->decisionFor(addressed: $addressed)->because(code: self::CODE_EXEMPT_OVERRIDE, reason: $reason);
		$decision->overridden = true;
		return $this->decision(decision: $decision);

	}//end decideExempt()

	/**
	 * Decide a category an opt-out can stop: refused when opted out or when
	 * required consent is missing, otherwise allowed.
	 *
	 * @param SendDecisionRun    $run       The batch.
	 * @param AddressedRecipient $addressed The recipient's rows and its stopping opt-out, if any.
	 *
	 * @return array<string,mixed> The decision.
	 */
	private function decideStoppable(SendDecisionRun $run, AddressedRecipient $addressed): array {
		$optOut = $addressed->optOut;
		if ($optOut !== null) {
			$reason = 'This address opted out (' . $optOut->getScope() . ').';
			$decision = $run->decisionFor(addressed: $addressed)->because(code: self::CODE_OPTED_OUT, reason: $reason);
			$decision->logDetail = ['scope' => (string)$optOut->getScope()];
			return $this->suppress(run: $run, decision: $decision);
		}

		if ($this->consentPermits(run: $run, addressed: $addressed) === false) {
			$reason = 'No recorded consent permits this message.';
			$refusal = $run->decisionFor(addressed: $addressed)->because(code: self::CODE_NO_CONSENT, reason: $reason);
			return $this->suppress(run: $run, decision: $refusal);
		}

		return $this->allow(run: $run, addressed: $addressed);

	}//end decideStoppable()

	/**
	 * Whether the send needs no consent or a recorded consent permits it.
	 *
	 * @param SendDecisionRun    $run       The batch.
	 * @param AddressedRecipient $addressed The recipient's rows.
	 *
	 * @return bool True when consent is not required or is recorded.
	 */
	private function consentPermits(SendDecisionRun $run, AddressedRecipient $addressed): bool {
		return $run->requiresConsent === false
			|| $this->matcher->hasConsent(run: $run, addressed: $addressed) === true;

	}//end consentPermits()

	/**
	 * Allow a send, with unsubscribe material unless the batch is a probe.
	 *
	 * @param SendDecisionRun    $run       The batch.
	 * @param AddressedRecipient $addressed The allowed recipient.
	 *
	 * @return array<string,mixed> The decision.
	 */
	private function allow(SendDecisionRun $run, AddressedRecipient $addressed): array {
		$decision = $run->decisionFor(addressed: $addressed)->because(code: self::CODE_ALLOWED, reason: '');
		if ($run->probe === true) {
			// A probe only shows a state: no token, no short link.
			return $this->decision(decision: $decision);
		}

		$decision->unsubscribe = $this->material(run: $run, addressed: $addressed);

		return $this->decision(decision: $decision);

	}//end allow()

	/**
	 * Refuse one recipient and log the suppression.
	 *
	 * @param SendDecisionRun $run The batch.
	 * @param SendDecision $decision The refusal, with its code, reason and log detail.
	 *
	 * @return array<string,mixed> The decision.
	 */
	private function suppress(SendDecisionRun $run, SendDecision $decision): array {
		$detail = ['code' => $decision->code] + $decision->logDetail;
		$this->append(kind: OptOutLogEntry::KIND_SUPPRESSED, address: $decision->address, context: $run->logContext(), detail: $detail);

		return $this->decision(decision: $decision);

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
	 * @param SendDecisionRun    $run       The batch.
	 * @param AddressedRecipient $addressed The allowed recipient.
	 *
	 * @return array<string,mixed>|null The material.
	 */
	private function material(SendDecisionRun $run, AddressedRecipient $addressed): ?array {
		$channel = $run->channel;
		$recipient = $addressed->recipient;
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
			address: (string)$addressed->key,
			scope: $scope,
			channel: $channel,
			ref: $ref,
			category: $run->category,
			baseUrl: $run->baseUrl
		);

	}//end material()

	/**
	 * One decision.
	 *
	 * @param SendDecision $decision The decision; whether it sends follows from its code.
	 *
	 * @return array<string,mixed> The decision: send, overridden, code, reason, unsubscribe, address, category.
	 */
	private function decision(SendDecision $decision): array {
		return $decision->toArray(send: in_array($decision->code, [self::CODE_ALLOWED, self::CODE_EXEMPT_OVERRIDE], true));

	}//end decision()

	/**
	 * The same decision for every recipient, with no table read.
	 *
	 * @param list<array<string,mixed>> $recipients The recipients.
	 * @param SendDecision $template The decision, copied per recipient address.
	 *
	 * @return array<string,array<string,mixed>> The decisions.
	 */
	private function uniform(array $recipients, SendDecision $template): array {
		$decisions = [];
		foreach ($recipients as $recipient) {
			$given = (string)($recipient['address'] ?? '');
			$decisions[$given] = $this->decision(decision: $template->forAddress(address: $given));
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
