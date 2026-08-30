<?php

/**
 * Integriq Authentication Twig Runtime Loader.
 *
 * Loads the AuthenticationRuntime for Twig when one of the authentication
 * helper functions is invoked from a template.
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

use OCA\Integriq\Service\AuthenticationService;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

/**
 * Runtime loader that wires the AuthenticationService into the Twig runtime.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class AuthenticationRuntimeLoader implements RuntimeLoaderInterface {
	/**
	 * Constructor.
	 *
	 * @param AuthenticationService $authenticationService Service that performs the token fetches.
	 */
	public function __construct(
		private readonly AuthenticationService $authenticationService,
	) {

	}//end __construct()

	/**
	 * Instantiate the requested runtime extension class.
	 *
	 * @param string $class Fully qualified class name to load.
	 *
	 * @return AuthenticationRuntime|null
	 */
	public function load(string $class): ?AuthenticationRuntime {
		if ($class === AuthenticationRuntime::class) {
			return new AuthenticationRuntime($this->authenticationService);
		}

		return null;
	}//end load()
}//end class
