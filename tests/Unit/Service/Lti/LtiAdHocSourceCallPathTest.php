<?php

/**
 * The LTI services' ad-hoc outbound calls through the real CallService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/lti-platform/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use GuzzleHttp\Psr7\Response;
use Jose\Component\KeyManagement\JWKFactory;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Lti\LtiAgsService;
use OCA\Integriq\Service\Lti\LtiJwksResolverService;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiRegistrationResolverService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Twig\Loader\ArrayLoader;

/**
 * The JWKS resolver and the AGS dispatcher call out through a Source built on
 * the spot, whose uuid (`lti-jwks-adhoc-<registration>`, `lti-ags-adhoc`) is
 * not a uuid. The register's `call_log.source` is `format: uuid`, so a call
 * log naming that source is refused, and that refusal failed every JWKS fetch
 * after the key set had arrived: no LTI token could ever be issued.
 *
 * These tests run the real CallService over a stubbed HTTP client, with an
 * object store that refuses a call log the way the register does.
 */
class LtiAdHocSourceCallPathTest extends TestCase {

	/**
	 * Call logs the store accepted.
	 *
	 * @var array<int, array>
	 */
	private array $savedCallLogs = [];

	/**
	 * The register declares `call_log.source` as a uuid: the refusal the store
	 * double imitates is the register's own rule, not an invention of the test.
	 *
	 * @return void
	 */
	public function testTheRegisterRequiresAUuidForTheCallLogSource(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/integriq_register.json'), true);

		$this->assertSame('uuid', $register['components']['schemas']['call_log']['properties']['source']['format']);

	}//end testTheRegisterRequiresAUuidForTheCallLogSource()

	/**
	 * A tool's key set fetched through the real CallService resolves.
	 *
	 * @return void
	 */
	public function testAToolKeySetResolvesThroughTheRealCallService(): void {
		$jwk = JWKFactory::createRSAKey(2048, ['kid' => 'tool-kid', 'alg' => 'RS256', 'use' => 'sig']);
		$callService = $this->callServiceAnswering(body: (string)json_encode(['keys' => [$jwk->toPublic()->jsonSerialize()]]));

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(static fn () => new ArrayCache());
		$resolver = new LtiJwksResolverService($cacheFactory, $callService, new NullLogger());

		$key = $resolver->resolveKey('lti_tool', 'be4a7a9d-e613-495c-8d3f-09e3fc0a6b25', 'http://tool.example:8765/jwks.php', 'tool-kid');

		$this->assertNotNull($key, 'the key set arrived, so the kid must resolve');
		$this->assertSame('tool-kid', $key->get('kid'));
		$this->assertSame([], $this->savedCallLogs, 'an ad-hoc fetch writes no call log');

	}//end testAToolKeySetResolvesThroughTheRealCallService()

	/**
	 * An AGS call through the ad-hoc source answers with the response.
	 *
	 * @return void
	 */
	public function testAnAgsCallAnswersThroughTheRealCallService(): void {
		$callService = $this->callServiceAnswering(body: '{"resultUrl":"https://platform.example/results/1"}');

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(static fn () => new ArrayCache());

		$service = new LtiAgsService(
			$this->createMock(LtiRegistrationResolverService::class),
			$this->createMock(LtiLaunchService::class),
			$this->createMock(LtiKeyService::class),
			$this->createMock(AuthenticationService::class),
			$callService,
			$this->createMock(EventService::class),
			$cacheFactory,
			new NullLogger()
		);

		$dispatch = new ReflectionMethod(LtiAgsService::class, 'dispatchAgsCall');
		$dispatch->setAccessible(true);
		$result = $dispatch->invoke($service, 'https://platform.example/ags/lineitems/7/scores', 'POST', 'access-token', ['scoreGiven' => 8]);

		$this->assertSame(200, $result['statusCode']);
		$this->assertSame([], $this->savedCallLogs, 'an ad-hoc AGS call writes no call log');

	}//end testAnAgsCallAnswersThroughTheRealCallService()

	/**
	 * A real CallService whose HTTP client answers 200 with the body, over a
	 * store that refuses a call log whose `source` is not a uuid.
	 *
	 * @param string $body The response body.
	 *
	 * @return CallService
	 */
	private function callServiceAnswering(string $body): CallService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			// Parameter order of tests/stubs/OCA/OpenRegister/Service/ObjectService.php.
			function ($object = [], $register = null, $schema = null) {
				if ($schema === 'call_log') {
					$source = (string)($object['source'] ?? '');
					if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $source) !== 1) {
						throw new RuntimeException("Property 'source' should match format 'uuid' but '" . $source . "' does not.");
					}

					$this->savedCallLogs[] = $object;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('00000000-0000-4000-8000-000000000001');
				$entity->setObject(is_array($object) === true ? $object : []);

				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$service = new CallService(
			$objectService,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
		);

		$client = $this->createMock(\GuzzleHttp\Client::class);
		$client->method('request')->willReturn(new Response(200, ['Content-Type' => 'application/json'], $body));
		$property = new ReflectionProperty(CallService::class, 'client');
		$property->setAccessible(true);
		$property->setValue($service, $client);

		return $service;

	}//end callServiceAnswering()
}//end class
