<?php

/**
 * Caller input to a proxy endpoint is data, never a template (iq-a-f2), and
 * the caller's credential headers never reach the upstream (iq-a-f4).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * Pins the render boundary between the source's authored call config and what
 * an outside caller sends.
 */
final class CallServiceCallerInputTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var CallService
	 */
	private CallService $service;

	/**
	 * Build the service with fully mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueString')->willReturn('false');

		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$this->service = new CallService(
			ObjectServiceMockBuilder::make($this),
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
			new BodyCapturePolicy(appConfig: $appConfig),
		);
	}//end setUp()

	/**
	 * Run the private normaliseRequestConfig().
	 *
	 * @param array $config     The call config.
	 * @param array $sourceData The source data used as render context.
	 *
	 * @return array The normalised config.
	 */
	private function normalise(array $config, array $sourceData): array {
		$method = new ReflectionMethod(CallService::class, 'normaliseRequestConfig');
		$method->setAccessible(true);

		return $method->invoke($this->service, $config, $sourceData)['config'];
	}//end normalise()

	/**
	 * A template in the caller's query, header or body reaches the upstream literally.
	 *
	 * @return void
	 */
	public function testCallerTemplatesAreNotRendered(): void {
		$secret = '{{ source.configuration.authentication.password }}';
		$range = '{% for i in 1..100000000 %}{% endfor %}';
		$config = $this->normalise(
			config: [
				CallService::CALLER_INPUT => [
					'query' => ['q' => $secret, 'loc' => '{{ source.location }}', 'spin' => $range],
					'headers' => ['x_trace' => $secret],
					'body' => '{"name": "{{ source.location }}"}',
				],
			],
			sourceData: ['location' => 'https://upstream.example', 'configuration' => ['authentication' => ['password' => 'hunter2']]]
		);

		$this->assertSame($secret, $config['query']['q']);
		$this->assertSame('{{ source.location }}', $config['query']['loc']);
		$this->assertSame($range, $config['query']['spin']);
		$this->assertSame($secret, $config['headers']['x_trace']);
		$this->assertSame('{"name": "{{ source.location }}"}', $config['body']);
		$this->assertStringNotContainsString('hunter2', (string)json_encode($config));
	}//end testCallerTemplatesAreNotRendered()

	/**
	 * The source's authored config is still rendered and wins over the caller.
	 *
	 * @return void
	 */
	public function testSourceConfigIsRenderedAndWinsOverCaller(): void {
		$config = $this->normalise(
			config: [
				'query' => ['key' => '{{ source.location }}'],
				'headers' => ['Authorization' => 'Bearer {{ source.location }}'],
				CallService::CALLER_INPUT => [
					'query' => ['key' => 'caller', 'page' => '2'],
					'headers' => ['authorization' => 'Basic Y2FsbGVyOnB3', 'accept' => 'application/json'],
				],
			],
			sourceData: ['location' => 'https://upstream.example']
		);

		$this->assertSame(['key' => 'https://upstream.example', 'page' => '2'], $config['query']);
		$this->assertSame('Bearer https://upstream.example', $config['headers']['Authorization']);
		$this->assertArrayNotHasKey('authorization', $config['headers']);
		$this->assertSame('application/json', $config['headers']['accept']);
		$this->assertArrayNotHasKey(CallService::CALLER_INPUT, $config);
	}//end testSourceConfigIsRenderedAndWinsOverCaller()

	/**
	 * Cookie, Authorization and other credential or hop-by-hop headers are not proxied.
	 *
	 * @return void
	 */
	public function testCredentialHeadersAreNotForwarded(): void {
		$forwarded = EndpointService::forwardableHeaders(
			headers: [
				'cookie' => 'nc_session_id=abc; nc_token=def',
				'authorization' => 'Basic dXNlcjpwYXNzd29yZA==',
				'proxy_authorization' => 'Basic x',
				'requesttoken' => 'csrf',
				'x_api_key' => 'consumer-key',
				'apikey' => 'consumer-key',
				'x_auth_token' => 't',
				'host' => 'nextcloud.example',
				'connection' => 'keep-alive',
				'content_length' => '12',
				'x_forwarded_for' => '203.0.113.7',
				'accept' => 'application/hal+json',
				'content_type' => 'application/json',
				'accept_crs' => 'EPSG:4326',
				'x_doelbinding' => 'BRPACT',
				'if_match' => '"etag"',
			]
		);

		$this->assertSame(
			[
				'accept' => 'application/hal+json',
				'content_type' => 'application/json',
				'accept_crs' => 'EPSG:4326',
				'x_doelbinding' => 'BRPACT',
				'if_match' => '"etag"',
			],
			$forwarded
		);
	}//end testCredentialHeadersAreNotForwarded()
}//end class
