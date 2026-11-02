<?php

/**
 * Contract of the RecipientKey.
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

/**
 * Public operations of {@see RecipientKey} that other components call.
 */
interface RecipientKeyInterface {
	/**
	 * Email.
	 *
	 * @var string
	 */
	public const CHANNEL_EMAIL = 'email';

	/**
	 * SMS.
	 *
	 * @var string
	 */
	public const CHANNEL_SMS = 'sms';

	/**
	 * A messaging gateway keyed on a phone number.
	 *
	 * @var string
	 */
	public const CHANNEL_MESSAGING = 'messaging';

	/**
	 * Microsoft Teams.
	 *
	 * @var string
	 */
	public const CHANNEL_TEAMS = 'teams';

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
	public function normalise(string $channel, string $address): ?string;

	/**
	 * The hashed form of a BSN.
	 *
	 * @param string $bsn The BSN, nine digits.
	 *
	 * @return string `bsn:` plus the HMAC.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-digital-post-recipient-is-never-stored-as-a-plain-bsn-req-ooa-008
	 */
	public function hashBsn(string $bsn): string;

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
	public function hashKey(string $key): string;
}
