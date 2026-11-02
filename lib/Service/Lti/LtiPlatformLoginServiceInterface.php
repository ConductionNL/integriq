<?php

/**
 * Contract of the LtiPlatformLoginService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Lti
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

namespace OCA\Integriq\Service\Lti;

use OCA\Integriq\Event\LtiLaunchRequestedEvent;
use OCA\Integriq\Exception\LtiValidationException;

/**
 * Public operations of {@see LtiPlatformLoginService} that other components call.
 */
interface LtiPlatformLoginServiceInterface {
	/**
	 * This platform's issuer: the instance's absolute base URL.
	 *
	 * @return string The `iss` value a tool registers for this platform.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function platformIssuer(): string;

	/**
	 * Answer a launch request with a login initiation form, or refuse it.
	 *
	 * @param LtiLaunchRequestedEvent $event The request; its result slot is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function initiateLogin(LtiLaunchRequestedEvent $event): void;

	/**
	 * Authorize the tool's login redirect and sign the launch id_token.
	 *
	 * Checks, in order (design.md D3): the signed-in user is the hint's user;
	 * the hint's signature and expiry; `client_id` is the approved tool the
	 * hint's deployment names; `redirect_uri` is registered for that tool;
	 * `nonce` and `state` are present. Any failure throws, naming the check,
	 * and nothing is signed.
	 *
	 * @param array<string, mixed> $params The authorization request parameters (GET or POST).
	 * @param string|null $sessionUid The signed-in user's uid, or null.
	 *
	 * @return array{redirectUri: string, idToken: string, state: string} What the controller posts to the tool.
	 *
	 * @throws LtiValidationException Naming the failed check in `details.check`.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-the-platform-authorizes-the-tools-login-redirect-and-posts-the-launch-token-req-ltil-002
	 */
	public function authorizeLaunch(array $params, ?string $sessionUid): array;
}
