<?php
/**
 * Test stub for Nextcloud's concrete `\OC\AppFramework\Http\Request`.
 *
 * `EndpointService::buildSyntheticRequest()` (flow-workflowengine-integration
 * design.md Decision 5) constructs the REAL `\OC\AppFramework\Http\Request`
 * at runtime — it is a private (`\OC\`) NC core class, not part of the
 * `nextcloud/ocp` dev-dependency package, so it is absent from the
 * standalone composer test environment (no full NC install). This stub
 * mirrors just enough of the real class's constructor + `IRequest` surface
 * for `EndpointServiceTest` to exercise `triggerFromFlow()`'s request
 * synthesis without a live Nextcloud install — the same rationale already
 * used for the `OC\Hooks\Emitter` stub in this directory.
 *
 * Guarded by `class_exists()` in tests/bootstrap.php so it never clobbers the
 * real class when running inside a full Nextcloud installation.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-call-endpoint-operations-onevent-must-dispatch-to-endpointservicetriggerfromflow-req-003
 */

declare(strict_types=1);

namespace OC\AppFramework\Http;

use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;

/**
 * Minimal stand-in for NC's concrete `IRequest` implementation.
 *
 * Only `getMethod()`/`getParam()`/`getParams()`/`getId()` are backed by real
 * behaviour (derived from the constructor's `$vars`); every other `IRequest`
 * method returns an inert default — sufficient for exercising
 * `EndpointService::buildSyntheticRequest()` without a live NC install.
 */
class Request implements IRequest
{

    /**
     * The merged GET/POST/urlParams/params array (mirrors the real class's `parameters`).
     *
     * @var array
     */
    private array $parameters;

    /**
     * The request method (`GET`, `POST`, ...).
     *
     * @var string
     */
    private string $method;


    /**
     * Constructor — mirrors the real class's public signature.
     *
     * @param array        $vars         Request vars (`method`, `get`, `post`, `params`, `urlParams`, ...).
     * @param IRequestId   $requestId    Nextcloud request-id service.
     * @param IConfig      $config       Nextcloud system configuration (unused by this stub).
     * @param mixed        $csrfTokenManager Unused by this stub (real signature accepts `?CsrfTokenManager`).
     * @param string       $stream       Unused by this stub.
     */
    public function __construct(
        array $vars,
        private readonly IRequestId $requestId,
        private readonly IConfig $config,
        $csrfTokenManager=null,
        string $stream='php://input'
    ) {
        $this->method = ($vars['method'] ?? 'GET');
        $this->parameters = array_merge(
            ($vars['get'] ?? []),
            ($vars['post'] ?? []),
            ($vars['urlParams'] ?? []),
            ($vars['params'] ?? [])
        );

    }//end __construct()


    /**
     * {@inheritDoc}
     */
    public function getHeader(string $name): string
    {
        return '';

    }//end getHeader()


    /**
     * {@inheritDoc}
     */
    public function getParam(string $key, $default=null)
    {
        return ($this->parameters[$key] ?? $default);

    }//end getParam()


    /**
     * {@inheritDoc}
     */
    public function getParams(): array
    {
        return $this->parameters;

    }//end getParams()


    /**
     * {@inheritDoc}
     */
    public function getMethod(): string
    {
        return $this->method;

    }//end getMethod()


    /**
     * {@inheritDoc}
     */
    public function getUploadedFile(string $key)
    {
        return [];

    }//end getUploadedFile()


    /**
     * {@inheritDoc}
     */
    public function getEnv(string $key)
    {
        return null;

    }//end getEnv()


    /**
     * {@inheritDoc}
     */
    public function getCookie(string $key)
    {
        return null;

    }//end getCookie()


    /**
     * {@inheritDoc}
     */
    public function passesCSRFCheck(): bool
    {
        return true;

    }//end passesCSRFCheck()


    /**
     * {@inheritDoc}
     */
    public function passesStrictCookieCheck(): bool
    {
        return true;

    }//end passesStrictCookieCheck()


    /**
     * {@inheritDoc}
     */
    public function passesLaxCookieCheck(): bool
    {
        return true;

    }//end passesLaxCookieCheck()


    /**
     * {@inheritDoc}
     */
    public function getId(): string
    {
        return $this->requestId->getId();

    }//end getId()


    /**
     * {@inheritDoc}
     */
    public function getRemoteAddress(): string
    {
        return '127.0.0.1';

    }//end getRemoteAddress()


    /**
     * {@inheritDoc}
     */
    public function getServerProtocol(): string
    {
        return 'https';

    }//end getServerProtocol()


    /**
     * {@inheritDoc}
     */
    public function getHttpProtocol(): string
    {
        return 'HTTP/1.1';

    }//end getHttpProtocol()


    /**
     * {@inheritDoc}
     */
    public function getRequestUri(): string
    {
        return '';

    }//end getRequestUri()


    /**
     * {@inheritDoc}
     */
    public function getRawPathInfo(): string
    {
        return '';

    }//end getRawPathInfo()


    /**
     * {@inheritDoc}
     */
    public function getPathInfo()
    {
        return '';

    }//end getPathInfo()


    /**
     * {@inheritDoc}
     */
    public function getScriptName(): string
    {
        return '';

    }//end getScriptName()


    /**
     * {@inheritDoc}
     */
    public function isUserAgent(array $agent): bool
    {
        return false;

    }//end isUserAgent()


    /**
     * {@inheritDoc}
     */
    public function getInsecureServerHost(): string
    {
        return '';

    }//end getInsecureServerHost()


    /**
     * {@inheritDoc}
     */
    public function getServerHost(): string
    {
        return '';

    }//end getServerHost()


    /**
     * {@inheritDoc}
     */
    public function throwDecodingExceptionIfAny(): void
    {
        // No-op stub.
    }//end throwDecodingExceptionIfAny()


    /**
     * {@inheritDoc}
     */
    public function getFormat(): ?string
    {
        return null;

    }//end getFormat()
}//end class
