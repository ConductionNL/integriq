<?php

namespace OCA\OpenConnector\Twig;

use OCA\OpenConnector\Service\AuthenticationService;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class AuthenticationRuntimeLoader implements RuntimeLoaderInterface
{
    public function __construct(
        private readonly AuthenticationService $authenticationService,
    ) {

    }//end __construct()

    public function load(string $class): ?AuthenticationRuntime
    {
        if ($class === AuthenticationRuntime::class) {
            return new AuthenticationRuntime($this->authenticationService);
        }

        return null;
    }//end load()
}//end class
