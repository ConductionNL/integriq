<?php

/**
 * Unit tests for the LTI platform launch: login initiation and authorization.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-the-platform-authorizes-the-tools-login-redirect-and-posts-the-launch-token-req-ltil-002
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use OCA\Integriq\Controller\LtiPlatformController;
use OCA\Integriq\Event\LtiLaunchRequestedEvent;
use OCA\Integriq\EventListener\LtiLaunchRequestedListener;
use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\AuthorizationService;
use OCA\Integriq\Service\Lti\LtiAgsService;
use OCA\Integriq\Service\Lti\LtiCustomParameterReader;
use OCA\Integriq\Service\Lti\LtiJwksResolverService;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiPlatformHint;
use OCA\Integriq\Service\Lti\LtiPlatformLoginService;
use OCA\Integriq\Service\Lti\LtiRegistrationResolverService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The platform launch runs the LTI 1.3 third-party-initiated login: the event
 * answers with a form to the tool's OIDC login URL, and the authorization
 * endpoint posts an id_token carrying the tool's own nonce and state.
 */
class LtiPlatformLaunchTest extends TestCase {

	private const TOOL_UUID = 'tool-1';
	private const DEPLOYMENT_UUID = 'dep-1';
	private const CLIENT_ID = 'tool-client';
	private const LOGIN_URL = 'https://tool.example/login';
	private const LAUNCH_URL = 'https://tool.example/launch';
	private const REDIRECT_URI = 'https://tool.example/lti/redirect';

	/**
	 * The clock every collaborator reads.
	 *
	 * @var integer
	 */
	private int $now = 1790000000;

	/**
	 * The signed-in user's uid, or null for no session.
	 *
	 * @var string|null
	 */
	private ?string $sessionUid = 'learner-1';

	/**
	 * The tool registration's data.
	 *
	 * @var array<string, mixed>
	 */
	private array $tool = [];

	/**
	 * In-memory registrations for the real LtiKeyService.
	 *
	 * @var array<string, array>
	 */
	private array $registrations = [];

	/**
	 * Synchronization contract rows in the contract store.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $contracts = [];

	/**
	 * Synchronizations by OpenRegister id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $synchronizations = [];

	/**
	 * Whether reading the contract store throws.
	 *
	 * @var boolean
	 */
	private bool $contractStoreFails = false;

	/**
	 * Build the tool registration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tool = [
			'clientId' => self::CLIENT_ID,
			'oidcLoginUrl' => self::LOGIN_URL,
			'launchUrl' => self::LAUNCH_URL,
			'redirectUris' => [self::REDIRECT_URI],
			'status' => 'approved',
		];
		$this->registrations = [self::TOOL_UUID => ['signingKeys' => []]];

	}//end setUp()

	// ---------------------------------------------------------------------
	// REQ-LTIL-001: the launch event answers with a login initiation.
	// ---------------------------------------------------------------------

	/**
	 * The launch starts at the tool's OIDC login URL with the six fields,
	 * not with a signed id_token posted to the launch URL (integriq#1508).
	 *
	 * @return void
	 */
	public function testLaunchEventReturnsLoginInitiationToToolOidcLoginUrl(): void {
		$event = $this->raise();

		$this->assertNull($event->getRefusal());
		$form = $event->getLoginInitiation();
		$this->assertIsArray($form);
		$this->assertSame(self::LOGIN_URL, $form['formActionUrl']);
		$this->assertSame('POST', $form['method']);
		$this->assertSame(
			['iss', 'login_hint', 'lti_message_hint', 'target_link_uri', 'client_id', 'lti_deployment_id'],
			array_keys($form['fields'])
		);
		$this->assertSame('https://nc.example', $form['fields']['iss']);
		$this->assertSame(self::LAUNCH_URL, $form['fields']['target_link_uri']);
		$this->assertSame(self::CLIENT_ID, $form['fields']['client_id']);
		$this->assertSame('deploy-42', $form['fields']['lti_deployment_id']);
		$this->assertNotSame('', $form['fields']['login_hint']);
		$this->assertSame($form['fields']['login_hint'], $form['fields']['lti_message_hint']);
		$this->assertArrayNotHasKey('id_token', $form['fields'], 'no id_token before the tool has logged in');

	}//end testLaunchEventReturnsLoginInitiationToToolOidcLoginUrl()

	/**
	 * A suspended tool does not open, and the refusal names the status.
	 *
	 * @return void
	 */
	public function testSuspendedToolIsRefusedNamingItsStatus(): void {
		$this->tool['status'] = 'suspended';

		$event = $this->raise();

		$this->assertNull($event->getLoginInitiation());
		$this->assertSame('tool-not-approved', $event->getRefusal()['code']);
		$this->assertStringContainsString('suspended', $event->getRefusal()['reason']);
		$this->assertTrue($event->isHandled());

	}//end testSuspendedToolIsRefusedNamingItsStatus()

	/**
	 * A launch for another user than the signed-in one is refused.
	 *
	 * @return void
	 */
	public function testLaunchForAnotherUserIsRefused(): void {
		$event = $this->raise(userId: 'someone-else');

		$this->assertNull($event->getLoginInitiation());
		$this->assertSame('user-mismatch', $event->getRefusal()['code']);

	}//end testLaunchForAnotherUserIsRefused()

	/**
	 * An unknown deployment is refused.
	 *
	 * @return void
	 */
	public function testUnknownDeploymentIsRefused(): void {
		$event = $this->raise(deploymentUuid: 'no-such-deployment');

		$this->assertSame('deployment-unknown', $event->getRefusal()['code']);

	}//end testUnknownDeploymentIsRefused()

	/**
	 * A hint older than five minutes is rejected as expired.
	 *
	 * @return void
	 */
	public function testHintOlderThanFiveMinutesIsRejectedAsExpired(): void {
		$hint = $this->makeHint()->issue(context: ['userId' => 'learner-1']);
		$this->now += (LtiPlatformHint::TTL_SECONDS + 1);

		try {
			$this->makeHint()->verify(hint: $hint);
			$this->fail('an expired hint must not verify');
		} catch (LtiValidationException $exception) {
			$this->assertSame('hint-expired', $exception->getDetails()['check']);
		}

	}//end testHintOlderThanFiveMinutesIsRejectedAsExpired()

	/**
	 * A hint whose payload was changed does not verify.
	 *
	 * @return void
	 */
	public function testTamperedHintIsRejected(): void {
		$hint = $this->makeHint()->issue(context: ['userId' => 'learner-1', 'placementId' => 'p-1']);
		[$body, $signature] = explode('.', $hint);
		$payload = json_decode((string)base64_decode(strtr($body, '-_', '+/')), true);
		$payload['placementId'] = 'p-2';
		$forged = rtrim(strtr(base64_encode((string)json_encode($payload)), '+/', '-_'), '=') . '.' . $signature;

		$this->expectException(LtiValidationException::class);
		$this->makeHint()->verify(hint: $forged);

	}//end testTamperedHintIsRejected()

	// ---------------------------------------------------------------------
	// REQ-LTIL-002: the authorization endpoint.
	// ---------------------------------------------------------------------

	/**
	 * A valid redirect from the tool is answered with a form posting an
	 * id_token that carries the tool's nonce, and the tool's state, to the
	 * registered redirect URI.
	 *
	 * @return void
	 */
	public function testAuthorizePostsIdTokenWithToolNonceAndState(): void {
		$response = $this->authorize(params: $this->toolRedirect());

		$this->assertSame('lti-autopost', $response->getTemplateName());
		$params = $response->getParams();
		$this->assertSame(self::REDIRECT_URI, $params['redirectUri']);
		$this->assertSame('tool-state-1', $params['state']);

		$claims = $this->claimsOf(idToken: $params['idToken']);
		$this->assertSame('tool-nonce-1', $claims['nonce']);
		$this->assertSame('https://nc.example', $claims['iss']);
		$this->assertSame(self::CLIENT_ID, $claims['aud']);
		$this->assertSame('learner-1', $claims['sub']);
		$this->assertSame('deploy-42', $claims[LtiLaunchService::CLAIM_DEPLOYMENT_ID]);
		$this->assertSame(['id' => 'placement-7'], $claims[LtiLaunchService::CLAIM_RESOURCE_LINK]);
		$this->assertSame([LtiPlatformLoginService::LIS_ROLES['Learner']], $claims[LtiPlatformLoginService::CLAIM_ROLES]);
		$this->assertSame(self::LAUNCH_URL, $claims[LtiPlatformLoginService::CLAIM_TARGET_LINK_URI]);
		$this->assertSame(['id' => 'course-3', 'title' => 'Biology'], $claims[LtiPlatformLoginService::CLAIM_CONTEXT]);

		// REQ-LTIL-003: the grade service claim names the placement's line item on
		// this deployment, with the scopes to read it and post scores to it.
		$this->assertSame(
			[
				'scope' => [LtiAgsService::SCOPE_LINEITEM_READONLY, LtiAgsService::SCOPE_SCORE],
				'lineitem' => 'https://nc.example/index.php/apps/integriq/api/lti/' . self::DEPLOYMENT_UUID . '/ags/lineitems/placement-7',
			],
			$claims[LtiPlatformLoginService::CLAIM_AGS_ENDPOINT]
		);
		$this->assertSame(
			[LtiAgsService::SCOPE_LINEITEM_READONLY, LtiAgsService::SCOPE_SCORE],
			array_values(array_intersect($claims[LtiPlatformLoginService::CLAIM_AGS_ENDPOINT]['scope'], LtiAgsService::ALLOWED_SCOPES)),
			'every scope the claim offers must be one the token endpoint grants'
		);

		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		$this->assertStringContainsString('https://tool.example', $policy, 'the form may post to the tool origin');

	}//end testAuthorizePostsIdTokenWithToolNonceAndState()

	/**
	 * Another signed-in user's browser presenting the hint gets an error page
	 * naming the user check, and nothing is posted.
	 *
	 * @return void
	 */
	public function testAuthorizeRefusesAnotherUsersHint(): void {
		$params = $this->toolRedirect();
		$this->sessionUid = 'another-learner';

		$response = $this->authorize(params: $params);

		$this->assertErrorPage(response: $response, check: 'user', status: 403);

	}//end testAuthorizeRefusesAnotherUsersHint()

	/**
	 * An unregistered redirect URI is refused.
	 *
	 * @return void
	 */
	public function testAuthorizeRefusesUnregisteredRedirectUri(): void {
		$params = $this->toolRedirect();
		$params['redirect_uri'] = 'https://evil.example/collect';

		$this->assertErrorPage(response: $this->authorize(params: $params), check: 'redirect_uri', status: 400);

	}//end testAuthorizeRefusesUnregisteredRedirectUri()

	/**
	 * A request without the tool's nonce is refused.
	 *
	 * @return void
	 */
	public function testAuthorizeRefusesMissingNonce(): void {
		$params = $this->toolRedirect();
		unset($params['nonce']);

		$this->assertErrorPage(response: $this->authorize(params: $params), check: 'nonce', status: 400);

	}//end testAuthorizeRefusesMissingNonce()

	/**
	 * A client id that is not the hint's tool is refused.
	 *
	 * @return void
	 */
	public function testAuthorizeRefusesAnotherClientId(): void {
		$params = $this->toolRedirect();
		$params['client_id'] = 'other-tool';

		$this->assertErrorPage(response: $this->authorize(params: $params), check: 'client_id', status: 400);

	}//end testAuthorizeRefusesAnotherClientId()

	/**
	 * A tool with no registered redirect URIs may only return to its launch URL.
	 *
	 * @return void
	 */
	public function testToolWithoutRedirectUrisMayOnlyUseItsLaunchUrl(): void {
		unset($this->tool['redirectUris']);
		$params = $this->toolRedirect();

		$this->assertErrorPage(response: $this->authorize(params: $params), check: 'redirect_uri', status: 400);

		$params['redirect_uri'] = self::LAUNCH_URL;
		$this->assertSame('lti-autopost', $this->authorize(params: $params)->getTemplateName());

	}//end testToolWithoutRedirectUrisMayOnlyUseItsLaunchUrl()

	// ---------------------------------------------------------------------
	// REQ-CMKT-001 (connectors-course-marketplace Task 7): a placement a course
	// marketplace synchronization wrote tells the tool which course to open.
	// ---------------------------------------------------------------------

	/**
	 * Launching a placement the Go1 placement synchronization wrote sends the
	 * Go1 course id (the contract's origin id) in the LTI custom claim, under
	 * the name the synchronization declares. The synchronization is the real
	 * one from the shipped fragment.
	 *
	 * @return void
	 */
	public function testAMarketplacePlacementTellsTheToolWhichCourseToOpen(): void {
		$sync = $this->shippedSynchronization(slug: 'course-marketplace-go1-placement');
		$this->synchronizations = ['sync-go1-placement' => $sync];
		$this->contracts = [
			[
				'synchronizationId' => 'sync-go1-placement',
				'originId' => 'go1-course-123',
				'targetId' => 'placement-7',
			],
		];

		$response = $this->authorize(params: $this->toolRedirect());
		$claims = $this->claimsOf(idToken: $response->getParams()['idToken']);

		$parameter = $sync['targetConfig']['ltiCustomOriginIdParameter'];
		$this->assertSame([$parameter => 'go1-course-123'], $claims[LtiPlatformLoginService::CLAIM_CUSTOM]);

	}//end testAMarketplacePlacementTellsTheToolWhichCourseToOpen()

	/**
	 * A placement no synchronization wrote, and one written by a
	 * synchronization that declares no custom parameter, launch without a
	 * custom claim; the rest of the launch is unchanged.
	 *
	 * @return void
	 */
	public function testAPlacementWithoutADeclaringSynchronizationCarriesNoCustomClaim(): void {
		$claims = $this->claimsOf(idToken: $this->authorize(params: $this->toolRedirect())->getParams()['idToken']);
		$this->assertArrayNotHasKey(LtiPlatformLoginService::CLAIM_CUSTOM, $claims);

		$this->synchronizations = ['sync-go1-course' => $this->shippedSynchronization(slug: 'course-marketplace-go1-course')];
		$this->contracts = [['synchronizationId' => 'sync-go1-course', 'originId' => 'go1-course-123', 'targetId' => 'placement-7']];

		$claims = $this->claimsOf(idToken: $this->authorize(params: $this->toolRedirect())->getParams()['idToken']);
		$this->assertArrayNotHasKey(LtiPlatformLoginService::CLAIM_CUSTOM, $claims);
		$this->assertSame(['id' => 'placement-7'], $claims[LtiLaunchService::CLAIM_RESOURCE_LINK]);

	}//end testAPlacementWithoutADeclaringSynchronizationCarriesNoCustomClaim()

	/**
	 * A contract store that cannot be read does not stop the launch: the
	 * token goes out without the custom claim.
	 *
	 * @return void
	 */
	public function testAnUnreadableContractStoreDoesNotStopTheLaunch(): void {
		$this->contractStoreFails = true;

		$response = $this->authorize(params: $this->toolRedirect());

		$this->assertSame('lti-autopost', $response->getTemplateName());
		$this->assertArrayNotHasKey(LtiPlatformLoginService::CLAIM_CUSTOM, $this->claimsOf(idToken: $response->getParams()['idToken']));

	}//end testAnUnreadableContractStoreDoesNotStopTheLaunch()

	/**
	 * Every marketplace placement synchronization declares the parameter, and
	 * the course and lesson synchronizations do not (their targets are not launched).
	 *
	 * @return void
	 */
	public function testEveryMarketplacePlacementSynchronizationDeclaresTheParameter(): void {
		foreach (['go1', 'linkedin-learning', 'udemy-business'] as $provider) {
			$placement = $this->shippedSynchronization(slug: 'course-marketplace-' . $provider . '-placement');
			$this->assertIsString($placement['targetConfig']['ltiCustomOriginIdParameter'] ?? null, $provider);
			$this->assertNotSame('', $placement['targetConfig']['ltiCustomOriginIdParameter'], $provider);

			foreach (['course', 'lesson'] as $kind) {
				$other = $this->shippedSynchronization(slug: 'course-marketplace-' . $provider . '-' . $kind);
				$this->assertArrayNotHasKey('ltiCustomOriginIdParameter', (array)($other['targetConfig'] ?? []), $provider . ' ' . $kind);
			}
		}

	}//end testEveryMarketplacePlacementSynchronizationDeclaresTheParameter()

	// ---------------------------------------------------------------------
	// Fixture.
	// ---------------------------------------------------------------------

	/**
	 * Raise the launch event through the real listener.
	 *
	 * @param string $userId The uid the launch is for.
	 * @param string $deploymentUuid The deployment.
	 *
	 * @return LtiLaunchRequestedEvent The answered event.
	 */
	private function raise(string $userId = 'learner-1', string $deploymentUuid = self::DEPLOYMENT_UUID): LtiLaunchRequestedEvent {
		$event = new LtiLaunchRequestedEvent(
			sourceApp: 'learniq',
			placementId: 'placement-7',
			deploymentUuid: $deploymentUuid,
			userId: $userId,
			messageType: 'LtiResourceLinkRequest',
			role: 'Learner',
			contextId: 'course-3',
			contextTitle: 'Biology',
			returnUrl: 'https://nc.example/apps/learniq/lesson/9'
		);

		(new LtiLaunchRequestedListener($this->makeLoginService(), new NullLogger()))->handle($event);

		return $event;

	}//end raise()

	/**
	 * The parameters a tool sends back after the login initiation.
	 *
	 * @return array<string, string>
	 */
	private function toolRedirect(): array {
		$fields = $this->raise()->getLoginInitiation()['fields'];

		return [
			'scope' => 'openid',
			'response_type' => 'id_token',
			'response_mode' => 'form_post',
			'prompt' => 'none',
			'client_id' => $fields['client_id'],
			'redirect_uri' => self::REDIRECT_URI,
			'login_hint' => $fields['login_hint'],
			'lti_message_hint' => $fields['lti_message_hint'],
			'state' => 'tool-state-1',
			'nonce' => 'tool-nonce-1',
		];

	}//end toolRedirect()

	/**
	 * Call the authorization endpoint through the real controller.
	 *
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return TemplateResponse
	 */
	private function authorize(array $params): TemplateResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters)
		);

		$controller = new LtiPlatformController(
			'integriq',
			$request,
			$this->makeLoginService(),
			$this->makeUserSession(),
			$l10n,
			new NullLogger()
		);

		return $controller->authorize();

	}//end authorize()

	/**
	 * Assert an error page naming a check, with nothing to post.
	 *
	 * @param TemplateResponse $response The response.
	 * @param string $check The check it must name.
	 * @param int $status The HTTP status.
	 *
	 * @return void
	 */
	private function assertErrorPage(TemplateResponse $response, string $check, int $status): void {
		$this->assertSame('lti-error', $response->getTemplateName());
		$this->assertSame($status, $response->getStatus());
		$this->assertSame('Failed check: ' . $check, $response->getParams()['checkLabel']);
		$this->assertArrayNotHasKey('idToken', $response->getParams());

	}//end assertErrorPage()

	/**
	 * Decode a JWS payload.
	 *
	 * @param string $idToken The compact JWS.
	 *
	 * @return array<string, mixed>
	 */
	private function claimsOf(string $idToken): array {
		$parts = explode('.', $idToken);

		return json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);

	}//end claimsOf()

	/**
	 * Build the login service over the fixture.
	 *
	 * @return LtiPlatformLoginService
	 */
	private function makeLoginService(): LtiPlatformLoginService {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://nc.example' . $url);
		// Resolve a route the way the router does: from the app's own routes.php,
		// so a claim pointing at a route that does not exist cannot pass.
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $routeName, array $arguments = []): string {
				$routes = require __DIR__ . '/../../../../appinfo/routes.php';
				foreach ($routes['routes'] as $route) {
					if ('integriq.' . str_replace('#', '.', $route['name']) === $routeName && ($route['verb'] ?? 'GET') === 'GET') {
						$url = $route['url'];
						foreach ($arguments as $key => $value) {
							$url = str_replace('{' . $key . '}', (string)$value, $url);
						}

						return 'https://nc.example/index.php/apps/integriq' . $url;
					}
				}

				throw new \RuntimeException('No GET route named ' . $routeName);
			}
		);

		return new LtiPlatformLoginService(
			$this->makeResolver(),
			$this->makeLaunchService(),
			$this->makeHint(),
			$this->makeUserSession(),
			$urlGenerator,
			$this->makeCustomParameterReader()
		);

	}//end makeLoginService()

	/**
	 * The real custom parameter reader over the real contract service, with
	 * OpenRegister answering from the fixture's contracts and synchronizations.
	 *
	 * @return LtiCustomParameterReader
	 */
	private function makeCustomParameterReader(): LtiCustomParameterReader {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				if ($this->contractStoreFails === true) {
					throw new \RuntimeException('contract store unavailable');
				}

				$filters = ($config['filters'] ?? []);
				$this->assertSame('synchronization_contract', $filters['schema'] ?? null, 'only the contract store is searched');
				$results = [];
				foreach ($this->contracts as $index => $row) {
					if (($filters['targetId'] ?? null) === $row['targetId']) {
						$results[] = $this->entity(uuid: 'contract-' . $index, data: $row);
					}
				}

				return ['results' => $results];
			}
		);
		$objectService->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null): ?ObjectEntity {
				$this->assertSame('synchronization', $schema, 'a contract names a synchronization');
				if (isset($this->synchronizations[(string)$id]) === false) {
					return null;
				}

				return $this->entity(uuid: (string)$id, data: $this->synchronizations[(string)$id]);
			}
		);

		return new LtiCustomParameterReader(
			new SynchronizationContractService($objectService),
			$objectService,
			new NullLogger()
		);

	}//end makeCustomParameterReader()

	/**
	 * A synchronization as the course marketplace fragment ships it.
	 *
	 * @param string $slug The synchronization's slug.
	 *
	 * @return array<string, mixed>
	 */
	private function shippedSynchronization(string $slug): array {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../lib/Settings/register.d/course-marketplace-connectors.json'),
			true
		);
		foreach ($fragment['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === 'synchronization' && ($object['@self']['slug'] ?? '') === $slug) {
				return $object;
			}
		}

		$this->fail('The fragment ships no synchronization ' . $slug);

	}//end shippedSynchronization()

	/**
	 * The hint service with a real HMAC and the fixture clock.
	 *
	 * @return LtiPlatformHint
	 */
	private function makeHint(): LtiPlatformHint {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new LtiPlatformHint($this->makeCrypto(), $time);

	}//end makeHint()

	/**
	 * An ICrypto whose HMAC is real and whose encryption round-trips.
	 *
	 * @return ICrypto
	 */
	private function makeCrypto(): ICrypto {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(
			static fn (string $message, string $password = ''): string => hash_hmac('sha256', $message, 'instance-secret', true)
		);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plaintext): string => 'enc:' . base64_encode($plaintext));
		$crypto->method('decrypt')->willReturnCallback(static fn (string $ciphertext): string => (string)base64_decode(substr($ciphertext, 4)));

		return $crypto;

	}//end makeCrypto()

	/**
	 * A session for the fixture user (or none).
	 *
	 * @return IUserSession
	 */
	private function makeUserSession(): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$uid = $this->sessionUid;
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;

	}//end makeUserSession()

	/**
	 * A resolver over the fixture deployment and tool, with the real method names.
	 *
	 * @return LtiRegistrationResolverService
	 */
	private function makeResolver(): LtiRegistrationResolverService {
		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findDeploymentByUuid')->willReturnCallback(
			function (string $uuid): ?ObjectEntity {
				if ($uuid !== self::DEPLOYMENT_UUID) {
					return null;
				}

				return $this->entity(uuid: $uuid, data: ['ltiToolId' => self::TOOL_UUID, 'deploymentId' => 'deploy-42']);
			}
		);
		$resolver->method('findRegistrationByUuid')->willReturnCallback(
			function (string $type, string $uuid): ?ObjectEntity {
				if ($type !== 'lti_tool' || $uuid !== self::TOOL_UUID || $this->tool['status'] !== 'approved') {
					return null;
				}

				return $this->entity(uuid: $uuid, data: $this->tool);
			}
		);
		$resolver->method('findRegistrationStatus')->willReturnCallback(
			fn (string $type, string $uuid): ?string => ($uuid === self::TOOL_UUID ? $this->tool['status'] : null)
		);

		return $resolver;

	}//end makeResolver()

	/**
	 * The real launch service, signing with a real generated tool key.
	 *
	 * @return LtiLaunchService
	 */
	private function makeLaunchService(): LtiLaunchService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			fn ($id): ObjectEntity => $this->entity(uuid: (string)$id, data: $this->registrations[$id])
		);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null): ObjectEntity {
				$this->registrations[$uuid] = $object;
				return $this->entity(uuid: (string)$uuid, data: $object);
			}
		);

		// Passes the crypto as well, so the fixture also fits the constructor
		// that encrypts keys at rest (integriq#2213).
		$keyService = new LtiKeyService($objectService, new NullLogger(), $this->makeCrypto());
		if ($this->registrations[self::TOOL_UUID]['signingKeys'] === []) {
			$keyService->generateKey('lti_tool', self::TOOL_UUID);
		}

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(new ArrayCache());

		return new LtiLaunchService(
			$this->makeResolver(),
			$this->createMock(AuthorizationService::class),
			$this->createMock(LtiJwksResolverService::class),
			$keyService,
			$cacheFactory,
			new NullLogger()
		);

	}//end makeLaunchService()

	/**
	 * An ObjectEntity carrying data.
	 *
	 * @param string $uuid The uuid.
	 * @param array $data The object data.
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
