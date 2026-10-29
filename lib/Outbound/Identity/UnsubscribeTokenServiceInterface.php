<?php

/**
 * Contract of the UnsubscribeTokenService.
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
 * Public operations of {@see UnsubscribeTokenService} that other components call.
 */
interface UnsubscribeTokenServiceInterface {
	/**
	 * The prefix of the scoped token format.
	 *
	 * @var string
	 */
	public const PREFIX_V3 = 'v3';

	/**
	 * The token verifies and has not expired.
	 *
	 * @var string
	 */
	public const STATUS_VALID = 'valid';

	/**
	 * The signature verifies but the link is past its expiry.
	 *
	 * @var string
	 */
	public const STATUS_EXPIRED = 'expired';

	/**
	 * Mint a token for one recipient and one case.
	 *
	 * @param string $address The recipient.
	 * @param string $caseRef The case whose updates the link stops.
	 *
	 * @return string The token.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function mint(string $address, string $caseRef): string;

	/**
	 * Mint a version 3 token: one address, one scope, one channel, one ref.
	 *
	 * @param string $address The recipient key.
	 * @param string $scope `instance`, `channel`, `case` or `list`.
	 * @param string $channel The channel a channel stop covers.
	 * @param string $ref The case or list.
	 * @param string $purpose What the link stops: `marketing`, `service`, or empty for everything.
	 *
	 * @return string The token.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
	 */
	public function mintScoped(string $address, string $scope, string $channel, string $ref, string $purpose = ''): string;

	/**
	 * Read a token back, if it is valid.
	 *
	 * @param string $token The token.
	 *
	 * @return array{address:string,caseRef:string}|null What it says, or null when it does not
	 *         verify or has expired. A token that does not verify is not read at all.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function verify(string $token): ?array;

	/**
	 * Say what a token is: valid, expired or invalid, and in which format.
	 *
	 * The claims of a token whose signature fails are never decoded. An
	 * expired token reports no address either: nothing is done with it.
	 *
	 * @param string $token The token.
	 *
	 * @return array{status:string,format:string,address:string,caseRef:string,scope:string,channel:string,ref:string,purpose:string} The verdict.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function inspect(string $token): array;

	/**
	 * The case link to render in a message, or nothing at all.
	 *
	 * @param string $address The recipient.
	 * @param string $caseRef The case.
	 * @param string $category What kind of message this is.
	 * @param string $baseUrl The instance's base url.
	 *
	 * @return string|null The link, or null when this category cannot be stopped.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function linkFor(string $address, string $caseRef, string $category, string $baseUrl = ''): ?string;

	/**
	 * The unsubscribe material for one recipient, or nothing at all.
	 *
	 * `url` is the page a person opens; GET only shows it. `oneClickUrl` is
	 * where a mail provider POSTs `List-Unsubscribe=One-Click` (RFC 8058).
	 * `headers` are the two `List-Unsubscribe` headers. `smsText` is set for
	 * a phone channel only, and is at most SMS_TEXT_MAX characters.
	 *
	 * @param string $address The recipient key.
	 * @param string $scope What the link stops.
	 * @param string $channel The channel.
	 * @param string $ref The case or list.
	 * @param string $category What kind of message this is.
	 * @param string $baseUrl The instance's base url, or empty for this instance's own.
	 *
	 * @return array{url:string,oneClickUrl:string,smsText:string|null,headers:array<string,string>}|null The
	 *         material, or null when this category cannot be stopped.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function materialFor(
		string $address,
		string $scope,
		string $channel,
		string $ref,
		string $category,
		string $baseUrl = '',
	): ?array;

	/**
	 * The token behind a short id, or null when there is none or it expired.
	 *
	 * @param string $shortId The id from the SMS.
	 *
	 * @return string|null The token.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function resolveShort(string $shortId): ?string;
}
