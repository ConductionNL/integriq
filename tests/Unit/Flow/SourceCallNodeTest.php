<?php

/**
 * Unit tests for SourceCallNode (`openconnector.source-call`).
 *
 * Covers the whole normative surface of the node: Source-only targeting and
 * SSRF containment at BOTH validation and execute time, per-item execution and
 * item shape, explicit failure (including the regression test asserting the
 * `HermiqAgentNode` empty-success flaw is NOT reproduced), `acceptStatuses`,
 * transport failure, fail-closed attribution, and save-time validation.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Flow;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use OCA\OpenConnector\Exception\FlowNodeException;
use OCA\OpenConnector\Flow\FlowNodeSupport;
use OCA\OpenConnector\Flow\FlowOwner;
use OCA\OpenConnector\Flow\SourceCallNode;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowConcurrency;
use OCA\OpenRegister\Service\ObjectService as OpenRegisterObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tests for the governed outbound-call flow node.
 */
class SourceCallNodeTest extends TestCase {

	/**
	 * The call engine double.
	 *
	 * @var CallService&MockObject
	 */
	private $callService;

	/**
	 * The OpenRegister object service double.
	 *
	 * @var OpenRegisterObjectService&MockObject
	 */
	private $objectService;

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private $userManager;

	/**
	 * The user session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * The node under test.
	 *
	 * @var SourceCallNode
	 */
	private SourceCallNode $node;

	/**
	 * Build the node with doubles for everything it delegates to.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->callService = $this->createMock(CallService::class);
		$this->objectService = $this->createMock(OpenRegisterObjectService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				if (is_array($parameters) === false || $parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/openconnector/img/flow-source-call.svg');

		$this->node = new SourceCallNode(
			callService: $this->callService,
			concurrency: new FlowConcurrency(),
			objectService: $this->objectService,
			flowOwner: new FlowOwner(
				userManager: $this->userManager,
				userSession: $this->userSession,
				l10n: $l10n
			),
			l10n: $l10n,
			urlGenerator: $urlGenerator,
			logger: $this->logger
		);

	}//end setUp()

	/**
	 * The palette metadata is present and app-namespaced.
	 *
	 * @return void
	 */
	public function testPaletteMetadata(): void {
		$this->assertSame('openconnector.source-call', $this->node->getId());
		$this->assertNotSame('', $this->node->getDisplayName());
		$this->assertNotSame('', $this->node->getDescription());
		$this->assertNotSame('', $this->node->getIcon());

	}//end testPaletteMetadata()


	/**
	 * The declared vocabulary is what the node actually reads.
	 *
	 * The preflight refuses keys outside this list and the flow editor renders
	 * one field per entry, so a key the node reads but does not declare becomes
	 * unreachable through both — this pins the two against each other.
	 *
	 * @return void
	 */
	public function testConfigKeysNameTheVocabularyTheNodeReads(): void {
		$this->assertSame(
			['source', 'endpoint', 'method', 'query', 'headers', 'body', 'output', 'concurrency'],
			$this->node->configKeys()
		);

	}//end testConfigKeysNameTheVocabularyTheNodeReads()

	/**
	 * Every form field edits a key the node reads, and `source` is a picker
	 * fed by the app's own sources listing — never a bare uuid box.
	 *
	 * @return void
	 */
	public function testConfigFormDescribesOnlyKeysTheNodeReads(): void {
		$form = $this->node->configForm();
		$keys = $this->node->configKeys();

		$this->assertNotSame([], $form);
		$byKey = [];
		foreach ($form as $field) {
			$this->assertContains($field['key'], $keys);
			$byKey[$field['key']] = $field;
		}

		$this->assertSame('select', $byKey['source']['type']);
		$this->assertTrue($byKey['source']['required']);
		$this->assertNotSame('', (string) ($byKey['source']['optionsFrom'] ?? ''));

	}//end testConfigFormDescribesOnlyKeysTheNodeReads()

	/**
	 * Scope is answered with Nextcloud's constants and false for anything else.
	 *
	 * @return void
	 */
	public function testIsAvailableForScope(): void {
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($this->node->isAvailableForScope(99));

	}//end testIsAvailableForScope()

	/**
	 * A step naming no source is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsMissingSource(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/source/');

		$this->node->validateConfig(['endpoint' => '/get', 'method' => 'GET']);

	}//end testValidateRejectsMissingSource()

	/**
	 * A step naming no endpoint is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsBlankEndpoint(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/endpoint/');

		$this->node->validateConfig(['source' => 'demo-echo-api', 'endpoint' => '  ', 'method' => 'GET']);

	}//end testValidateRejectsBlankEndpoint()

	/**
	 * An unsupported method is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsUnsupportedMethod(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/method/');

		$this->node->validateConfig(['source' => 'demo-echo-api', 'endpoint' => '/get', 'method' => 'TRACE']);

	}//end testValidateRejectsUnsupportedMethod()

	/**
	 * A malformed `acceptStatuses` is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsMalformedAcceptStatuses(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/acceptStatuses/');

		$this->node->validateConfig(
			[
				'source' => 'demo-echo-api',
				'endpoint' => '/get',
				'method' => 'GET',
				'acceptStatuses' => ['200', 404],
			]
		);

	}//end testValidateRejectsMalformedAcceptStatuses()

	/**
	 * An endpoint that is not relative to the Source is rejected at save.
	 *
	 * @param string $endpoint The offending endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider escapingEndpointProvider
	 */
	public function testValidateRejectsEscapingEndpoint(string $endpoint): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node->validateConfig(
			['source' => 'demo-echo-api', 'endpoint' => $endpoint, 'method' => 'GET']
		);

	}//end testValidateRejectsEscapingEndpoint()

	/**
	 * Endpoints that must never be accepted.
	 *
	 * @return array<string, array<int, string>> The cases.
	 */
	public static function escapingEndpointProvider(): array {
		return [
			'absolute url' => ['https://evil.example.org/steal'],
			'scheme relative' => ['//evil.example.org/steal'],
			'traversal' => ['../../evil'],
			'embedded traversal' => ['/issues/../../evil'],
			'other scheme' => ['file:///etc/passwd'],
		];

	}//end escapingEndpointProvider()

	/**
	 * A credential-bearing field is rejected at save.
	 *
	 * @param string $field The offending field name.
	 *
	 * @return void
	 *
	 * @dataProvider forbiddenFieldProvider
	 */
	public function testValidateRejectsForbiddenField(string $field): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/' . preg_quote($field, '/') . '/');

		$this->node->validateConfig(
			[
				'source' => 'demo-echo-api',
				'endpoint' => '/get',
				'method' => 'GET',
				$field => 'nope',
			]
		);

	}//end testValidateRejectsForbiddenField()

	/**
	 * Config fields that must never be accepted.
	 *
	 * @return array<string, array<int, string>> The cases.
	 */
	public static function forbiddenFieldProvider(): array {
		return [
			'token' => ['token'],
			'password' => ['password'],
			'api key' => ['apiKey'],
			'bearer' => ['bearerToken'],
			'owner' => ['owner'],
			'run as' => ['runAs'],
			'url' => ['url'],
			'host' => ['host'],
		];

	}//end forbiddenFieldProvider()

	/**
	 * An authentication request header may not be set from node config.
	 *
	 * @return void
	 */
	public function testValidateRejectsAuthorizationHeader(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/Authorization/');

		$this->node->validateConfig(
			[
				'source' => 'demo-echo-api',
				'endpoint' => '/get',
				'method' => 'GET',
				'headers' => ['Authorization' => 'Bearer nope'],
			]
		);

	}//end testValidateRejectsAuthorizationHeader()

	/**
	 * A valid configuration passes.
	 *
	 * @return void
	 */
	public function testValidateAcceptsWellFormedConfig(): void {
		$this->node->validateConfig(
			[
				'source' => 'demo-echo-api',
				'endpoint' => '/issues/{{issue.number}}/labels',
				'method' => 'POST',
				'body' => ['labels' => ['{{triage.proposedLabel}}']],
				'headers' => ['Accept' => 'application/json'],
				'acceptStatuses' => [200, 201],
				'output' => 'labelResult',
				'responseMapping' => ['labelResult.applied' => '$.labels[*].name'],
				'onError' => 'continue',
			]
		);

		$this->addToAssertionCount(1);

	}//end testValidateAcceptsWellFormedConfig()

	/**
	 * An empty input list makes no call and returns no items.
	 *
	 * @return void
	 */
	public function testEmptyInputMakesNoCall(): void {
		$this->callService->expects($this->never())->method('callAsync');

		$this->assertSame([], $this->node->execute([], $this->config(), $this->context()));

	}//end testEmptyInputMakesNoCall()

	/**
	 * Three items produce three calls and three well-paired items.
	 *
	 * @return void
	 */
	public function testThreeItemsProduceThreeCallsAndThreeItems(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->expects($this->exactly(3))
			->method('callAsync')
			->willReturn($this->promisedLog(statusCode: 200, body: '{"status":"ok"}'));

		$items = [
			['json' => ['issue' => ['number' => 1]]],
			['json' => ['issue' => ['number' => 2]]],
			['json' => ['issue' => ['number' => 3]]],
		];

		$out = $this->node->execute($items, $this->config(), $this->context());

		$this->assertCount(3, $out);
		foreach ($out as $index => $item) {
			$this->assertSame(['item' => $index], $item['pairedItem']);
			$this->assertSame(200, $item['json']['echo']['status']);
		}

	}//end testThreeItemsProduceThreeCallsAndThreeItems()

	/**
	 * Templated endpoint and body are resolved from the item.
	 *
	 * @return void
	 */
	public function testTemplatedValuesAreResolvedFromTheItem(): void {
		$this->givenSource();
		$this->givenOwner();

		$captured = [];
		$this->callService->method('callAsync')->willReturnCallback(
			function (...$arguments) use (&$captured) {
				$captured = $arguments;
				return $this->promisedLog(statusCode: 200, body: '{"status":"ok"}');
			}
		);

		$config = [
			'source' => 'demo-forge-api',
			'endpoint' => '/issues/{{issue.number}}/labels',
			'method' => 'POST',
			'body' => ['labels' => ['{{triage.proposedLabel}}']],
			'output' => 'labelResult',
		];

		$items = [
			[
				'json' => [
					'issue' => ['number' => 42],
					'triage' => ['proposedLabel' => 'needs-triage'],
				],
			],
		];

		$this->node->execute($items, $config, $this->context());

		$this->assertSame('/issues/42/labels', $captured[1]);
		$this->assertSame('POST', $captured[2]);
		$this->assertSame(['labels' => ['needs-triage']], $captured[3]['json']);

	}//end testTemplatedValuesAreResolvedFromTheItem()

	/**
	 * The response lands under the author-named key and cannot spoof provenance.
	 *
	 * @return void
	 */
	public function testResponseLandsUnderAuthorKeyOnly(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->method('callAsync')->willReturn(
			$this->promisedLog(statusCode: 200, body: '{"status":"ok","pairedItem":"spoofed"}')
		);

		$out = $this->node->execute(
			[['json' => ['issue' => ['number' => 1]]]],
			['source' => 'demo-echo-api', 'endpoint' => '/get', 'method' => 'GET', 'output' => 'labelResult'],
			$this->context()
		);

		$this->assertSame(['item' => 0], $out[0]['pairedItem']);
		$this->assertSame('ok', $out[0]['json']['labelResult']['body']['status']);
		$this->assertArrayNotHasKey('pairedItem', $out[0]['json']);

	}//end testResponseLandsUnderAuthorKeyOnly()

	/**
	 * `responseMapping` writes selected parts under author-named keys.
	 *
	 * @return void
	 */
	public function testResponseMappingWritesSelectedParts(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->method('callAsync')->willReturn(
			$this->promisedLog(statusCode: 200, body: '{"status":"ok","labels":[{"name":"bug"},{"name":"triage"}]}')
		);

		$out = $this->node->execute(
			[['json' => []]],
			[
				'source' => 'demo-forge-api',
				'endpoint' => '/get',
				'method' => 'GET',
				'output' => 'labelResult',
				'responseMapping' => [
					'mapped.applied' => '$.labels[*].name',
					'mapped.status' => '$.status',
				],
			],
			$this->context()
		);

		$this->assertSame(['bug', 'triage'], $out[0]['json']['mapped']['applied']);
		$this->assertSame('ok', $out[0]['json']['mapped']['status']);

	}//end testResponseMappingWritesSelectedParts()

	/**
	 * A rendered endpoint that escapes the Source is refused before any request.
	 *
	 * @return void
	 */
	public function testRenderedEndpointEscapeIsRefusedBeforeAnyRequest(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->expects($this->never())->method('callAsync');

		$this->expectException(UnexpectedValueException::class);

		$this->node->execute(
			[['json' => ['issue' => ['ref' => '../../evil']]]],
			['source' => 'demo-forge-api', 'endpoint' => '/issues/{{issue.ref}}', 'method' => 'GET'],
			$this->context()
		);

	}//end testRenderedEndpointEscapeIsRefusedBeforeAnyRequest()

	/**
	 * A 500 under the default policy raises, naming status, source and endpoint.
	 *
	 * @return void
	 */
	public function testFiveHundredRaisesUnderDefaultPolicy(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->method('callAsync')->willReturn(
			$this->promisedLog(statusCode: 500, body: '{"error":"boom"}', statusMessage: 'Internal Server Error')
		);

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/500/');
		$this->expectExceptionMessageMatches('/demo-echo-api/');
		$this->expectExceptionMessageMatches('#/get#');

		$this->node->execute([['json' => []]], $this->config(), $this->context());

	}//end testFiveHundredRaisesUnderDefaultPolicy()

	/**
	 * Regression: a failed call is NEVER a success-shaped empty result.
	 *
	 * This is the `HermiqAgentNode` flaw — `catch (Throwable) { $answer = ''; }`
	 * writes an empty string to the output key, so a failed turn is
	 * indistinguishable from an empty answer while the run reports success.
	 * The node must not do that under EITHER policy: it raises by default, and
	 * under `continue` it emits explicit error state with NO output key.
	 *
	 * @return void
	 */
	public function testFailureIsNeverASuccessShapedEmptyResult(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->method('callAsync')->willReturn(
			$this->promisedLog(statusCode: 500, body: '{"error":"boom"}', statusMessage: 'Internal Server Error')
		);

		$raised = false;
		try {
			$this->node->execute([['json' => []]], $this->config(), $this->context());
		} catch (FlowNodeException $exception) {
			$raised = true;
		}

		$this->assertTrue($raised, 'A 500 must raise under the default policy.');

		$out = $this->node->execute(
			[['json' => []]],
			array_merge($this->config(), ['onError' => 'continue']),
			$this->context()
		);

		$this->assertCount(1, $out);
		$this->assertArrayNotHasKey('echo', $out[0]['json'], 'A failed item must not carry the output key at all.');
		$this->assertArrayHasKey(FlowNodeSupport::ERROR_KEY, $out[0]['json']);
		$this->assertSame(500, $out[0]['json'][FlowNodeSupport::ERROR_KEY]['status']);

	}//end testFailureIsNeverASuccessShapedEmptyResult()

	/**
	 * Under `continue`, a failing item carries error state and the next succeeds.
	 *
	 * @return void
	 */
	public function testContinuePolicyDistinguishesFailedAndSucceededItems(): void {
		$this->givenSource();
		$this->givenOwner();

		$responses = [
			$this->promisedLog(statusCode: 500, body: '{"error":"boom"}', statusMessage: 'Internal Server Error'),
			$this->promisedLog(statusCode: 200, body: '{"status":"ok"}'),
		];

		$this->callService->method('callAsync')->willReturnCallback(
			static function () use (&$responses) {
				return array_shift($responses);
			}
		);

		$out = $this->node->execute(
			[['json' => ['n' => 1]], ['json' => ['n' => 2]]],
			array_merge($this->config(), ['onError' => 'continue']),
			$this->context()
		);

		$this->assertCount(2, $out);

		$failed = $out[0]['json'][FlowNodeSupport::ERROR_KEY];
		$this->assertSame(500, $failed['status']);
		$this->assertSame('demo-echo-api', $failed['source']);
		$this->assertSame('/get', $failed['endpoint']);
		$this->assertArrayNotHasKey('echo', $out[0]['json']);

		$this->assertArrayNotHasKey(FlowNodeSupport::ERROR_KEY, $out[1]['json']);
		$this->assertSame(200, $out[1]['json']['echo']['status']);

	}//end testContinuePolicyDistinguishesFailedAndSucceededItems()

	/**
	 * A status the author opted into is a success the item can branch on.
	 *
	 * @return void
	 */
	public function testAcceptedStatusIsASuccess(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->method('callAsync')->willReturn(
			$this->promisedLog(statusCode: 404, body: '{"detail":"absent"}', statusMessage: 'Not Found')
		);

		$out = $this->node->execute(
			[['json' => []]],
			array_merge($this->config(), ['acceptStatuses' => [200, 404]]),
			$this->context()
		);

		$this->assertSame(404, $out[0]['json']['echo']['status']);
		$this->assertArrayNotHasKey(FlowNodeSupport::ERROR_KEY, $out[0]['json']);

	}//end testAcceptedStatusIsASuccess()

	/**
	 * A transport failure is a failure, never an empty-but-successful body.
	 *
	 * @return void
	 */
	public function testTransportFailureIsNotAnEmptyResponse(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->method('callAsync')->willThrowException(new RuntimeException('cURL error 28: timed out'));

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/transport level/');

		$this->node->execute([['json' => []]], $this->config(), $this->context());

	}//end testTransportFailureIsNotAnEmptyResponse()

	/**
	 * A precondition refusal (disabled source) surfaces as an error.
	 *
	 * `CallService` refuses a disabled source with a synthetic 409 CallLog
	 * rather than throwing, so the node must read the status — otherwise a
	 * refused call would look like a successful empty one.
	 *
	 * @return void
	 */
	public function testDisabledSourceRefusalSurfacesAsError(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->method('callAsync')->willReturn(
			$this->promisedLog(statusCode: 409, body: '', statusMessage: 'This source is not enabled')
		);

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/409/');

		$this->node->execute([['json' => []]], $this->config(), $this->context());

	}//end testDisabledSourceRefusalSurfacesAsError()

	/**
	 * An unresolvable source raises and makes no request.
	 *
	 * @return void
	 */
	public function testUnknownSourceRaisesAndMakesNoRequest(): void {
		$this->givenOwner();
		$this->objectService->method('find')->willReturn(null);
		$this->callService->expects($this->never())->method('callAsync');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/no-such-source/');

		$this->node->execute(
			[['json' => []]],
			['source' => 'no-such-source', 'endpoint' => '/get', 'method' => 'GET'],
			$this->context()
		);

	}//end testUnknownSourceRaisesAndMakesNoRequest()

	/**
	 * An unattributed run refuses to call, with no fallback identity.
	 *
	 * @return void
	 */
	public function testUnattributedRunRefusesToCall(): void {
		$this->callService->expects($this->never())->method('callAsync');
		$this->userManager->expects($this->never())->method('get');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/unattributed/');

		$this->node->execute([['json' => []]], $this->config(), []);

	}//end testUnattributedRunRefusesToCall()

	/**
	 * A run naming a deleted user refuses to call.
	 *
	 * @return void
	 */
	public function testUnknownUserRefusesToCall(): void {
		$this->userManager->method('get')->willReturn(null);
		$this->callService->expects($this->never())->method('callAsync');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/ghost/');

		$this->node->execute([['json' => []]], $this->config(), ['triggeredBy' => 'ghost']);

	}//end testUnknownUserRefusesToCall()

	/**
	 * The call runs inside the run owner's session, and the session is restored.
	 *
	 * @return void
	 */
	public function testCallRunsAsTheRunOwnerAndRestoresTheSession(): void {
		$this->givenSource();
		$owner = $this->givenOwner();

		$prior = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($prior);

		$seen = [];
		$this->userSession->method('setUser')->willReturnCallback(
			static function (?IUser $user) use (&$seen): void {
				$seen[] = $user;
			}
		);

		$this->callService->method('callAsync')->willReturn($this->promisedLog(statusCode: 200, body: '{}'));

		$this->node->execute([['json' => []]], $this->config(), $this->context());

		$this->assertSame([$owner, $prior], $seen);

	}//end testCallRunsAsTheRunOwnerAndRestoresTheSession()

	/**
	 * The step's default configuration used by most tests.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private function config(): array {
		return [
			'source' => 'demo-echo-api',
			'endpoint' => '/get',
			'method' => 'GET',
			'output' => 'echo',
		];

	}//end config()

	/**
	 * A run context naming an owner.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(): array {
		return ['triggeredBy' => 'alice', 'stepId' => 'step-echo'];
	}//end context()

	/**
	 * Make the object service resolve a Source.
	 *
	 * @return ObjectEntity The Source double.
	 */
	private function givenSource(): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('11111111-1111-1111-1111-111111111111');
		$source->setObject(['location' => 'https://echo.example.org', 'isEnabled' => true]);

		$this->objectService->method('find')->willReturn($source);

		return $source;
	}//end givenSource()

	/**
	 * Make the user manager resolve the run owner.
	 *
	 * @return IUser The owner double.
	 */
	private function givenOwner(): IUser {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->willReturn($owner);

		return $owner;
	}//end givenOwner()

	/**
	 * The calls actually go out CONCURRENTLY, not one at a time.
	 *
	 * Every other test in this file passes just as well against a serial loop —
	 * they hand back promises that are already fulfilled, so dispatch order and
	 * settle order are indistinguishable. This one cannot: each mocked call
	 * returns an UNSETTLED promise and counts itself in flight, so a node that
	 * awaited each response before making the next request would record a peak
	 * of one and fail here.
	 *
	 * @return void
	 */
	public function testCallsGoOutConcurrentlyRatherThanOneAtATime(): void {
		$this->givenSource();
		$this->givenOwner();

		$inFlight = 0;
		$peak = 0;

		$this->callService->method('callAsync')->willReturnCallback(
			function () use (&$inFlight, &$peak): Promise {
				$inFlight++;
				$peak = max($peak, $inFlight);

				$log = $this->callLog(statusCode: 200, body: '{"status":"ok"}');
				$promise = new Promise(
					static function () use (&$promise, $log, &$inFlight): void {
						$inFlight--;
						$promise->resolve($log);
					}
				);

				return $promise;
			}
		);

		$items = [];
		foreach (range(1, 6) as $number) {
			$items[] = ['json' => ['n' => $number]];
		}

		$out = $this->node->execute($items, $this->config(), $this->context());

		$this->assertSame(
			FlowConcurrency::DEFAULT_LIMIT,
			$peak,
			'the calls did not fan out to the shared default concurrency'
		);
		$this->assertCount(6, $out);

	}//end testCallsGoOutConcurrentlyRatherThanOneAtATime()

	/**
	 * The authored limit bounds the fan-out, and the results keep INPUT order.
	 *
	 * Both halves matter. A flow whose author capped the concurrency is making
	 * a promise to an upstream, and a run log ordered by whichever response
	 * came back first is not comparable with the next run of the same flow —
	 * so the responses here settle in REVERSE, and the output is asserted to be
	 * in the order the items went in.
	 *
	 * @return void
	 */
	public function testTheAuthoredLimitBoundsTheFanOutAndOutputKeepsInputOrder(): void {
		$this->givenSource();
		$this->givenOwner();

		$inFlight = 0;
		$peak = 0;
		$pending = [];

		$this->callService->method('callAsync')->willReturnCallback(
			function (...$arguments) use (&$inFlight, &$peak, &$pending): Promise {
				$inFlight++;
				$peak = max($peak, $inFlight);

				// Echo the endpoint back in the body, so the assertion below is
				// about WHICH item's response landed where — not merely that
				// three responses arrived.
				$endpoint = (string)($arguments[1] ?? '');
				$log = $this->callLog(
					statusCode: 200,
					body: json_encode(['seen' => $endpoint])
				);

				$promise = new Promise(
					static function () use (&$promise, $log, &$inFlight): void {
						$inFlight--;
						$promise->resolve($log);
					}
				);
				$pending[] = $promise;

				return $promise;
			}
		);

		$items = [];
		foreach (range(1, 9) as $number) {
			$items[] = ['json' => ['n' => $number]];
		}

		$out = $this->node->execute(
			$items,
			array_merge($this->config(), ['endpoint' => '/get/{{ n }}', 'concurrency' => 2]),
			$this->context()
		);

		$this->assertSame(2, $peak, 'the authored concurrency limit was not honoured');
		$this->assertCount(9, $out);

		$seen = [];
		foreach ($out as $index => $item) {
			$seen[] = $item['json']['echo']['body']['seen'];
			$this->assertSame(['item' => $index], $item['pairedItem']);
		}

		$this->assertSame(
			['/get/1', '/get/2', '/get/3', '/get/4', '/get/5', '/get/6', '/get/7', '/get/8', '/get/9'],
			$seen,
			'a response landed on the wrong item, or the output lost input order'
		);

	}//end testTheAuthoredLimitBoundsTheFanOutAndOutputKeepsInputOrder()

	/**
	 * A containment refusal stops the STEP before any request is dispatched.
	 *
	 * Rendering happens per item, so a document whose ninth item renders an
	 * out-of-bounds endpoint is only found on the ninth. Under a serial loop
	 * that meant eight calls had already gone out; under a concurrent one it
	 * could mean any number. Neither is acceptable for a guard, so the render
	 * pass runs to completion BEFORE dispatch — asserted here by the fact that
	 * `callAsync` is never reached at all.
	 *
	 * @return void
	 */
	public function testAnOutOfBoundsEndpointOnALaterItemPreventsEveryCall(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->expects($this->never())->method('callAsync');

		$items = [
			['json' => ['path' => 'fine']],
			['json' => ['path' => 'also-fine']],
			['json' => ['path' => '../../etc/passwd']],
		];

		// The guard's own refusal type, not a node error: containment is
		// refused ahead of the step's `onError` policy, and an author cannot
		// opt into continuing past it.
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/path traversal/');

		$this->node->execute(
			$items,
			array_merge($this->config(), ['endpoint' => '/get/{{ path }}']),
			$this->context()
		);

	}//end testAnOutOfBoundsEndpointOnALaterItemPreventsEveryCall()

	/**
	 * The same CallLog, as `callAsync()` hands it back.
	 *
	 * `callAsync()` is declared `): PromiseInterface`, and PHPUnit enforces a
	 * mocked method's declared return type — so a double cannot return the bare
	 * ObjectEntity even though the node's concurrency helper would accept one.
	 * Wrapping here rather than at each call site keeps every existing
	 * expectation stating the RESPONSE it is about.
	 *
	 * @param int $statusCode The HTTP status.
	 * @param string $body The response body.
	 * @param string $statusMessage The reason phrase.
	 *
	 * @return FulfilledPromise The CallLog double, already fulfilled.
	 */
	private function promisedLog(int $statusCode, string $body, string $statusMessage = 'OK'): FulfilledPromise {
		return new FulfilledPromise(
			$this->callLog(statusCode: $statusCode, body: $body, statusMessage: $statusMessage)
		);

	}//end promisedLog()

	/**
	 * Build a CallLog object shaped exactly as `CallService::call()` returns one.
	 *
	 * @param int $statusCode The HTTP status.
	 * @param string $body The response body.
	 * @param string $statusMessage The reason phrase.
	 *
	 * @return ObjectEntity The CallLog double.
	 */
	private function callLog(int $statusCode, string $body, string $statusMessage = 'OK'): ObjectEntity {
		$callLog = new ObjectEntity();
		$callLog->setUuid('22222222-2222-2222-2222-222222222222');
		$callLog->setObject(
			[
				'statusCode' => $statusCode,
				'statusMessage' => $statusMessage,
				'request' => ['url' => 'https://echo.example.org/get', 'method' => 'GET'],
				'response' => [
					'statusCode' => $statusCode,
					'statusMessage' => $statusMessage,
					'headers' => ['Content-Type' => ['application/json']],
					'body' => $body,
					'encoding' => 'UTF-8',
				],
			]
		);

		return $callLog;
	}//end callLog()

}//end class
