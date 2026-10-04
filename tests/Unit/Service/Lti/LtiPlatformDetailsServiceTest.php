<?php

/**
 * Unit tests for the platform details a tool's administrator enters at the vendor.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use OCA\Integriq\Controller\LtiPlatformDetailsController;
use OCA\Integriq\Service\Lti\LtiCustomParameterReader;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiPlatformDetailsService;
use OCA\Integriq\Service\Lti\LtiPlatformHint;
use OCA\Integriq\Service\Lti\LtiPlatformLoginService;
use OCA\Integriq\Service\Lti\LtiRegistrationResolverService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The detail view of a tool registration shows the six values the tool's
 * administrator pastes into the vendor's platform registration. Every URL
 * here must be one this instance answers, so the URL generator resolves
 * route names from the app's own routes.php: a value pointing at a route
 * that does not exist cannot pass.
 */
class LtiPlatformDetailsServiceTest extends TestCase {

	private const TOOL_UUID = 'tool-1';

	/**
	 * Tool registrations by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $tools = [];

	/**
	 * Deployment rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $deployments = [];

	/**
	 * Build a pending tool with two deployments, and one deployment of another tool.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tools = [
			self::TOOL_UUID => [
				'clientId' => 'tool-client',
				'launchUrl' => 'https://tool.example/launch',
				'status' => 'pending',
			],
		];
		$this->deployments = [
			['deploymentId' => 'dep-a', 'ltiToolId' => self::TOOL_UUID],
			['deploymentId' => 'dep-b', 'ltiToolId' => self::TOOL_UUID],
			['deploymentId' => 'dep-other', 'ltiToolId' => 'tool-2'],
		];

	}//end setUp()

	/**
	 * The six values, each pointing at a route this instance answers.
	 *
	 * @return void
	 */
	public function testTheSixValuesAreTheOnesThisInstanceAnswersOn(): void {
		$details = $this->makeService()->forTool(toolUuid: self::TOOL_UUID);

		$this->assertSame(
			[
				'issuer' => 'https://nc.example',
				'clientId' => 'tool-client',
				'deploymentIds' => ['dep-a', 'dep-b'],
				'authorizationUrl' => 'https://nc.example/index.php/apps/integriq/api/lti/platform/authorize',
				'tokenUrl' => 'https://nc.example/index.php/apps/integriq/api/lti/token',
				'keySetUrl' => 'https://nc.example/index.php/apps/integriq/.well-known/lti/lti_tool/tool-1/jwks.json',
			],
			$details
		);

	}//end testTheSixValuesAreTheOnesThisInstanceAnswersOn()

	/**
	 * The issuer shown is the issuer the launch signs with, not a second copy.
	 *
	 * @return void
	 */
	public function testTheIssuerIsTheOneTheLaunchSignsWith(): void {
		$loginService = new LtiPlatformLoginService(
			$this->createMock(LtiRegistrationResolverService::class),
			$this->createMock(LtiLaunchService::class),
			$this->createMock(LtiPlatformHint::class),
			$this->createMock(IUserSession::class),
			$this->makeUrlGenerator(),
			$this->createMock(LtiCustomParameterReader::class)
		);

		$details = $this->makeService()->forTool(toolUuid: self::TOOL_UUID);

		$this->assertSame($loginService->platformIssuer(), $details['issuer']);

	}//end testTheIssuerIsTheOneTheLaunchSignsWith()

	/**
	 * A pending tool still gets its details: the administrator needs them to
	 * register at the vendor before the registration is approved.
	 *
	 * @return void
	 */
	public function testAPendingToolStillGetsItsDetails(): void {
		$this->assertSame('pending', $this->tools[self::TOOL_UUID]['status']);
		$this->assertNotNull($this->makeService()->forTool(toolUuid: self::TOOL_UUID));

	}//end testAPendingToolStillGetsItsDetails()

	/**
	 * A tool without deployments gets an empty list, not a missing key.
	 *
	 * @return void
	 */
	public function testAToolWithoutDeploymentsGetsAnEmptyList(): void {
		$this->deployments = [];

		$details = $this->makeService()->forTool(toolUuid: self::TOOL_UUID);

		$this->assertSame([], $details['deploymentIds']);

	}//end testAToolWithoutDeploymentsGetsAnEmptyList()

	/**
	 * An unknown tool answers null.
	 *
	 * @return void
	 */
	public function testAnUnknownToolAnswersNull(): void {
		$this->assertNull($this->makeService()->forTool(toolUuid: 'missing'));
		$this->assertNull($this->makeService()->forTool(toolUuid: ''));

	}//end testAnUnknownToolAnswersNull()

	/**
	 * The controller answers the details, and 404 for an unknown tool.
	 *
	 * @return void
	 */
	public function testTheControllerAnswersTheDetailsAnd404ForAnUnknownTool(): void {
		$controller = new LtiPlatformDetailsController(
			$this->createMock(IRequest::class),
			$this->makeService()
		);

		$found = $controller->show(id: self::TOOL_UUID);
		$this->assertSame(200, $found->getStatus());
		$this->assertSame('tool-client', $found->getData()['clientId']);

		$missing = $controller->show(id: 'missing');
		$this->assertSame(404, $missing->getStatus());
		$this->assertArrayHasKey('error', $missing->getData());

	}//end testTheControllerAnswersTheDetailsAnd404ForAnUnknownTool()

	/**
	 * Only an administrator reads the details, and the route exists.
	 *
	 * @return void
	 */
	public function testOnlyAnAdministratorReadsTheDetailsOnARegisteredRoute(): void {
		$method = new ReflectionMethod(LtiPlatformDetailsController::class, 'show');
		$attributes = $method->getAttributes(AuthorizedAdminSetting::class);
		$this->assertCount(1, $attributes);
		$this->assertSame([IntegriqAdmin::class], $attributes[0]->getArguments());
		$this->assertSame([], $method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class));
		$this->assertSame([], $method->getAttributes(\OCP\AppFramework\Http\Attribute\PublicPage::class));

		$routes = require __DIR__ . '/../../../../appinfo/routes.php';
		$matches = array_filter(
			$routes['routes'],
			static fn (array $route): bool => $route['name'] === 'ltiPlatformDetails#show'
				&& $route['url'] === '/api/lti/tools/{id}/platform-details'
				&& ($route['verb'] ?? 'GET') === 'GET'
		);
		$this->assertCount(1, $matches);

	}//end testOnlyAnAdministratorReadsTheDetailsOnARegisteredRoute()

	/**
	 * The service over the fixture, with the real resolver.
	 *
	 * @return LtiPlatformDetailsService
	 */
	private function makeService(): LtiPlatformDetailsService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null): ObjectEntity {
				$this->assertSame('lti_tool', $schema, 'only a tool registration is read');
				if (isset($this->tools[(string)$id]) === false) {
					throw new DoesNotExistException('no such tool');
				}

				return $this->entity(uuid: (string)$id, data: $this->tools[(string)$id]);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = ($config['filters'] ?? []);
				$this->assertSame('lti_deployment', $filters['schema'] ?? null, 'only deployments are searched');
				$results = [];
				foreach ($this->deployments as $index => $row) {
					$results[] = $this->entity(uuid: 'deployment-' . $index, data: $row);
				}

				return ['results' => $results];
			}
		);

		return new LtiPlatformDetailsService(
			new LtiRegistrationResolverService($objectService, new NullLogger()),
			$objectService,
			$this->makeUrlGenerator()
		);

	}//end makeService()

	/**
	 * A URL generator that resolves route names from routes.php.
	 *
	 * @return IURLGenerator
	 */
	private function makeUrlGenerator(): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://nc.example' . $url);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $routeName, array $arguments = []): string {
				$routes = require __DIR__ . '/../../../../appinfo/routes.php';
				foreach ($routes['routes'] as $route) {
					if ('integriq.' . str_replace('#', '.', $route['name']) === $routeName) {
						$url = $route['url'];
						foreach ($arguments as $key => $value) {
							$url = str_replace('{' . $key . '}', (string)$value, $url);
						}

						return 'https://nc.example/index.php/apps/integriq' . $url;
					}
				}

				throw new \RuntimeException('No route named ' . $routeName);
			}
		);

		return $urlGenerator;

	}//end makeUrlGenerator()

	/**
	 * An OpenRegister object.
	 *
	 * @param string $uuid The uuid.
	 * @param array  $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;

	}//end entity()
}//end class
