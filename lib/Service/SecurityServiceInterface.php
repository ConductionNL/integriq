<?php

/**
 * Contract of the SecurityService.
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

use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public operations of {@see SecurityService} that other components call.
 */
interface SecurityServiceInterface {
	/**
	 * Check if login attempt is allowed based on rate limiting rules
	 *
	 * This method implements comprehensive rate limiting including:
	 * - Per-user rate limiting
	 * - Per-IP rate limiting
	 * - Account lockout mechanisms
	 * - Progressive delay system
	 *
	 * @param string $username The username attempting to login.
	 * @param string $ipAddress The IP address of the request.
	 *
	 * @return array Result with 'allowed' boolean and optional 'delay' or 'lockout_until'.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function checkLoginRateLimit(string $username, string $ipAddress): array;

	/**
	 * Record a failed login attempt.
	 *
	 * This method tracks failed login attempts for both users and IP addresses
	 * and implements automatic lockout when thresholds are exceeded.
	 *
	 * @param string $username The username that failed authentication.
	 * @param string $ipAddress The IP address of the failed attempt.
	 * @param string $reason The reason for login failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function recordFailedLoginAttempt(string $username, string $ipAddress, string $reason = 'invalid_credentials'): void;

	/**
	 * Record a successful login attempt.
	 *
	 * This method clears rate limiting counters and progressive delays
	 * when a user successfully authenticates.
	 *
	 * @param string $username The username that successfully authenticated.
	 * @param string $ipAddress The IP address of the successful attempt.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function recordSuccessfulLogin(string $username, string $ipAddress): void;

	/**
	 * Sanitize input data to prevent XSS and injection attacks
	 *
	 * This method provides comprehensive input sanitization including:
	 * - HTML entity encoding
	 * - Script tag removal
	 * - Dangerous character filtering
	 * - Length limiting
	 *
	 * @param mixed $input The input to sanitize.
	 * @param int $maxLength Maximum allowed length for strings.
	 *
	 * @return mixed Sanitized input.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function sanitizeInput(mixed $input, int $maxLength = 255): mixed;

	/**
	 * Validate and sanitize login credentials
	 *
	 * This method provides specific validation and sanitization for login
	 * credentials to ensure they meet security requirements.
	 *
	 * @param array $credentials The login credentials to validate.
	 *
	 * @return array Validated and sanitized credentials or error information.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function validateLoginCredentials(array $credentials): array;

	/**
	 * Add security headers to response
	 *
	 * This method adds various security headers to the HTTP response
	 * to protect against common web vulnerabilities.
	 *
	 * @param JSONResponse $response The response to add headers to.
	 *
	 * @return JSONResponse The response with added security headers.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function addSecurityHeaders(JSONResponse $response): JSONResponse;

	/**
	 * Get client IP address from request
	 *
	 * This method extracts the real client IP address from the request,
	 * considering various proxy headers while preventing spoofing.
	 *
	 * @param IRequest $request The request object.
	 *
	 * @return string The client IP address.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function getClientIpAddress(IRequest $request): string;
}
