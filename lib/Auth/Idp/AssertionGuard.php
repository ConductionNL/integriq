<?php

/**
 * Integriq AssertionGuard.
 *
 * The four checks that decide whether a government assertion may become an
 * envelope: it answers a request we made, it names us as its audience, it is
 * inside its own validity window, and it has not been used before.
 *
 * The guard reads a normalised assertion rather than raw SAML, which is what
 * lets it be tested without a broker, an IdP or a certificate.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
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
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;

/**
 * Refuses an assertion that must not become an envelope.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
 */
class AssertionGuard {

	/**
	 * How long a used assertion id is remembered.
	 *
	 * Longer than any assertion window we accept, so an assertion cannot
	 * outlive the memory of having been used.
	 *
	 * @var integer
	 */
	public const REPLAY_MEMORY_SECONDS = 900;

	/**
	 * Constructor.
	 *
	 * @param EnvelopeReplayGuard $replayGuard Makes an assertion id single-use.
	 */
	public function __construct(
		private readonly EnvelopeReplayGuard $replayGuard,
	) {

	}//end __construct()

	/**
	 * Check one assertion against the request it claims to answer.
	 *
	 * @param array<string,mixed> $assertion `{id, inResponseTo, audience, notBefore, notOnOrAfter}`.
	 * @param string $outstandingRequestId The id of the request this broker actually sent.
	 * @param string $expectedAudience The configured SP or RP EntityID.
	 * @param integer|null $now The clock, injectable for tests.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException On any failure. The reason names the check, never the assertion contents.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
	 */
	public function assertAcceptable(
		array $assertion,
		string $outstandingRequestId,
		string $expectedAudience,
		?int $now = null,
	): void {
		$this->assertSolicited(assertion: $assertion, outstandingRequestId: $outstandingRequestId);
		$this->assertAudience(assertion: $assertion, expectedAudience: $expectedAudience);
		$this->assertWithinWindow(assertion: $assertion, now: ($now ?? time()));

		$this->replayGuard->burn(
			jti: (string)($assertion['id'] ?? ''),
			ttlSeconds: self::REPLAY_MEMORY_SECONDS
		);

	}//end assertAcceptable()

	/**
	 * Refuse an assertion that answers no request of ours.
	 *
	 * IdP-initiated flows are not supported by design. An unsolicited
	 * response is correctly signed and completely valid, and accepting one
	 * means anybody who can reach the endpoint can start a session for
	 * whoever the IdP names.
	 *
	 * @param array<string,mixed> $assertion The assertion.
	 * @param string $outstandingRequestId The request id this broker sent.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When nothing matches.
	 */
	private function assertSolicited(array $assertion, string $outstandingRequestId): void {
		$inResponseTo = trim((string)($assertion['inResponseTo'] ?? ''));
		$expected = trim($outstandingRequestId);

		if ($expected === '' || $inResponseTo === '') {
			throw new IdpAssertionException(
				message: 'The response answers no outstanding request, so it is refused.'
			);
		}

		if (hash_equals($expected, $inResponseTo) === false) {
			throw new IdpAssertionException(
				message: 'The response answers a different request, so it is refused.'
			);
		}

	}//end assertSolicited()

	/**
	 * Refuse an assertion issued for somebody else.
	 *
	 * @param array<string,mixed> $assertion The assertion.
	 * @param string $expectedAudience The configured EntityID.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When the audience does not match.
	 */
	private function assertAudience(array $assertion, string $expectedAudience): void {
		$audience = trim((string)($assertion['audience'] ?? ''));
		$expected = trim($expectedAudience);

		if ($expected === '' || $audience === '' || hash_equals($expected, $audience) === false) {
			throw new IdpAssertionException(
				message: 'The assertion names another audience, so it is refused.'
			);
		}

	}//end assertAudience()

	/**
	 * Refuse an assertion outside its own validity window.
	 *
	 * No clock skew is allowed. A broker that accepts an assertion a little
	 * before it was valid accepts one a little after it expired too, and the
	 * window is the only thing bounding a stolen assertion's usefulness.
	 *
	 * @param array<string,mixed> $assertion The assertion.
	 * @param integer $now The clock.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When the window is missing, unreadable or closed.
	 */
	private function assertWithinWindow(array $assertion, int $now): void {
		$notBefore = $this->timestamp(value: ($assertion['notBefore'] ?? null));
		$notOnOrAfter = $this->timestamp(value: ($assertion['notOnOrAfter'] ?? null));

		if ($notBefore === null || $notOnOrAfter === null) {
			throw new IdpAssertionException(
				message: 'The assertion carries no readable validity window, so it is refused.'
			);
		}

		if ($now < $notBefore || $now >= $notOnOrAfter) {
			throw new IdpAssertionException(
				message: 'The assertion is outside its validity window, so it is refused.'
			);
		}

	}//end assertWithinWindow()

	/**
	 * One timestamp as a unix time, or null when it cannot be read.
	 *
	 * @param mixed $value The value off the assertion.
	 *
	 * @return integer|null The unix time, or null.
	 */
	private function timestamp(mixed $value): ?int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$parsed = strtotime(trim($value));
		if ($parsed === false) {
			return null;
		}

		return $parsed;

	}//end timestamp()

}//end class
