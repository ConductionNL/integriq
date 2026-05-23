<?php
/**
 * OpenConnector Authentication Twig Runtime.
 *
 * Runtime class invoked by the AuthenticationExtension Twig functions to
 * fetch authentication tokens for outbound calls.
 *
 * @category Twig
 * @package  OCA\OpenConnector\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Twig;

use Adbar\Dot;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenConnector\Service\AuthenticationService;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Authentication runtime that fetches tokens for a given source configuration.
 */
class AuthenticationRuntime implements RuntimeExtensionInterface
{
    /**
     * Constructor.
     *
     * @param AuthenticationService $authService Service that performs the token fetches.
     */
    public function __construct(
        private readonly AuthenticationService $authService,
    ) {

    }//end __construct()

    /**
     * Add an oauth token to the configuration.
     *
     * @param array $source The source data array (from ObjectEntity::getObject()).
     *
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
     * @param array $source The source data array (from ObjectEntity::getObject()).
     *
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
     * @param array $source The source data array (from ObjectEntity::getObject()).
     *
     * @return string
     *
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
