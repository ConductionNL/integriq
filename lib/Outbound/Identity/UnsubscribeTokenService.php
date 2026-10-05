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
 * Three formats. A new link is `v3.<claims>.<signature>`: the claims carry
 * the address (`a`), the scope (`s`: instance, channel, case or list), the
 * channel (`ch`), the case or list ref (`r`) and an expiry (`e`, unix time).
 * The signature covers the prefix, so a signature from one format never
 * verifies as another. `v2.<claims>.<signature>` (2026-10-05) and the
 * unprefixed `<claims>.<signature>` before it carry an address and a case
 * only. Those are still honoured as case stops once their signature
 * verifies: they are already in mail people have received, an opt-out is the
 * recipient's own right, and the most a leaked old link can do is stop the
 * non-statutory updates of one case for the one address it was minted for.
 * Nothing mints the old formats any more.
 *
 * A full token does not fit an SMS, so for a phone channel the link material
 * carries a short text with a ten-character id the server maps to the token
 * (`integriq_unsubscribe_short`).
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

use OCA\Integriq\Db\UnsubscribeShortLink;
use OCA\Integriq\Db\UnsubscribeShortLinkMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

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
	 * The prefix of the scoped token format.
	 *
	 * @var string
	 */
	public const PREFIX_V3 = 'v3';

	/**
	 * The app-config key holding the host and path an SMS short link starts with.
	 *
	 * @var string
	 */
	public const CONFIG_SHORT_BASE = 'outbound.short_link_base';

	/**
	 * How long a short id is.
	 *
	 * @var int
	 */
	public const SHORT_ID_LENGTH = 10;

	/**
	 * The most characters the SMS unsubscribe text may take.
	 *
	 * @var int
	 */
	public const SMS_TEXT_MAX = 50;

	/**
	 * The secret, once read. A sensitive app-config value is decrypted on
	 * every read, which made a batch of 500 cost 300 ms; one read per request
	 * is enough.
	 *
	 * @var string|null
	 */
	private ?string $secretCache = null;

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
	 * @param ISecureRandom $random Mints the secret and the short ids.
	 * @param OptOutCategories $categories Says which categories carry no link at all.
	 * @param ITimeFactory $time The clock the expiry is set and checked against.
	 * @param UnsubscribeShortLinkMapper $shortLinks Keeps the short ids an SMS carries.
	 * @param IURLGenerator $urls The instance url, when a caller gives none.
	 * @param LoggerInterface $logger Warns when an SMS text cannot be kept short.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ISecureRandom $random,
		private readonly OptOutCategories $categories,
		private readonly ITimeFactory $time,
		private readonly UnsubscribeShortLinkMapper $shortLinks,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

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
	public function mint(string $address, string $caseRef): string {
		return $this->mintScoped(address: $address, scope: OptOutRegistry::SCOPE_CASE, channel: '', ref: $caseRef);

	}//end mint()

	/**
	 * Mint a version 3 token: one address, one scope, one channel, one ref.
	 *
	 * @param string $address The recipient key.
	 * @param string $scope `instance`, `channel`, `case` or `list`.
	 * @param string $channel The channel a channel stop covers.
	 * @param string $ref The case or list.
	 *
	 * @return string The token.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function mintScoped(string $address, string $scope, string $channel, string $ref): string {
		$payload = $this->encode(
			claims: [
				'a' => strtolower(trim($address)),
				's' => $scope,
				'ch' => $channel,
				'r' => $ref,
				'e' => $this->expiry(),
			]
		);
		$signed = self::PREFIX_V3 . '.' . $payload;

		return $signed . '.' . $this->sign(payload: $signed);

	}//end mintScoped()

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
	 * @return array{status:string,format:string,address:string,caseRef:string,scope:string,channel:string,ref:string} The verdict.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function inspect(string $token): array {
		$parts = explode('.', trim($token));

		if (count($parts) === 3 && in_array($parts[0], [self::PREFIX_V2, self::PREFIX_V3], true) === true) {
			return $this->inspectPrefixed(prefix: $parts[0], payload: $parts[1], signature: $parts[2]);
		}

		if (count($parts) === 2) {
			return $this->inspectV1(payload: $parts[0], signature: $parts[1]);
		}

		return $this->invalid();

	}//end inspect()

	/**
	 * Check a token with a format prefix and an expiry.
	 *
	 * @param string $prefix    `v2` or `v3`.
	 * @param string $payload   The claims part.
	 * @param string $signature The signature part.
	 *
	 * @return array{status:string,format:string,address:string,caseRef:string,scope:string,channel:string,ref:string} The verdict.
	 */
	private function inspectPrefixed(string $prefix, string $payload, string $signature): array {
		if (hash_equals($this->sign(payload: $prefix . '.' . $payload), $signature) === false) {
			return $this->invalid();
		}

		$claims = $this->decode(payload: $payload);
		if ($claims === null || is_int($claims['e'] ?? null) === false) {
			return $this->invalid();
		}

		if ($claims['e'] < $this->time->getTime()) {
			$expired = $this->invalid();
			$expired['status'] = self::STATUS_EXPIRED;
			$expired['format'] = $prefix;
			return $expired;
		}

		return $this->valid(claims: $claims, format: $prefix);

	}//end inspectPrefixed()

	/**
	 * Check a link minted before expiry existed. See the class comment for
	 * why it is still honoured.
	 *
	 * @param string $payload   The claims part.
	 * @param string $signature The signature part.
	 *
	 * @return array{status:string,format:string,address:string,caseRef:string,scope:string,channel:string,ref:string} The verdict.
	 */
	private function inspectV1(string $payload, string $signature): array {
		if (hash_equals($this->sign(payload: $payload), $signature) === false) {
			return $this->invalid();
		}

		$claims = $this->decode(payload: $payload);
		if ($claims === null) {
			return $this->invalid();
		}

		return $this->valid(claims: $claims, format: 'v1');

	}//end inspectV1()

	/**
	 * The verdict for a token that does not verify.
	 *
	 * @return array{status:string,format:string,address:string,caseRef:string,scope:string,channel:string,ref:string} The verdict.
	 */
	private function invalid(): array {
		return [
			'status' => self::STATUS_INVALID,
			'format' => '',
			'address' => '',
			'caseRef' => '',
			'scope' => '',
			'channel' => '',
			'ref' => '',
		];

	}//end invalid()

	/**
	 * When a link minted now expires.
	 *
	 * @return int The unix time.
	 */
	private function expiry(): int {
		return $this->time->getTime() + ($this->ttlDays() * 86400);

	}//end expiry()

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
	 * @return array{status:string,format:string,address:string,caseRef:string,scope:string,channel:string,ref:string} The verdict.
	 */
	private function valid(array $claims, string $format): array {
		if ($format !== self::PREFIX_V3) {
			// Version 1 and 2 only ever stopped one case.
			$caseRef = (string)($claims['c'] ?? '');
			return [
				'status' => self::STATUS_VALID,
				'format' => $format,
				'address' => (string)($claims['a'] ?? ''),
				'caseRef' => $caseRef,
				'scope' => OptOutRegistry::SCOPE_CASE,
				'channel' => '',
				'ref' => $caseRef,
			];
		}

		$scope = (string)($claims['s'] ?? '');
		$ref = (string)($claims['r'] ?? '');
		$caseRef = '';
		if ($scope === OptOutRegistry::SCOPE_CASE) {
			$caseRef = $ref;
		}

		return [
			'status' => self::STATUS_VALID,
			'format' => $format,
			'address' => (string)($claims['a'] ?? ''),
			'caseRef' => $caseRef,
			'scope' => $scope,
			'channel' => (string)($claims['ch'] ?? ''),
			'ref' => $ref,
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
	public function linkFor(string $address, string $caseRef, string $category, string $baseUrl = ''): ?string {
		$material = $this->materialFor(
			address: $address,
			scope: OptOutRegistry::SCOPE_CASE,
			channel: '',
			ref: $caseRef,
			category: $category,
			baseUrl: $baseUrl
		);
		if ($material === null) {
			return null;
		}

		return $material['url'];

	}//end linkFor()

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
	): ?array {
		if ($this->categories->isExempt($category) === true) {
			return null;
		}

		$baseUrl = rtrim($baseUrl, '/');
		if ($baseUrl === '') {
			$baseUrl = rtrim($this->urls->getAbsoluteURL('/'), '/');
		}

		$token = $this->mintScoped(address: $address, scope: $scope, channel: $channel, ref: $ref);
		$url = $baseUrl . '/index.php/apps/integriq/unsubscribe/' . $token;

		$smsText = null;
		if (in_array($channel, RecipientKey::PHONE_CHANNELS, true) === true) {
			$smsText = $this->smsText(token: $token, baseUrl: $baseUrl);
		}

		return [
			'url' => $url,
			'oneClickUrl' => $url,
			'smsText' => $smsText,
			'headers' => [
				'List-Unsubscribe' => '<' . $url . '>',
				'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
			],
		];

	}//end materialFor()

	/**
	 * The token behind a short id, or null when there is none or it expired.
	 *
	 * @param string $shortId The id from the SMS.
	 *
	 * @return string|null The token.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function resolveShort(string $shortId): ?string {
		if (preg_match('/^[A-Za-z0-9]{' . self::SHORT_ID_LENGTH . '}$/', $shortId) !== 1) {
			return null;
		}

		$link = $this->shortLinks->findByShortId(shortId: $shortId);
		if ($link === null || $link->getExpiresAt() < $this->time->getTime()) {
			return null;
		}

		return $link->getToken();

	}//end resolveShort()

	/**
	 * The SMS unsubscribe text: `Stop: <host>/apps/integriq/u/<id>`.
	 *
	 * A host too long to keep the text at SMS_TEXT_MAX characters gets no
	 * text and a warning naming the setting that fixes it, rather than a
	 * text that breaks the limit or a truncated link that leads nowhere.
	 *
	 * @param string $token   The token the id stands for.
	 * @param string $baseUrl The instance url.
	 *
	 * @return string|null The text.
	 */
	private function smsText(string $token, string $baseUrl): ?string {
		$base = trim($this->appConfig->getValueString('integriq', self::CONFIG_SHORT_BASE, ''));
		if ($base === '') {
			$base = $baseUrl . '/apps/integriq';
		}

		$base = rtrim((string)preg_replace('#^https?://#i', '', $base), '/');
		$shortId = $this->random->generate(self::SHORT_ID_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);

		foreach (['Stop: ', 'Stop:'] as $prefix) {
			$text = $prefix . $base . '/u/' . $shortId;
			if (mb_strlen($text) > self::SMS_TEXT_MAX) {
				continue;
			}

			$link = new UnsubscribeShortLink();
			$link->setShortId($shortId);
			$link->setToken($token);
			$link->setExpiresAt($this->expiry());
			$link->setCreatedAt($this->time->getTime());
			$this->shortLinks->store(link: $link);

			return $text;
		}

		$this->logger->warning(
			'[UnsubscribeTokenService] the SMS unsubscribe text would pass ' . self::SMS_TEXT_MAX . ' characters; set ' . self::CONFIG_SHORT_BASE . ' to a shorter host',
			['base' => $base]
		);

		return null;

	}//end smsText()

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
		if ($this->secretCache !== null) {
			return $this->secretCache;
		}

		$secret = $this->appConfig->getValueString('integriq', self::CONFIG_SECRET, '');
		if (trim($secret) !== '') {
			$this->secretCache = $secret;
			return $secret;
		}

		$secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->appConfig->setValueString('integriq', self::CONFIG_SECRET, $secret, false, true);
		$this->secretCache = $secret;

		return $secret;

	}//end secret()

}//end class
