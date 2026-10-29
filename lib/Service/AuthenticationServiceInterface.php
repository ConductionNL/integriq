<?php

/**
 * Contract of the AuthenticationService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use Jose\Component\Core\JWK;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * Public operations of {@see AuthenticationService} that other components call.
 */
interface AuthenticationServiceInterface {
	/**
	 * Requests an OAuth Access Token with predefined configuration
	 *
	 * @param array $configuration The configuration for the OAuth call.
	 *
	 * @return string The resulting access token
	 *
	 * @throws BadRequestException Thrown if the configuration is not compatible with OAuth,
	 *                             or if the tokenUrl is unsafe (C4: SSRF guard).
	 * @throws \GuzzleHttp\Exception\GuzzleException Thrown if the token endpoint does not respond with an access token.
	 * @todo   Convert GuzzleException to another error.
	 *
	 * @spec openspec/specs/authentication-twig/spec.md
	 */
	public function fetchOAuthTokens(array $configuration): string;

	/**
	 * Fetch an access token from the DeCOS non-implementation of OAuth 2.0
	 *
	 * @param array $configuration The configuration of the source.
	 *
	 * @return string The access token
	 *
	 * @throws BadRequestException When the tokenUrl is unsafe (C4: SSRF guard).
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 *
	 * @spec openspec/specs/authentication-twig/spec.md
	 */
	public function fetchDecosToken(array $configuration): string;

	/**
	 * Generates a JWT token that can be used for authentication.
	 *
	 * @param array $configuration The auth configuration for the JWT token. Must at least contain payload, algorithm and secret.
	 *
	 * @return string The generated JWT token
	 *
	 * @throws BadRequestException When required parameters are missing or the JWK cannot be formed.
	 * @throws Exception When JWS signing fails (propagated from generateJWT — C1 fix).
	 *
	 * @spec openspec/specs/authentication-twig/spec.md
	 */
	public function fetchJWTToken(array $configuration): string;

	/**
	 * Build a WS-Security UsernameToken SOAP header XML string.
	 *
	 * Supports PasswordText (plaintext) and PasswordDigest
	 * (Base64(SHA1(Nonce + Created + Password))) authentication modes per the
	 * WS-Security UsernameToken 1.0 profile.
	 *
	 * @param array $configuration Auth configuration. Required keys: username, password.
	 *                             Optional: passwordType ('PasswordText'|'PasswordDigest'), nonce.
	 *
	 * @return string The wsse:Security SOAP header XML fragment.
	 *
	 * @throws BadRequestException When required username or password is missing.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	public function buildWsSecurityHeader(array $configuration): string;
}
