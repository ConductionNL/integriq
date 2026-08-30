<?php

/**
 * Integriq Authentication Twig Extension.
 *
 * Registers Twig functions for the various authentication flows
 * (OAuth, Decos, JWT) exposed to template authors.
 *
 * @category Twig
 * @package  OCA\Integriq\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension exposing the authentication helper functions.
 */
class AuthenticationExtension extends AbstractExtension {
	/**
	 * Return the Twig functions registered by this extension.
	 *
	 * @return array<int, TwigFunction>
	 */
	public function getFunctions(): array {
		return [
			new TwigFunction(name: 'oauthToken', callable: [AuthenticationRuntime::class, 'oauthToken']),
			new TwigFunction(name: 'decosToken', callable: [AuthenticationRuntime::class, 'decosToken']),
			new TwigFunction(name: 'jwtToken', callable: [AuthenticationRuntime::class, 'jwtToken']),
		];

	}//end getFunctions()
}//end class
