<?php

/**
 * Integriq RecipientKey.
 *
 * Turns a recipient, as a sender names it, into the key the opt-out table is
 * matched on. An email address is trimmed and lower cased. A phone number
 * goes to E.164 through PhoneNumberValidator, so `0612345678` and
 * `+31 6 12345678` are one person. A digital post recipient is a BSN, and a
 * BSN has no business in an opt-out table: it is stored as `bsn:` plus an
 * HMAC-SHA256 under an instance secret (approved by Ruben on 2026-10-05). The
 * lookup is an equality match, so the hash works and the BSN never lands in
 * the table, the log or the unsubscribe token.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-digital-post-recipient-is-never-stored-as-a-plain-bsn-req-ooa-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use OCA\Integriq\Service\Sms\PhoneNumberNormaliserInterface;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Normalises a recipient per channel.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-digital-post-recipient-is-never-stored-as-a-plain-bsn-req-ooa-008
 */
class RecipientKey implements RecipientKeyInterface {

	/**
	 * WhatsApp.
	 *
	 * @var string
	 */
	public const CHANNEL_WHATSAPP = 'whatsapp';

	/**
	 * Digital post: Berichtenbox, Postex.
	 *
	 * @var string
	 */
	public const CHANNEL_DIGITAL_POST = 'digital-post';

	/**
	 * The channels whose recipient is a phone number.
	 *
	 * @var array<int,string>
	 */
	public const PHONE_CHANNELS = [self::CHANNEL_SMS, self::CHANNEL_WHATSAPP, self::CHANNEL_MESSAGING];

	/**
	 * The prefix of a hashed BSN.
	 *
	 * @var string
	 */
	public const BSN_PREFIX = 'bsn:';

	/**
	 * The prefix of a hashed key that is not a BSN: an erased address in the log.
	 *
	 * @var string
	 */
	public const HASH_PREFIX = 'h:';

	/**
	 * The app-config key holding the BSN hashing secret.
	 *
	 * @var string
	 */
	public const HASHING_CONFIG_NAME = 'outbound.recipient_key_secret';

	/**
	 * The former name of HASHING_CONFIG_NAME.
	 *
	 * @var string
	 *
	 * @deprecated Use HASHING_CONFIG_NAME.
	 */
	public const CONFIG_SECRET = self::HASHING_CONFIG_NAME;

	/**
	 * The hashing secret, read once per instance.
	 *
	 * @var AppSecret
	 */
	private readonly AppSecret $secret;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the hashing secret.
	 * @param ISecureRandom $random Mints the secret the first time one is needed.
	 * @param PhoneNumberNormaliserInterface $phoneNumbers Normalises a phone number to E.164.
	 */
	public function __construct(
		IAppConfig $appConfig,
		ISecureRandom $random,
		private readonly PhoneNumberNormaliserInterface $phoneNumbers,
	) {
		$this->secret = new AppSecret(appConfig: $appConfig, random: $random, configKey: self::HASHING_CONFIG_NAME);

	}//end __construct()

	/**
	 * The key a recipient is matched on, or null when it does not normalise.
	 *
	 * @param string $channel The channel the message travels on, or empty when unknown.
	 * @param string $address The recipient as the sender names it.
	 *
	 * @return string|null The key.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-digital-post-recipient-is-never-stored-as-a-plain-bsn-req-ooa-008
	 */
	public function normalise(string $channel, string $address): ?string {
		$address = trim($address);
		if ($address === '') {
			return null;
		}

		$channel = strtolower(trim($channel));
		if (str_starts_with($address, self::BSN_PREFIX) === true) {
			return $address;
		}

		if ($channel === self::CHANNEL_EMAIL) {
			return $this->email(address: $address);
		}

		if (in_array($channel, self::PHONE_CHANNELS, true) === true) {
			return $this->phoneNumbers->toE164(rawNumber: $address);
		}

		if ($channel === self::CHANNEL_DIGITAL_POST) {
			if (preg_match('/^\d{9}$/', $address) === 1) {
				return $this->hashBsn(bsn: $address);
			}

			return strtolower($address);
		}

		return $this->guess(address: $address);

	}//end normalise()

	/**
	 * The hashed form of a BSN.
	 *
	 * @param string $bsn The BSN, nine digits.
	 *
	 * @return string `bsn:` plus the HMAC.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-digital-post-recipient-is-never-stored-as-a-plain-bsn-req-ooa-008
	 */
	public function hashBsn(string $bsn): string {
		return self::BSN_PREFIX . hash_hmac('sha256', trim($bsn), $this->secret());

	}//end hashBsn()

	/**
	 * The hashed form of a recipient key, for a log entry that may no longer name the address.
	 *
	 * A key that is already a hash (a BSN, or an earlier redaction) is returned as is,
	 * so every entry of one person carries the same key.
	 *
	 * @param string $key The normalised key.
	 *
	 * @return string `h:` plus the HMAC, or the key when it is already hashed or empty.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/outbound-opt-out-authority/spec.md#requirement-an-erasure-redacts-the-earlier-log-entries-req-ooa-013
	 */
	public function hashKey(string $key): string {
		$key = trim($key);
		if ($key === '' || str_starts_with($key, self::BSN_PREFIX) === true || str_starts_with($key, self::HASH_PREFIX) === true) {
			return $key;
		}

		return self::HASH_PREFIX . hash_hmac('sha256', $key, $this->secret());

	}//end hashKey()

	/**
	 * An email address, or null when it is not one.
	 *
	 * @param string $address The address.
	 *
	 * @return string|null The lower-cased address.
	 */
	private function email(string $address): ?string {
		if (str_contains($address, '@') === false) {
			return null;
		}

		return strtolower($address);

	}//end email()

	/**
	 * A recipient whose channel is not named: an email address, a phone
	 * number or an opaque handle.
	 *
	 * @param string $address The address.
	 *
	 * @return string The key.
	 */
	private function guess(string $address): string {
		if (str_contains($address, '@') === true) {
			return strtolower($address);
		}

		if (preg_match('/^(\+|00|0)[\d\s().-]{6,}$/', $address) === 1) {
			$e164 = $this->phoneNumbers->toE164(rawNumber: $address);
			if ($e164 !== null) {
				return $e164;
			}
		}

		return strtolower($address);

	}//end guess()

	/**
	 * The hashing secret, minted once and kept.
	 *
	 * @return string The secret.
	 */
	private function secret(): string {
		return $this->secret->value();

	}//end secret()

}//end class
