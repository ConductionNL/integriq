<?php

/**
 * Integriq UnsubscribeTokenService.
 *
 * The link binds to a recipient and a case through a signed token, not to an
 * account: the person who wants the updates to stop usually has neither an
 * account nor a reason to make one. A message in a protected category carries
 * no link at all, rather than a link that refuses, so nobody is told they can
 * stop something they cannot.
 *
 * Two formats. A new link is `v2.<claims>.<signature>`: the claims carry an
 * expiry (`e`, unix time) and the signature covers the `v2.` prefix, so a
 * signature from one format never verifies as the other. A link minted before
 * 2026-10-05 is `<claims>.<signature>` with no expiry. Those are still
 * honoured once their signature verifies: they are already in mail people
 * have received, an opt-out is the recipient's own right, and the most a
 * leaked old link can do is stop the non-statutory updates of one case for
 * the one address it was minted for. Nothing mints the old format any more.
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
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Mints and verifies unsubscribe tokens.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class UnsubscribeTokenService {

	/**
	 * The app-config key holding the signing secret.
	 *
	 * @var string
	 */
	public const CONFIG_SECRET = 'outbound.unsubscribe_secret';

	/**
	 * The app-config key holding how many days a new link stays valid.
	 *
	 * @var string
	 */
	public const CONFIG_TTL_DAYS = 'outbound.unsubscribe_ttl_days';

	/**
	 * How many days a new link stays valid unless the instance says otherwise.
	 * A year: people unsubscribe from mail they kept, not only from today's.
	 *
	 * @var int
	 */
	public const DEFAULT_TTL_DAYS = 365;

	/**
	 * The prefix of the current token format.
	 *
	 * @var string
	 */
	public const PREFIX_V2 = 'v2';

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
	 * The token does not verify, so nothing in it is read.
	 *
	 * @var string
	 */
	public const STATUS_INVALID = 'invalid';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the signing secret.
	 * @param ISecureRandom $random Mints the secret the first time one is needed.
	 * @param OptOutRegistry $optOuts Says which categories carry no link at all.
	 * @param ITimeFactory $time The clock the expiry is set and checked against.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ISecureRandom $random,
		private readonly OptOutRegistry $optOuts,
		private readonly ITimeFactory $time,
	) {

	}//end __construct()

	/**
	 * Mint a token for one recipient and one case, in the current format.
	 *
	 * @param string $address The recipient.
	 * @param string $caseRef The case whose updates the link stops.
	 *
	 * @return string The token.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function mint(string $address, string $caseRef): string {
		$expires = $this->time->getTime() + ($this->ttlDays() * 86400);
		$payload = $this->encode(claims: ['a' => strtolower(trim($address)), 'c' => $caseRef, 'e' => $expires]);
		$signed = self::PREFIX_V2 . '.' . $payload;

		return $signed . '.' . $this->sign(payload: $signed);

	}//end mint()

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
	public function verify(string $token): ?array {
		$result = $this->inspect(token: $token);
		if ($result['status'] !== self::STATUS_VALID) {
			return null;
		}

		return ['address' => $result['address'], 'caseRef' => $result['caseRef']];

	}//end verify()

	/**
	 * Say what a token is: valid, expired or invalid, and in which format.
	 *
	 * The claims of a token whose signature fails are never decoded. An
	 * expired token reports no address either: nothing is done with it.
	 *
	 * @param string $token The token.
	 *
	 * @return array{status:string,format:string,address:string,caseRef:string} The verdict.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function inspect(string $token): array {
		$invalid = ['status' => self::STATUS_INVALID, 'format' => '', 'address' => '', 'caseRef' => ''];
		$parts = explode('.', trim($token));

		if (count($parts) === 3 && $parts[0] === self::PREFIX_V2) {
			$signed = self::PREFIX_V2 . '.' . $parts[1];
			if (hash_equals($this->sign(payload: $signed), $parts[2]) === false) {
				return $invalid;
			}

			$claims = $this->decode(payload: $parts[1]);
			if ($claims === null || isset($claims['e']) === false || is_int($claims['e']) === false) {
				return $invalid;
			}

			if ($claims['e'] < $this->time->getTime()) {
				return ['status' => self::STATUS_EXPIRED, 'format' => self::PREFIX_V2, 'address' => '', 'caseRef' => ''];
			}

			return $this->valid(claims: $claims, format: self::PREFIX_V2);
		}

		if (count($parts) === 2) {
			// A link minted before expiry existed. See the class comment for
			// why it is still honoured.
			if (hash_equals($this->sign(payload: $parts[0]), $parts[1]) === false) {
				return $invalid;
			}

			$claims = $this->decode(payload: $parts[0]);
			if ($claims === null) {
				return $invalid;
			}

			return $this->valid(claims: $claims, format: 'v1');
		}

		return $invalid;

	}//end inspect()

	/**
	 * How many days a new link stays valid on this instance.
	 *
	 * @return int The days, at least one.
	 */
	private function ttlDays(): int {
		$raw = trim($this->appConfig->getValueString('integriq', self::CONFIG_TTL_DAYS, ''));
		if ($raw === '' || ctype_digit($raw) === false || (int)$raw < 1) {
			return self::DEFAULT_TTL_DAYS;
		}

		return (int)$raw;

	}//end ttlDays()

	/**
	 * A valid verdict from verified claims.
	 *
	 * @param array<string,mixed> $claims The claims.
	 * @param string $format The token format.
	 *
	 * @return array{status:string,format:string,address:string,caseRef:string} The verdict.
	 */
	private function valid(array $claims, string $format): array {
		return [
			'status' => self::STATUS_VALID,
			'format' => $format,
			'address' => (string)($claims['a'] ?? ''),
			'caseRef' => (string)($claims['c'] ?? ''),
		];

	}//end valid()

	/**
	 * Base64url-encode the claims.
	 *
	 * @param array<string,mixed> $claims The claims.
	 *
	 * @return string The payload.
	 */
	private function encode(array $claims): string {
		$json = json_encode($claims);
		if ($json === false) {
			$json = '';
		}

		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

	}//end encode()

	/**
	 * Decode a verified payload.
	 *
	 * @param string $payload The payload.
	 *
	 * @return array<string,mixed>|null The claims, or null when they are not an object.
	 */
	private function decode(string $payload): ?array {
		$decoded = json_decode((string)base64_decode(strtr($payload, '-_', '+/'), false), true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;

	}//end decode()

	/**
	 * The link to render in a message, or nothing at all.
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
	public function linkFor(string $address, string $caseRef, string $category, string $baseUrl = ''): ?string {
		if ($this->optOuts->isProtected($category) === true) {
			return null;
		}

		return rtrim($baseUrl, '/') . '/index.php/apps/integriq/unsubscribe/' . $this->mint(address: $address, caseRef: $caseRef);

	}//end linkFor()

	/**
	 * The signature over a payload.
	 *
	 * @param string $payload The payload.
	 *
	 * @return string The signature.
	 */
	private function sign(string $payload): string {
		return hash_hmac('sha256', $payload, $this->secret());

	}//end sign()

	/**
	 * The signing secret, minted once and kept.
	 *
	 * @return string The secret.
	 */
	private function secret(): string {
		$secret = $this->appConfig->getValueString('integriq', self::CONFIG_SECRET, '');
		if (trim($secret) !== '') {
			return $secret;
		}

		$secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->appConfig->setValueString('integriq', self::CONFIG_SECRET, $secret, false, true);

		return $secret;

	}//end secret()

}//end class
