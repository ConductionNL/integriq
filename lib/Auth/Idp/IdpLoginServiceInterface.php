<?php

/**
 * Contract of the IdpLoginService.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;

/**
 * Public operations of {@see IdpLoginService} that other components call.
 */
interface IdpLoginServiceInterface {
	/**
	 * Start a login and answer where to send the browser.
	 *
	 * Every refusal throws, and the caller shows integriq's own error page:
	 * before the checks pass there is no address it is safe to send the
	 * browser to.
	 *
	 * @param string $provider The provider id from the route.
	 * @param array<string,mixed> $params `{organisation, consumer, trust, returnUrl, relayState}`.
	 *
	 * @return string The identity provider's address.
	 *
	 * @throws IdpAssertionException When anything about the request or the broker is not in order.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function start(string $provider, array $params): string;

	/**
	 * Finish a login and answer where to send the browser.
	 *
	 * Once the state is found every failure still goes back to the consumer,
	 * as one generic error with the relay state, and the real reason is
	 * logged. Before that there is nowhere safe to go, so it throws.
	 *
	 * @param string $provider The provider id from the route.
	 * @param array<string,mixed> $callback What arrived on the callback.
	 * @param integer|null $now The clock, injectable for tests.
	 *
	 * @return string The consumer's return address with `code` and `relayState`, or with `error` and `relayState`.
	 *
	 * @throws IdpAssertionException When the response answers no stored state (an IdP-initiated flow included).
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
	 */
	public function callback(string $provider, array $callback, ?int $now = null): string;
}
