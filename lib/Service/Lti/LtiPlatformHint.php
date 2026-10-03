<?php

/**
 * Integriq LtiPlatformHint.
 *
 * The signed, short-lived login hint a platform launch hands a tool.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

use OCA\Integriq\Exception\LtiValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;

/**
 * Issues and verifies the `login_hint` / `lti_message_hint` of a platform launch.
 *
 * One token serves as both hints (design.md D2). It carries the user, the
 * placement, the deployment, the message type, the role, the course context
 * and the return URL, expires after five minutes, and is signed with the
 * instance secret through {@see ICrypto::calculateHMAC()}. It is never
 * stored: the authorization endpoint trusts it because it can verify the
 * signature, not because it remembers issuing it. The tool treats it as
 * opaque and only echoes it back.
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
 */
class LtiPlatformHint {

	/**
	 * How long a hint stays valid, in seconds (REQ-LTIL-001: five minutes).
	 *
	 * @var integer
	 */
	public const TTL_SECONDS = 300;

	/**
	 * Domain separator mixed into the HMAC so this signature cannot be
	 * replayed as any other ICrypto HMAC the instance computes.
	 *
	 * @var string
	 */
	private const PURPOSE = 'integriq.lti.platform-hint.v1';

	/**
	 * Constructor.
	 *
	 * @param ICrypto $crypto Computes the HMAC with the instance secret.
	 * @param ITimeFactory $timeFactory The clock.
	 */
	public function __construct(
		private readonly ICrypto $crypto,
		private readonly ITimeFactory $timeFactory,
	) {

	}//end __construct()

	/**
	 * Issue a hint over the launch context.
	 *
	 * @param array<string, string> $context `userId`, `placementId`, `deploymentUuid`, `messageType`, `role`,
	 *                                       `contextId`, `contextTitle`, `returnUrl`.
	 *
	 * @return string The signed hint.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function issue(array $context): string {
		$payload = $context;
		$payload['exp'] = ($this->timeFactory->getTime() + self::TTL_SECONDS);

		$body = self::base64UrlEncode(data: (string)json_encode($payload, JSON_UNESCAPED_SLASHES));

		return $body . '.' . self::base64UrlEncode(data: $this->sign(body: $body));
	}//end issue()

	/**
	 * Read a hint's payload WITHOUT verifying it.
	 *
	 * Used only to name the user a hint was issued for, so a mismatch can be
	 * reported as such before the signature check. Nothing read here is
	 * trusted until {@see verify()} has passed.
	 *
	 * @param string $hint The hint as the tool echoed it.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @throws LtiValidationException When the hint is not a hint at all.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-the-platform-authorizes-the-tools-login-redirect-and-posts-the-launch-token-req-ltil-002
	 */
	public function peek(string $hint): array {
		$parts = explode('.', $hint);
		if (count($parts) !== 2) {
			throw self::failure(check: 'hint', message: 'The login hint is malformed');
		}

		$payload = json_decode((string)self::base64UrlDecode(data: $parts[0]), true);
		if (is_array($payload) === false) {
			throw self::failure(check: 'hint', message: 'The login hint is malformed');
		}

		return $payload;
	}//end peek()

	/**
	 * Verify a hint's signature and expiry and return its payload.
	 *
	 * @param string $hint The hint as the tool echoed it.
	 *
	 * @return array<string, mixed> The verified payload.
	 *
	 * @throws LtiValidationException When the signature does not verify (`hint`) or the hint expired (`hint-expired`).
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-the-platform-authorizes-the-tools-login-redirect-and-posts-the-launch-token-req-ltil-002
	 */
	public function verify(string $hint): array {
		$payload = $this->peek(hint: $hint);
		[$body, $signature] = explode('.', $hint);

		$expected = self::base64UrlEncode(data: $this->sign(body: $body));
		if (hash_equals($expected, $signature) === false) {
			throw self::failure(check: 'hint', message: 'The login hint signature does not verify');
		}

		if ((int)($payload['exp'] ?? 0) < $this->timeFactory->getTime()) {
			throw self::failure(check: 'hint-expired', message: 'The login hint has expired; start the launch again');
		}

		return $payload;
	}//end verify()

	/**
	 * The raw HMAC over a hint body.
	 *
	 * @param string $body The base64url payload.
	 *
	 * @return string The raw HMAC bytes.
	 */
	private function sign(string $body): string {
		return $this->crypto->calculateHMAC(self::PURPOSE . '.' . $body);
	}//end sign()

	/**
	 * Build the validation failure for a named check.
	 *
	 * @param string $check The check that failed.
	 * @param string $message What the user reads.
	 *
	 * @return LtiValidationException
	 */
	private static function failure(string $check, string $message): LtiValidationException {
		return new LtiValidationException(message: $message, details: ['check' => $check], httpStatus: 400);
	}//end failure()

	/**
	 * Base64url-encode without padding.
	 *
	 * @param string $data Raw bytes.
	 *
	 * @return string
	 */
	private static function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}//end base64UrlEncode()

	/**
	 * Base64url-decode.
	 *
	 * @param string $data Base64url text.
	 *
	 * @return string|false The raw bytes, or false when not decodable.
	 */
	private static function base64UrlDecode(string $data): string|false {
		return base64_decode(strtr($data, '-_', '+/'), true);
	}//end base64UrlDecode()
}//end class
