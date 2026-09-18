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
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the signing secret.
	 * @param ISecureRandom $random Mints the secret the first time one is needed.
	 * @param OptOutRegistry $optOuts Says which categories carry no link at all.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ISecureRandom $random,
		private readonly OptOutRegistry $optOuts,
	) {

	}//end __construct()

	/**
	 * Mint a token for one recipient and one case.
	 *
	 * @param string $address The recipient.
	 * @param string $caseRef The case whose updates the link stops.
	 *
	 * @return string The token.
	 */
	public function mint(string $address, string $caseRef): string {
		$payload = base64_encode(json_encode(['a' => strtolower(trim($address)), 'c' => $caseRef]) ?: '');
		$payload = rtrim(strtr($payload, '+/', '-_'), '=');

		return $payload . '.' . $this->sign($payload);

	}//end mint()

	/**
	 * Read a token back.
	 *
	 * @param string $token The token.
	 *
	 * @return array{address:string,caseRef:string}|null What it says, or null when it does not
	 *         verify. A token that does not verify is not read at all.
	 */
	public function verify(string $token): ?array {
		$parts = explode('.', trim($token));
		if (count($parts) !== 2) {
			return null;
		}

		[$payload, $signature] = $parts;
		if (hash_equals($this->sign($payload), $signature) === false) {
			return null;
		}

		$decoded = json_decode((string)base64_decode(strtr($payload, '-_', '+/'), false), true);
		if (is_array($decoded) === false) {
			return null;
		}

		return [
			'address' => (string)($decoded['a'] ?? ''),
			'caseRef' => (string)($decoded['c'] ?? ''),
		];

	}//end verify()

	/**
	 * The link to render in a message, or nothing at all.
	 *
	 * @param string $address The recipient.
	 * @param string $caseRef The case.
	 * @param string $category What kind of message this is.
	 * @param string $baseUrl The instance's base url.
	 *
	 * @return string|null The link, or null when this category cannot be stopped.
	 */
	public function linkFor(string $address, string $caseRef, string $category, string $baseUrl = ''): ?string {
		if ($this->optOuts->isProtected($category) === true) {
			return null;
		}

		return rtrim($baseUrl, '/') . '/index.php/apps/integriq/unsubscribe/' . $this->mint($address, $caseRef);

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
