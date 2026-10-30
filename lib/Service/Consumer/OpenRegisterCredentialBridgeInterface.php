<?php

/**
 * Contract of the OpenRegisterCredentialBridge.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Consumer
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

namespace OCA\Integriq\Service\Consumer;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Public operations of {@see OpenRegisterCredentialBridge} that other components call.
 */
interface OpenRegisterCredentialBridgeInterface {
	/**
	 * Check a JWT bearer token against integriq's consumers.
	 *
	 * @param string $authorization The Authorization header value (`Bearer <jwt>`).
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the token is refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function authorizeJwt(string $authorization): void;

	/**
	 * Check an API key: the rule's own keys first, then integriq's API-key consumers.
	 *
	 * @param string $header The presented key.
	 * @param array  $keys   The rule's `key => uid` map.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the key is refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function authorizeApiKey(string $header, array $keys): void;

	/**
	 * Check HTTP Basic credentials against Nextcloud's users.
	 *
	 * @param string $header The Authorization header value (`Basic <base64>`).
	 * @param array  $users  Allowed users (uid or e-mail); empty with empty groups means any.
	 * @param array  $groups Allowed groups.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the credentials are refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function authorizeBasic(string $header, array $users, array $groups): void;

	/**
	 * Check an OAuth bearer call that Nextcloud authenticated.
	 *
	 * @param string $header The Authorization header value.
	 * @param array  $users  Allowed users.
	 * @param array  $groups Allowed groups.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the call is refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function authorizeOAuth(string $header, array $users, array $groups): void;

	/**
	 * Check the signed-in Nextcloud session user (CSRF required).
	 *
	 * @param array $users  Allowed users.
	 * @param array $groups Allowed groups.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the session is refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function authorizeNcSession(array $users = [], array $groups = []): void;

	/**
	 * Check a token's time claims and jti, then integriq's lifetime cap.
	 *
	 * Used by the LTI launch and AGS paths, which verify the signature
	 * themselves against a platform's JWKS.
	 *
	 * @param array $payload The token claims.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the claims are refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-keeps-its-token-lifetime-cap-req-007
	 */
	public function validatePayload(array $payload): void;

	/**
	 * Echo the caller's origin, refusing a response that allows credentials.
	 *
	 * @param IRequest $request  The request.
	 * @param Response $response The response.
	 *
	 * @return Response
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md#requirement-cors-response-header-injection-with-credentials-guard-req-005
	 */
	public function corsAfterController(IRequest $request, Response $response): Response;

	/**
	 * The consumer object the last check admitted, or null.
	 *
	 * Null after Basic, OAuth, Nextcloud-session and rule-inline API keys: those
	 * authenticate a Nextcloud user, not a consumer.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function getResolvedConsumer(): ?ObjectEntity;
}
