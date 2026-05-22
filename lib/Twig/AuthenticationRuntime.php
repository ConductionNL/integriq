<?php

namespace OCA\OpenConnector\Twig;

use Adbar\Dot;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenConnector\Service\AuthenticationService;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Twig\Extension\RuntimeExtensionInterface;

class AuthenticationRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly AuthenticationService $authService,
    ) {

    }//end __construct()

    /**
     * Add an oauth token to the configuration.
     *
     * @param  array $source The source data array (from ObjectEntity::getObject()).
     * @return string
     *
     * @throws GuzzleException
     */
    public function oauthToken(array $source): string
    {
        $configuration = new Dot($source['configuration'] ?? [], true);

        $authConfig = $configuration->get('authentication');

        return $this->authService->fetchOAuthTokens(
            configuration: $authConfig
        );
    }//end oauthToken()

    /**
     * Add a decos non-oauth token to the configuration.
     *
     * @param  array $source The source data array (from ObjectEntity::getObject()).
     * @return string
     *
     * @throws GuzzleException
     */
    public function decosToken(array $source): string
    {
        $configuration = new Dot($source['configuration'] ?? [], true);

        $authConfig = $configuration->get('authentication');

        return $this->authService->fetchDecosToken(
            configuration: $authConfig
        );
    }//end decosToken()

    /**
     * Add a jwt token to the configuration.
     *
     * @param  array $source The source data array (from ObjectEntity::getObject()).
     * @return string
     * @throws GuzzleException
     */
    public function jwtToken(array $source): string
    {
        $configuration = new Dot($source['configuration'] ?? [], true);

        $authConfig = $configuration->get('authentication');

        return $this->authService->fetchJWTToken(
            configuration: $authConfig
        );
    }//end jwtToken()
}//end class
