<?php

/**
 * Contract of the SubscriptionSigningPolicy.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Subscriptions
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

namespace OCA\Integriq\Service\Subscriptions;

use DateTimeImmutable;

/**
 * Public operations of {@see SubscriptionSigningPolicy} that other components call.
 */
interface SubscriptionSigningPolicyInterface {
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
	 * Why this subscription may not be saved, if it may not.
	 *
	 * @param array<string, mixed> $subscription The subscription being saved.
	 *
	 * @return string|null The refusal, or null when it may be saved.
	 *
	 * @spec openspec/specs/webhook-signing/spec.md
	 */
	public function refuse(array $subscription): ?string;

	/**
	 * The protocol settings a NEW push subscription is saved with.
	 *
	 * @param array<string, mixed>   $subscription The subscription being created.
	 * @param string                 $user         Who is creating it.
	 * @param DateTimeImmutable|null $now          The moment, for a frozen clock.
	 *
	 * @return array<string, mixed> The settings to store.
	 *
	 * @spec openspec/specs/webhook-signing/spec.md
	 */
	public function settingsForNew(array $subscription, string $user = '', ?DateTimeImmutable $now = null): array;

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
	 *
	 * @spec openspec/specs/webhook-signing/spec.md
	 */
	public function settingsForExisting(
		array $existing,
		array $incoming,
		string $user = '',
		?DateTimeImmutable $now = null
	): array;

	/**
	 * Whether this subscription signs its deliveries.
	 *
	 * @param array<string, mixed> $subscription The subscription as stored.
	 *
	 * @return bool True when a delivery carries a signature.
	 *
	 * @spec openspec/specs/webhook-signing/spec.md
	 */
	public function isSigned(array $subscription): bool;

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
	 *
	 * @spec openspec/specs/webhook-signing/spec.md
	 */
	public function forReading(array $subscription): array;

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
	 *
	 * @spec openspec/specs/webhook-signing/spec.md
	 */
	public function attemptRecord(array $subscription, string $kind = 'immediate'): array;

	/**
	 * Whether a create request leaves the secret to the default, so there is one to reveal.
	 *
	 * A caller that supplied its own secret already has it, and an unsigned
	 * one has none.
	 *
	 * @param array<string, mixed> $subscription The create request.
	 *
	 * @return bool True when the create generates a secret.
	 *
	 * @spec openspec/specs/webhook-signing/spec.md#requirement-a-push-subscription-is-signed-unless-somebody-says-otherwise-req-sow-001
	 */
	public function generatesSecret(array $subscription): bool;

	/**
	 * The one reveal of a stored secret, for the create response only.
	 *
	 * @param array<string, mixed> $stored The subscription as stored, unrendered.
	 *
	 * @return array<string, string> `['signingSecret' => ...]`, or nothing when there is none.
	 *
	 * @spec openspec/specs/webhook-signing/spec.md#requirement-a-push-subscription-is-signed-unless-somebody-says-otherwise-req-sow-001
	 */
	public function reveal(array $stored): array;

	/**
	 * The recipe a receiver needs, shown whether or not the secret is revealed.
	 *
	 * Shown ALWAYS, because the person integrating the receiving end is usually
	 * not the person who created the subscription and will never see the secret
	 * reveal. A recipe that appears only at creation is a recipe nobody reads.
	 *
	 * @return array<string, mixed> The verification recipe.
	 *
	 * @spec openspec/specs/webhook-signing/spec.md
	 */
	public function verificationRecipe(): array;

	/**
	 * A fresh signing secret, for a first generate or a rotation.
	 *
	 * @return string The new secret.
	 *
	 * @spec openspec/specs/webhook-signing/spec.md
	 */
	public function generateSecret(): string;
}
