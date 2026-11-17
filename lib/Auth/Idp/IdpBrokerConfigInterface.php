<?php

/**
 * Contract of the IdpBrokerConfig.
 *
 * @category Contract
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

/**
 * Public operations of {@see IdpBrokerConfig} that other components call.
 */
interface IdpBrokerConfigInterface {
	/**
	 * Whether the broker is switched on.
	 *
	 * @return boolean True only when the flag is exactly "1".
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-dormant-seam-adapters-ship-config-flag-gated-and-inert
	 */
	public function isEnabled(): bool;

	/**
	 * The envelope signing key.
	 *
	 * @return string The key, or an empty string when none is configured.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function signingKey(): string;

	/**
	 * One consumer's inline exchange secret, from the older id-to-secret form.
	 *
	 * A consumer in the current form holds its secret by broker reference, so
	 * this answers an empty string for it: {@see IdpConsumerSecretResolver}
	 * reads that one.
	 *
	 * @param string $consumer The consumer id.
	 *
	 * @return string The secret, or an empty string when the consumer is unknown or in the current form.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function consumerSecret(string $consumer): string;

	/**
	 * One registered consumer.
	 *
	 * @param string $consumer The consumer id.
	 *
	 * @return IdpConsumer|null The consumer, or null when it is not registered.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) IdpConsumer::fromConfig is the value object's named constructor.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function consumer(string $consumer): ?IdpConsumer;

	/**
	 * Whether a consumer id is registered at all, in either form.
	 *
	 * @param string $consumer The consumer id.
	 *
	 * @return boolean True when an entry exists.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function hasConsumer(string $consumer): bool;

	/**
	 * Write one consumer in the current form, leaving every other entry as it is.
	 *
	 * @param IdpConsumer $consumer The consumer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function saveConsumer(IdpConsumer $consumer): void;

}
