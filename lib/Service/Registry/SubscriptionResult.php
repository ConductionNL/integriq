<?php

/**
 * The outcome of asking a registry for a subscription.
 *
 * @category ValueObject
 * @package  OCA\Integriq\Service\Registry
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

namespace OCA\Integriq\Service\Registry;

/**
 * A subscribe either takes or fails, and a failure carries the source's own
 * words. There is no silent third state.
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */
final class SubscriptionResult {
	/**
	 * The registry accepted the subscription.
	 */
	public const STATE_ACTIVE = 'active';

	/**
	 * The registry refused, and said why.
	 */
	public const STATE_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param string $state One of the STATE_* constants.
	 * @param string $identity The identity value subscribed to.
	 * @param string $error The source's error text, empty when active.
	 * @param string $reference The registry's own subscription reference, when it gives one.
	 */
	private function __construct(
		private readonly string $state,
		private readonly string $identity,
		private readonly string $error = '',
		private readonly string $reference = '',
	) {
	}//end __construct()

	/**
	 * The registry accepted.
	 *
	 * @param string $identity The identity value.
	 * @param string $reference The registry's subscription reference.
	 *
	 * @return self An active result.
	 */
	public static function active(string $identity, string $reference = ''): self {
		return new self(self::STATE_ACTIVE, $identity, '', $reference);
	}//end active()

	/**
	 * The registry refused, in its own words.
	 *
	 * @param string $identity The identity value.
	 * @param string $error The source's error text.
	 *
	 * @return self A failed result.
	 */
	public static function failed(string $identity, string $error): self {
		return new self(self::STATE_FAILED, $identity, $error);
	}//end failed()

	/**
	 * The state this subscription is in.
	 *
	 * @return string One of the STATE_* constants.
	 */
	public function getState(): string {
		return $this->state;
	}//end getState()

	/**
	 * The identity value subscribed to.
	 *
	 * @return string Identity value.
	 */
	public function getIdentity(): string {
		return $this->identity;
	}//end getIdentity()

	/**
	 * The source's error text.
	 *
	 * @return string Error text, empty when active.
	 */
	public function getError(): string {
		return $this->error;
	}//end getError()

	/**
	 * The registry's own subscription reference.
	 *
	 * @return string Reference, empty when the registry gives none.
	 */
	public function getReference(): string {
		return $this->reference;
	}//end getReference()

	/**
	 * Whether the subscription is live.
	 *
	 * @return bool True when the state is active.
	 */
	public function isActive(): bool {
		return $this->state === self::STATE_ACTIVE;
	}//end isActive()

	/**
	 * The result as it is reported back to OpenRegister.
	 *
	 * @return array<string,mixed> Serialisable result.
	 */
	public function toArray(): array {
		return [
			'state' => $this->state,
			'identity' => $this->identity,
			'error' => $this->error,
			'reference' => $this->reference,
		];
	}//end toArray()
}//end class
