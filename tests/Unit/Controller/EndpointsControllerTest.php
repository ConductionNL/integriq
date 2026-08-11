<?php

/**
 * Unit tests for EndpointsController's CORS preflight endpoint.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\EndpointsController;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\EndpointCacheService;
use OCA\OpenConnector\Service\EndpointService;
use OCA\OpenConnector\Service\ObjectService;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An IRequest whose `$server` map is a REAL property.
 *
 * `IRequest` exposes `$_SERVER` through the magic `@property-read string[]
 * $server`, so a PHPUnit mock has no such property: assigning
 * `$mock->server = […]` creates a dynamic property, which PHP 8.2 deprecates
 * and PHPUnit reports. `preflightedCors()` reads exactly that map, so the
 * cheapest honest double is one that declares it.
 *
 * Every other method is a stub — this controller path touches none of them.
 */
final class ServerAwareRequestStub implements IRequest
{

    /**
     * The $_SERVER-alike map.
     *
     * @var array<string, string>
     */
    public array $server = [];

    /**
     * Route parameters.
     *
     * @var array<string, string>
     */
    public array $urlParams = [];


    /**
     * @param array<string, string> $server The server map to expose.
     */
    public function __construct(array $server)
    {
        $this->server = $server;

    }//end __construct()


    public function getHeader(string $name): string
    {
        return '';
    }

    public function getParam(string $key, $default=null)
    {
        return $default;
    }

    public function getParams(): array
    {
        return [];
    }

    public function getMethod(): string
    {
        return 'OPTIONS';
    }

    public function getUploadedFile(string $key)
    {
        return null;
    }

    public function getEnv(string $key)
    {
        return null;
    }

    public function getCookie(string $key)
    {
        return null;
    }

    public function passesCSRFCheck(): bool
    {
        return true;
    }

    public function passesStrictCookieCheck(): bool
    {
        return true;
    }

    public function passesLaxCookieCheck(): bool
    {
        return true;
    }

    public function getId(): string
    {
        return 'test-request-id';
    }

    public function getRemoteAddress(): string
    {
        return '127.0.0.1';
    }

    public function getServerProtocol(): string
    {
        return 'https';
    }

    public function getHttpProtocol(): string
    {
        return 'HTTP/1.1';
    }

    public function getRequestUri(): string
    {
        return '/api/endpoint';
    }

    public function getRawPathInfo(): string
    {
        return '/api/endpoint';
    }

    public function getPathInfo()
    {
        return '/api/endpoint';
    }

    public function getScriptName(): string
    {
        return '/index.php';
    }

    public function isUserAgent(array $agent): bool
    {
        return false;
    }

    public function getInsecureServerHost(): string
    {
        return 'localhost';
    }

    public function getServerHost(): string
    {
        return 'localhost';
    }

}//end class

/**
 * Wire-contract tests for OPTIONS on the generic endpoint surface.
 *
 * `preflightedCors()` is `#[PublicPage] #[NoCSRFRequired]` — it answers before
 * any authentication runs, on every endpoint the app publishes. Its headers
 * therefore ARE the security posture of the whole endpoint runtime for
 * cross-origin callers, which is why they are asserted individually rather
 * than as "a Response came back".
 */
class EndpointsControllerTest extends TestCase
{


    /**
     * Build the controller with a request reporting the given server vars.
     *
     * @param array<string, string> $server The $_SERVER-alike map the request exposes.
     *
     * @return EndpointsController
     */
    private function buildController(array $server): EndpointsController
    {
        $request = new ServerAwareRequestStub($server);

        return new EndpointsController(
            'openconnector',
            $request,
            $this->createMock(EndpointService::class),
            $this->createMock(AuthorizationService::class),
            $this->createMock(ObjectService::class),
            $this->createMock(EndpointCacheService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IL10N::class)
        );

    }//end buildController()


    /**
     * Read a Response's raw, handler-set `headers` property via reflection.
     *
     * {@see \OCP\AppFramework\Http\Response::getHeaders()} merges in a live
     * `\OC::$server`-resolved IRequest, which does not exist in a standalone
     * unit-test environment.
     *
     * @param Response $response The response to inspect.
     *
     * @return array<string, mixed>
     */
    private function rawHeaders(Response $response): array
    {
        $property = new \ReflectionProperty(Response::class, 'headers');
        $property->setAccessible(true);

        return $property->getValue($response);

    }//end rawHeaders()


    /**
     * The preflight echoes the caller's Origin and advertises the configured
     * methods, headers and max-age.
     *
     * @return void
     */
    public function testPreflightedCorsEchoesTheOriginAndAdvertisesTheConfiguredPolicy(): void
    {
        $response = $this->buildController(['HTTP_ORIGIN' => 'https://partner.example.org'])
            ->preflightedCors();

        $headers = $this->rawHeaders($response);

        $this->assertSame('https://partner.example.org', $headers['Access-Control-Allow-Origin']);
        $this->assertSame('PUT, POST, GET, DELETE, PATCH', $headers['Access-Control-Allow-Methods']);
        $this->assertSame('Authorization, Content-Type, Accept', $headers['Access-Control-Allow-Headers']);
        $this->assertSame('1728000', $headers['Access-Control-Max-Age']);

    }//end testPreflightedCorsEchoesTheOriginAndAdvertisesTheConfiguredPolicy()


    /**
     * With no Origin header the policy falls back to the wildcard.
     *
     * @return void
     */
    public function testPreflightedCorsFallsBackToTheWildcardWhenNoOriginIsSent(): void
    {
        $headers = $this->rawHeaders($this->buildController([])->preflightedCors());

        $this->assertSame('*', $headers['Access-Control-Allow-Origin']);

    }//end testPreflightedCorsFallsBackToTheWildcardWhenNoOriginIsSent()


    /**
     * THE ASSERTION THAT MATTERS: this preflight reflects an arbitrary,
     * unauthenticated caller's Origin, so it must never also grant credentials.
     *
     * `Allow-Origin: <attacker origin>` together with
     * `Allow-Credentials: true` lets any site read authenticated responses from
     * every published endpoint — the endpoint runtime's whole surface. Unlike
     * UserController's preflight (which pins a concrete origin BECAUSE it sets
     * credentials true), this one must stay credential-free.
     *
     * @return void
     */
    public function testPreflightedCorsNeverGrantsCredentialsToAReflectedOrigin(): void
    {
        foreach (['https://evil.example', '*', ''] as $origin) {
            $server  = $origin === '' ? [] : ['HTTP_ORIGIN' => $origin];
            $headers = $this->rawHeaders($this->buildController($server)->preflightedCors());

            $this->assertSame(
                'false',
                $headers['Access-Control-Allow-Credentials'],
                'origin "' . $origin . '": a reflected origin with Allow-Credentials:true exposes every '
                . 'authenticated endpoint response to any site the user visits'
            );
        }

    }//end testPreflightedCorsNeverGrantsCredentialsToAReflectedOrigin()


    /**
     * The preflight is a bare Response — no body, 200.
     *
     * @return void
     */
    public function testPreflightedCorsReturnsAnEmptyOkResponse(): void
    {
        $response = $this->buildController(['HTTP_ORIGIN' => 'https://partner.example.org'])
            ->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatus());

    }//end testPreflightedCorsReturnsAnEmptyOkResponse()

}//end class
