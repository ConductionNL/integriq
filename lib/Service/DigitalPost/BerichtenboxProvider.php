<?php

/**
 * MijnOverheid Berichtenbox, over the client that already ships.
 *
 * @category Provider
 * @package  OCA\Integriq\Service\DigitalPost
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

namespace OCA\Integriq\Service\DigitalPost;

use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClient;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxException;
use OCA\Integriq\Adapters\Berichtenbox\LetterBuilderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Built on `lib/Adapters/Berichtenbox/BerichtenboxClient.php` rather than a
 * second Berichtenbox client, so there is one code path to keep honest.
 *
 * A send runs in this order: the opt-out list has already spoken
 * (DigitalPostService); the category picks a BerichtType; Logius is asked
 * whether the citizen takes letters from this sender (`not_subscribed`
 * otherwise); the letter is built to the official schema (`invalid_letter`
 * otherwise); then it goes to the ebMS adapter. A result decides the status
 * later. A Berichtenbox letter is never `read`: Logius does not tell.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-one-berichtenbox-code-path-built-on-the-client-that-ships-req-dpa-006
 */
class BerichtenboxProvider implements DigitalPostProviderInterface {
	/**
	 * The provider id a source configuration names.
	 */
	public const ID = 'berichtenbox';

	/**
	 * The refusal code when the citizen does not take letters from this sender.
	 */
	public const CODE_NOT_SUBSCRIBED = 'not_subscribed';

	/**
	 * The BerichtType a mock source sends under when it maps none.
	 */
	private const MOCK_BERICHT_TYPE = 'SIMULATE';

	/**
	 * What to tell the adapter once a status is stored, per provider reference.
	 *
	 * @var array<string,array{kind:string,id:string,event?:string}>
	 */
	private array $pendingAcks = [];

	/**
	 * Constructor.
	 *
	 * @param BerichtenboxClient $client The shipped Berichtenbox client.
	 * @param LoggerInterface $logger Structured logger.
	 * @param LetterBuilderInterface $letters Builds the letter to the Logius schema.
	 */
	public function __construct(
		private readonly BerichtenboxClient $client,
		private readonly LoggerInterface $logger,
		private readonly LetterBuilderInterface $letters,
	) {
	}//end __construct()

	/**
	 * The provider id.
	 *
	 * @return string Provider id.
	 */
	public function getProviderId(): string {
		return self::ID;
	}//end getProviderId()

	/**
	 * What a Berichtenbox source has to be configured with.
	 *
	 * @return array<string,mixed> The configuration schema.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-one-berichtenbox-code-path-built-on-the-client-that-ships-req-dpa-006
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'title' => 'MijnOverheid Berichtenbox',
			'description' => 'Letters to a citizen\'s MijnOverheid Berichtenbox, over Digikoppeling ebMS through an ebMS '
				. 'adapter you run, with the subscription checked over WUS. Needs a Logius aansluiting, a PKIoverheid '
				. 'certificate carrying the sender OIN (uploaded under Administration settings, Integriq, and stored '
				. 'encrypted), and the CPA values Logius gives you.',
			'properties' => [
				'senderOin' => ['type' => 'string', 'title' => 'Sender OIN', 'description' => 'The 20-digit OIN this organisation sends as.'],
				'certificateRef' => [
					'type' => 'string',
					'title' => 'Certificate',
					'description' => 'Set by the certificate upload: the fingerprint of the stored certificate. Never the certificate or its key.',
				],
				'adapterUrl' => [
					'type' => 'string',
					'title' => 'ebMS adapter URL',
					'description' => 'The base of the adapter\'s REST API, for ebms-admin …/service/rest/v19/ebms.',
				],
				'cpaId' => ['type' => 'string', 'title' => 'CPA id'],
				'fromPartyId' => ['type' => 'string', 'title' => 'Your party id', 'description' => 'Defaults to the sender OIN.'],
				'toPartyId' => ['type' => 'string', 'title' => 'Logius party id'],
				'service' => ['type' => 'string', 'title' => 'CPA service'],
				'wusEndpoint' => [
					'type' => 'string',
					'title' => 'Subscription check endpoint',
					'description' => 'The ValidateAbonnementen URL Logius gives you.',
				],
				'berichtTypes' => [
					'type' => 'object',
					'title' => 'BerichtType per category',
					'description' => 'Letter category (besluit, case-update, statutory, service) to the BerichtType code '
						. 'you made in the Leveranciersportaal, at most 8 characters.',
					'additionalProperties' => ['type' => 'string', 'maxLength' => 8],
				],
			],
			'required' => ['certificateRef', 'senderOin'],
		];
	}//end getConfigSchema()

	/**
	 * Why this source cannot be activated as it stands.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,string> The refusals, naming what is missing.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-live-binding-speaks-the-interface-logius-publishes-req-dpa-008
	 */
	public function activationRefusals(array $config): array {
		$refusals = [];

		if (trim((string)($config['certificateRef'] ?? '')) === '') {
			$refusals[] = 'Berichtenbox needs a PKIoverheid certificate. Upload it under Administration settings, Integriq, '
				. 'Berichtenbox; it is stored encrypted and named here by its fingerprint.';
		}

		if (trim((string)($config['senderOin'] ?? '')) === '') {
			$refusals[] = 'Berichtenbox needs a sender OIN. Configure "senderOin" with the number this '
				. 'instance is registered under.';
		}

		return array_merge($refusals, $this->client->configurationRefusals($config));
	}//end activationRefusals()

	/**
	 * Send one letter.
	 *
	 * @param array<string,mixed> $message The message.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return DigitalPostResult What Logius answered, or the refusal.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-subscription-is-checked-before-every-send-req-dpa-009
	 */
	public function send(array $message, array $config = []): DigitalPostResult {
		$refusal = $this->unsendable(message: $message, config: $config);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->deliverLetter(message: $message, config: $config);
		} catch (BerichtenboxException $e) {
			$this->logger->warning('digital-post.berichtenbox.send-refused', ['code' => $e->getReason(), 'error' => $e->getMessage()]);

			return DigitalPostResult::refused($e->getMessage(), $e->getReason());
		} catch (Throwable $e) {
			$this->logger->warning('digital-post.berichtenbox.send-failed', ['error' => $e->getMessage()]);

			return DigitalPostResult::refused($e->getMessage(), BerichtenboxException::CODE_TRANSPORT);
		}//end try
	}//end send()

	/**
	 * Why a letter cannot be sent from this source at all: it is not activated, or the letter's category has no BerichtType.
	 *
	 * @param array<string,mixed> $message The letter.
	 * @param array<string,mixed> $config  The source configuration.
	 *
	 * @return DigitalPostResult|null The refusal, or null when the letter may be sent.
	 */
	private function unsendable(array $message, array $config): ?DigitalPostResult {
		$refusals = $this->activationRefusals(config: $config);
		if ($refusals !== []) {
			return DigitalPostResult::refused(implode(' ', $refusals), BerichtenboxException::CODE_NOT_CONFIGURED);
		}

		$category = $this->letterCategory(message: $message);
		if ($this->berichtType(category: $category, config: $config) === '') {
			return DigitalPostResult::refused(
				sprintf('No BerichtType is configured for letters of category "%s". Map it under "berichtTypes". Nothing was sent.', $category),
				BerichtenboxException::CODE_NOT_CONFIGURED
			);
		}

		return null;

	}//end unsendable()

	/**
	 * The letter's category; `service` when it names none.
	 *
	 * @param array<string,mixed> $message The letter.
	 *
	 * @return string The category.
	 */
	private function letterCategory(array $message): string {
		return (string)($message['category'] ?? 'service');

	}//end letterCategory()

	/**
	 * Check the citizen's subscription, then build and deliver the letter.
	 *
	 * @param array<string,mixed> $message The letter.
	 * @param array<string,mixed> $config  The source configuration.
	 *
	 * @return DigitalPostResult Sent, or refused when the citizen takes no Berichtenbox letters from this organisation.
	 *
	 * @throws BerichtenboxException When the subscription check, the letter or the delivery is refused.
	 */
	private function deliverLetter(array $message, array $config): DigitalPostResult {
		$berichtType = $this->berichtType(category: $this->letterCategory(message: $message), config: $config);
		$simulated = ($this->client->flavour() === 'mock');
		$bsn = (string)($message['recipient'] ?? '');

		$subscribed = $this->client->checkSubscriptions([$bsn], $berichtType, $config);
		if (($subscribed[$bsn] ?? false) !== true) {
			return DigitalPostResult::refused(
				'This citizen does not take Berichtenbox letters from this organisation, or has no active Berichtenbox. Nothing was sent.',
				self::CODE_NOT_SUBSCRIBED
			);
		}

		$batch = $this->letters->build(message: $message, senderOin: (string)$config['senderOin'], berichtType: $berichtType);
		$transportMessageId = $this->client->deliver($batch, $config);

		return DigitalPostResult::accepted(
			DigitalPostResult::STATUS_SENT,
			$batch->berichtId,
			$simulated,
			['batchId' => $batch->batchId, 'transportMessageId' => $transportMessageId, 'berichtType' => $berichtType]
		);

	}//end deliverLetter()

	/**
	 * What Logius answered for this letter, so far.
	 *
	 * @param string $providerReference The BerichtID.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return DigitalPostResult The status: `sent` while nothing decided, `delivered` or `failed` once something did.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-logius-results-decide-the-status-and-a-berichtenbox-letter-is-never-read-req-dpa-011
	 */
	public function status(string $providerReference, array $config = []): DigitalPostResult {
		$letter = new LetterStatusLookup(providerReference: $providerReference, config: $config);
		$result = ($this->client->results($config)[$letter->reference()] ?? null);
		if ($result !== null) {
			$this->holdResultAck(letter: $letter, result: $result);
			return $this->resultStatus(letter: $letter, result: $result);
		}

		return $this->transportStatus(letter: $letter);
	}//end status()

	/**
	 * Remember to acknowledge the result once the status is stored, and its transport event with it when there is one.
	 *
	 * The transport event for this letter goes with its result, so nothing is
	 * left waiting at the adapter.
	 *
	 * @param LetterStatusLookup  $letter The letter.
	 * @param array<string,mixed> $result Logius' result for it.
	 *
	 * @return void
	 */
	private function holdResultAck(LetterStatusLookup $letter, array $result): void {
		$reference = $letter->reference();
		$transportMessageId = $this->client->transportMessageIdFor(berichtId: $reference);
		$this->pendingAcks[$reference] = ['kind' => 'result', 'id' => $result['resultMessageId']];
		if (isset($this->client->transportEvents($letter->config)[$transportMessageId]) === true) {
			$this->pendingAcks[$reference]['event'] = $transportMessageId;
		}

	}//end holdResultAck()

	/**
	 * The status Logius' result decides: delivered when processed, else failed with its code and stage.
	 *
	 * @param LetterStatusLookup  $letter The letter.
	 * @param array<string,mixed> $result Logius' result for it.
	 *
	 * @return DigitalPostResult The status.
	 */
	private function resultStatus(LetterStatusLookup $letter, array $result): DigitalPostResult {
		$extra = ['resultCode' => $result['code'], 'resultStage' => $result['stadium']];
		if ($result['code'] === 'Verwerkt') {
			return DigitalPostResult::accepted(DigitalPostResult::STATUS_DELIVERED, $letter->providerReference, $this->isSimulated(), $extra);
		}

		$stage = $result['stadium'];
		if ($stage === '') {
			$stage = 'unknown';
		}

		return DigitalPostResult::refused(
			sprintf('Logius did not place the letter: %s (stage %s).', $result['code'], $stage),
			'logius_' . $result['code'],
			$letter->providerReference,
			$extra
		);

	}//end resultStatus()

	/**
	 * The status while Logius has not decided: failed when the transport failed or expired, else still sent.
	 *
	 * @param LetterStatusLookup $letter The letter.
	 *
	 * @return DigitalPostResult The status.
	 */
	private function transportStatus(LetterStatusLookup $letter): DigitalPostResult {
		$transportMessageId = $this->client->transportMessageIdFor(berichtId: $letter->reference());
		$event = ($this->client->transportEvents($letter->config)[$transportMessageId] ?? null);
		if ($event === 'FAILED' || $event === 'EXPIRED') {
			$this->pendingAcks[$letter->reference()] = ['kind' => 'event', 'id' => $transportMessageId];

			return DigitalPostResult::refused(
				sprintf('The letter was not delivered to Logius: the ebMS adapter reports %s.', $event),
				'transport_' . strtolower($event),
				$letter->providerReference
			);
		}

		if ($event === 'DELIVERED') {
			// Logius acknowledged the batch; the letter stays `sent` until a
			// result arrives, so the event can go now.
			$this->client->eventProcessed($transportMessageId, $letter->config);
		}

		return DigitalPostResult::accepted(DigitalPostResult::STATUS_SENT, $letter->providerReference, $this->isSimulated());

	}//end transportStatus()

	/**
	 * Whether this source runs against the mock: its letters are simulated.
	 *
	 * @return bool True for the mock.
	 */
	private function isSimulated(): bool {
		return ($this->client->flavour() === 'mock');

	}//end isSimulated()

	/**
	 * The status for this letter is stored: let the adapter drop what it reported.
	 *
	 * @param string $providerReference The BerichtID.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#scenario-a-result-is-not-lost-when-saving-fails
	 */
	public function statusRecorded(string $providerReference, array $config): void {
		$reference = strtolower($providerReference);
		$pending = ($this->pendingAcks[$reference] ?? null);
		if ($pending === null) {
			return;
		}

		unset($this->pendingAcks[$reference]);
		if ($pending['kind'] === 'result') {
			$this->client->resultProcessed($pending['id'], $config);
			if (isset($pending['event']) === true) {
				$this->client->eventProcessed($pending['event'], $config);
			}

			return;
		}

		$this->client->eventProcessed($pending['id'], $config);
	}//end statusRecorded()

	/**
	 * Everything that arrived for this instance.
	 *
	 * The Berichtenbox has no direction from citizen to organisation
	 * (aansluithandleiding section 2), so a live source has nothing. The mock
	 * keeps its fixture so the intake path can still be exercised.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,array<string,mixed>> The inbound items.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-a-live-berichtenbox-source-has-no-inbound-post-req-dpa-012
	 */
	public function pollInbound(array $config = []): array {
		if ($this->client->flavour() !== 'mock') {
			return [];
		}

		$items = [];
		foreach ((array)($config['inboundFixture'] ?? []) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$items[] = [
				'sender' => (string)($item['sender'] ?? ''),
				'subject' => (string)($item['subject'] ?? ''),
				'document' => ($item['document'] ?? []),
				'receivedAt' => (string)($item['receivedAt'] ?? gmdate('c')),
			];
		}

		return $items;
	}//end pollInbound()

	/**
	 * The BerichtType for a letter category.
	 *
	 * @param string $category The category.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return string The code, empty when none is configured.
	 */
	private function berichtType(string $category, array $config): string {
		$types = ($config['berichtTypes'] ?? []);
		$type = '';
		if (is_array($types) === true) {
			$type = trim((string)($types[$category] ?? ''));
		}

		if ($type === '' && $this->client->flavour() === 'mock') {
			return self::MOCK_BERICHT_TYPE;
		}

		return $type;
	}//end berichtType()
}//end class
