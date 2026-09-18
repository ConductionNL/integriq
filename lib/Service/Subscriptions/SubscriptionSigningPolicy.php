<?php

/**
 * Whether a push subscription signs its deliveries, and what it takes to say no.
 *
 * 🔴 SIGNED IS THE DEFAULT AND UNSIGNED IS A DECISION SOMEBODY MAKES BY NAME.
 * The opposite default is what most of this fleet's outbound webhooks shipped
 * with: unsigned unless configured, so every receiver that never got round to
 * verification kept working, and nobody found out which ones those were until
 * somebody asked. A receiver cannot tell a request from integriq from a request
 * that says it is from integriq, and neither can integriq's own delivery log.
 *
 * 🔴 `unsigned` WITHOUT A REASON IS REFUSED. An operator turning off signing on
 * a Friday afternoon to make a stubborn receiver work is a completely
 * legitimate thing to do; doing it and leaving nothing to say why is how it is
 * still off two years later, when nobody remembers the receiver was supposed to
 * be fixed. The reason, the user and the time are stored with it.
 *
 * 🔴 AN EXISTING SUBSCRIPTION NEVER GAINS A SECRET ON UPGRADE. Retrofitting one
 * would start sending `X-OpenConnector-Signature` to a receiver that was never
 * given the secret and never asked to verify. The well-behaved ones reject it
 * and the delivery silently starts failing; the rest ignore it, which looks
 * exactly like success while nothing is actually being verified. Either way an
 * operator now believes a channel is signed that is not, which is worse than
 * knowing it is unsigned.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Integriq\Service\Subscriptions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://Integriq.app
 *
 * @spec openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Subscriptions;

use DateTimeImmutable;
use OCA\Integriq\Service\WebhookSignatureService;

/**
 * Decides a push subscription's signing posture, and refuses the requests that hide it.
 */
class SubscriptionSigningPolicy {

	/**
	 * The subscription style this policy applies to.
	 *
	 * @var string
	 */
	public const STYLE_PUSH = 'push';

	/**
	 * What a read of a stored secret shows instead of the secret.
	 *
	 * @var string
	 */
	public const REDACTED = '__redacted__';

	/**
	 * An attempt that carried a signature.
	 *
	 * @var string
	 */
	public const ATTEMPT_SIGNED = 'signed';

	/**
	 * An attempt that did not.
	 *
	 * @var string
	 */
	public const ATTEMPT_UNSIGNED = 'unsigned';

	/**
	 * Wire the policy.
	 *
	 * @param WebhookSignatureService $signatures The existing secret generator and signer.
	 */
	public function __construct(private readonly WebhookSignatureService $signatures) {
	}//end __construct()

	/**
	 * Why this subscription may not be saved, if it may not.
	 *
	 * @param array<string, mixed> $subscription The subscription being saved.
	 *
	 * @return string|null The refusal, or null when it may be saved.
	 */
	public function refuse(array $subscription): ?string {
		if ((string)($subscription['style'] ?? '') !== self::STYLE_PUSH) {
			return null;
		}

		$settings = (array)($subscription['protocolSettings'] ?? []);
		if (array_key_exists('unsigned', $settings) === false) {
			return null;
		}

		$unsigned = (array)($settings['unsigned'] ?? []);
		if (trim((string)($unsigned['reason'] ?? '')) !== '') {
			return null;
		}

		return 'Turning off signing for this subscription needs a reason. Unsigned deliveries cannot be told '
			.'apart from deliveries that only claim to be from here, and without a reason on the record nobody '
			.'later knows whether the receiver was ever meant to be fixed.';
	}//end refuse()

	/**
	 * The protocol settings a NEW push subscription is saved with.
	 *
	 * @param array<string, mixed>   $subscription The subscription being created.
	 * @param string                 $user         Who is creating it.
	 * @param DateTimeImmutable|null $now          The moment, for a frozen clock.
	 *
	 * @return array<string, mixed> The settings to store.
	 */
	public function settingsForNew(array $subscription, string $user = '', ?DateTimeImmutable $now = null): array {
		$settings = (array)($subscription['protocolSettings'] ?? []);

		if ((string)($subscription['style'] ?? '') !== self::STYLE_PUSH) {
			return $settings;
		}

		$moment = ($now ?? new DateTimeImmutable());

		if (array_key_exists('unsigned', $settings) === true) {
			$unsigned = (array)$settings['unsigned'];
			$settings['unsigned'] = [
				'reason' => trim((string)($unsigned['reason'] ?? '')),
				'setBy' => ($unsigned['setBy'] ?? $user),
				'setAt' => $moment->format(DATE_ATOM),
			];
			unset($settings['signingSecret']);

			return $settings;
		}

		$settings['signingSecret'] = $this->signatures->generateSecret();

		return $settings;
	}//end settingsForNew()

	/**
	 * The protocol settings an EXISTING subscription keeps.
	 *
	 * 🔴 IT DOES NOT GAIN A SECRET. An upgrade that retrofits one starts
	 * signing to a receiver that was never given the secret: the strict ones
	 * begin rejecting deliveries, the lax ones ignore the header, and either way
	 * an operator now believes a channel is verified that is not.
	 *
	 * @param array<string, mixed>   $existing The subscription as stored.
	 * @param array<string, mixed>   $incoming The save being applied.
	 * @param string                 $user     Who is saving.
	 * @param DateTimeImmutable|null $now      The moment.
	 *
	 * @return array<string, mixed> The settings to store.
	 */
	public function settingsForExisting(
		array $existing,
		array $incoming,
		string $user = '',
		?DateTimeImmutable $now = null
	): array {
		$stored = (array)($existing['protocolSettings'] ?? []);
		$settings = (array)($incoming['protocolSettings'] ?? []);

		// The stored secret survives a save that does not mention it, so an
		// ordinary edit of a sink or a filter never silently unsigns a channel.
		if (array_key_exists('signingSecret', $stored) === true
			&& array_key_exists('signingSecret', $settings) === false
		) {
			$settings['signingSecret'] = $stored['signingSecret'];
		}

		if (array_key_exists('unsigned', $settings) === true) {
			$unsigned = (array)$settings['unsigned'];
			$settings['unsigned'] = [
				'reason' => trim((string)($unsigned['reason'] ?? '')),
				'setBy' => ($unsigned['setBy'] ?? $user),
				'setAt' => ($now ?? new DateTimeImmutable())->format(DATE_ATOM),
			];
		}

		return $settings;
	}//end settingsForExisting()

	/**
	 * Whether this subscription signs its deliveries.
	 *
	 * @param array<string, mixed> $subscription The subscription as stored.
	 *
	 * @return bool True when a delivery carries a signature.
	 */
	public function isSigned(array $subscription): bool {
		$settings = (array)($subscription['protocolSettings'] ?? []);

		if (array_key_exists('unsigned', $settings) === true) {
			return false;
		}

		return (trim((string)($settings['signingSecret'] ?? '')) !== '');
	}//end isSigned()

	/**
	 * How a subscription reads on a list or a detail page.
	 *
	 * The secret is redacted, and an unsigned subscription is MARKED with its
	 * reason. A list that shows only "push" beside both leaves an operator to
	 * open every subscription in turn to find the unsigned ones, which is the
	 * same as not telling them.
	 *
	 * @param array<string, mixed> $subscription The subscription as stored.
	 *
	 * @return array<string, mixed> The read shape.
	 */
	public function forReading(array $subscription): array {
		$settings = (array)($subscription['protocolSettings'] ?? []);
		$signed = $this->isSigned(subscription: $subscription);

		if (array_key_exists('signingSecret', $settings) === true) {
			$settings['signingSecret'] = self::REDACTED;
		}

		$read = $subscription;
		$read['protocolSettings'] = $settings;
		$read['signingPosture'] = $this->posture(signed: $signed);
		$read['unsignedReason'] = null;

		if ($signed === false && array_key_exists('unsigned', $settings) === true) {
			$read['unsignedReason'] = (string)(((array)$settings['unsigned'])['reason'] ?? '');
		}

		return $read;
	}//end forReading()

	/**
	 * What one delivery attempt records about its own signing.
	 *
	 * 🔴 EVERY ATTEMPT, INCLUDING RETRIES AND OPERATOR REPLAYS. A log that
	 * records it only on the first try cannot answer the one question a
	 * receiver asks after a bad night: "was the request I got at 03:14 signed?"
	 * A replay in particular is the attempt most likely to differ, because it
	 * happens after somebody has been changing the configuration.
	 *
	 * @param array<string, mixed> $subscription The subscription as stored.
	 * @param string               $kind         immediate, retry or replay.
	 *
	 * @return array<string, mixed> What the attempt records.
	 */
	public function attemptRecord(array $subscription, string $kind = 'immediate'): array {
		$signed = $this->isSigned(subscription: $subscription);

		return [
			'kind' => $kind,
			'signed' => $signed,
			'signingPosture' => $this->posture(signed: $signed),
		];
	}//end attemptRecord()

	/**
	 * One posture as the word a surface prints.
	 *
	 * @param bool $signed Whether it signs.
	 *
	 * @return string The word.
	 */
	private function posture(bool $signed): string {
		if ($signed === true) {
			return self::ATTEMPT_SIGNED;
		}

		return self::ATTEMPT_UNSIGNED;
	}//end posture()

	/**
	 * The recipe a receiver needs, shown whether or not the secret is revealed.
	 *
	 * Shown ALWAYS, because the person integrating the receiving end is usually
	 * not the person who created the subscription and will never see the secret
	 * reveal. A recipe that appears only at creation is a recipe nobody reads.
	 *
	 * @return array<string, mixed> The verification recipe.
	 */
	public function verificationRecipe(): array {
		return [
			'header' => 'X-OpenConnector-Signature',
			'valueShape' => 't=<unix-ts>,v1=<hex>',
			'v1' => 'HMAC-SHA256(secret, "<t>." + rawBody)',
			'bodyNote' => 'Computed over the body exactly as received, before any parsing or re-encoding.',
			'toleranceNote' => 'Apply your own timestamp tolerance; integriq does not decide it for you.',
			'rotationNote' => 'During a rotation grace window two v1 pairs appear. Accept the request if either verifies.',
			'graceSeconds' => WebhookSignatureService::ROTATION_GRACE_SECONDS,
		];
	}//end verificationRecipe()
}//end class
