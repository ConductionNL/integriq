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
 * @package  OCA\Integriq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Flow;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use OCA\Integriq\Flow\FlowNodeException;
use OCA\Integriq\Flow\FlowNodeSupport;
use OCA\Integriq\Flow\FlowOwner;
use OCA\Integriq\Flow\SourceCallNode;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ResponseDecoder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowConcurrency;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
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
		$urlGenerator->method('imagePath')->willReturn('/apps/integriq/img/flow-source-call.svg');

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
			logger: $this->logger,
			decoder: new ResponseDecoder()
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
			[
				'source',
				'endpoint',
				'method',
				'query',
				'headers',
				'body',
				'bodyFrom',
				'output',
				'concurrency',
				'decode',
				'onError',
				'acceptStatuses',
				'responseMapping',
			],
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
		$this->assertNotSame('', (string)($byKey['source']['optionsFrom'] ?? ''));

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

		$this->assertSame([], $this->node->execute(new FlowNodeCall([], $this->config(), $this->context())));

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

		$out = $this->node->execute(new FlowNodeCall($items, $this->config(), $this->context()));

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

		$this->node->execute(new FlowNodeCall($items, $config, $this->context()));

		$this->assertSame('/issues/42/labels', $captured[1]);
		$this->assertSame('POST', $captured[2]);
		$this->assertSame(['labels' => ['needs-triage']], $captured[3]['json']);

	}//end testTemplatedValuesAreResolvedFromTheItem()

	/**
	 * A body naming `{{ @item }}` posts the whole item, which is what a retired webhook step sent.
	 *
	 * @return void
	 */
	public function testWholeItemBodyPostsTheItemItself(): void {
		$this->givenSource();
		$this->givenOwner();

		$captured = [];
		$this->callService->method('callAsync')->willReturnCallback(
			function (...$arguments) use (&$captured) {
				$captured = $arguments;
				return $this->promisedLog(statusCode: 204, body: '');
			}
		);

		$case = ['id' => 'c-7', 'title' => 'Kapvergunning', 'status' => 'open'];
		$this->node->execute(new FlowNodeCall(
			[['json' => $case]],
			[
				'source' => 'url-https-hooks-example-org',
				'endpoint' => '/case-events',
				'method' => 'POST',
				'body' => ['case' => '{{ @item }}', 'transition' => ['to' => 'closed']],
			],
			$this->context()
		));

		$this->assertSame('/case-events', $captured[1]);
		$this->assertSame(['case' => $case, 'transition' => ['to' => 'closed']], $captured[3]['json']);

	}//end testWholeItemBodyPostsTheItemItself()

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

		$out = $this->node->execute(new FlowNodeCall(
			[['json' => ['issue' => ['number' => 1]]]],
			['source' => 'demo-echo-api', 'endpoint' => '/get', 'method' => 'GET', 'output' => 'labelResult'],
			$this->context()
		));

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

		$out = $this->node->execute(new FlowNodeCall(
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
		));

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

		$this->node->execute(new FlowNodeCall(
			[['json' => ['issue' => ['ref' => '../../evil']]]],
			['source' => 'demo-forge-api', 'endpoint' => '/issues/{{issue.ref}}', 'method' => 'GET'],
			$this->context()
		));

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

		$this->node->execute(new FlowNodeCall([['json' => []]], $this->config(), $this->context()));

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
			$this->node->execute(new FlowNodeCall([['json' => []]], $this->config(), $this->context()));
		} catch (FlowNodeException $exception) {
			$raised = true;
		}

		$this->assertTrue($raised, 'A 500 must raise under the default policy.');

		$out = $this->node->execute(new FlowNodeCall(
			[['json' => []]],
			array_merge($this->config(), ['onError' => 'continue']),
			$this->context()
		));

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

		$out = $this->node->execute(new FlowNodeCall(
			[['json' => ['n' => 1]], ['json' => ['n' => 2]]],
			array_merge($this->config(), ['onError' => 'continue']),
			$this->context()
		));

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

		$out = $this->node->execute(new FlowNodeCall(
			[['json' => []]],
			array_merge($this->config(), ['acceptStatuses' => [200, 404]]),
			$this->context()
		));

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

		$this->node->execute(new FlowNodeCall([['json' => []]], $this->config(), $this->context()));

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

		$this->node->execute(new FlowNodeCall([['json' => []]], $this->config(), $this->context()));

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

		$this->node->execute(new FlowNodeCall(
			[['json' => []]],
			['source' => 'no-such-source', 'endpoint' => '/get', 'method' => 'GET'],
			$this->context()
		));

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

		$this->node->execute(new FlowNodeCall([['json' => []]], $this->config(), []));

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

		$this->node->execute(new FlowNodeCall([['json' => []]], $this->config(), ['triggeredBy' => 'ghost']));

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
		$this->userSession->method('setVolatileActiveUser')->willReturnCallback(
			static function (?IUser $user) use (&$seen): void {
				$seen[] = $user;
			}
		);

		$this->callService->method('callAsync')->willReturn($this->promisedLog(statusCode: 200, body: '{}'));

		$this->node->execute(new FlowNodeCall([['json' => []]], $this->config(), $this->context()));

		$this->assertSame([$owner, $prior], $seen);

	}//end testCallRunsAsTheRunOwnerAndRestoresTheSession()

	/**
	 * Every outcome of a call matches the golden file recorded before the
	 * outcome step was split: transport failures, a missing call log, status
	 * checks, where the status is read from, and the success shape, under both
	 * error policies.
	 *
	 * Regenerate with SOURCE_CALL_GOLDEN=1.
	 *
	 * @return void
	 */
	public function testEveryCallOutcomeMatchesTheGoldenFile(): void {
		$entity = function (array $object): ObjectEntity {
			$log = new ObjectEntity();
			$log->setUuid('33333333-3333-3333-3333-333333333333');
			$log->setObject($object);
			return $log;
		};
		$cases = [
			'ok-200' => [fn () => $this->promisedLog(statusCode: 200, body: '{"a":1}'), []],
			'server-error' => [fn () => $this->promisedLog(statusCode: 500, body: '{"e":1}', statusMessage: 'Boom'), []],
			'accepted-404' => [fn () => $this->promisedLog(statusCode: 404, body: '{}', statusMessage: 'Not Found'), ['acceptStatuses' => [200, 404]]],
			'transport' => [fn () => new \GuzzleHttp\Promise\RejectedPromise(new RuntimeException('timed out')), []],
			'not-a-call-log' => [fn () => new FulfilledPromise('a string'), []],
			'status-only-in-response' => [fn () => new FulfilledPromise($entity(['response' => ['statusCode' => 201, 'statusMessage' => 'Created', 'body' => '{"b":2}', 'headers' => ['X' => ['y']]]])), []],
			'no-status-at-all' => [fn () => new FulfilledPromise($entity(['response' => ['body' => '']])), []],
			'top-level-message-wins' => [fn () => new FulfilledPromise($entity(['statusCode' => 202, 'statusMessage' => 'Top', 'response' => ['statusCode' => 500, 'statusMessage' => 'Inner', 'body' => '{}']])), []],
			'post-lowercase-method' => [fn () => $this->promisedLog(statusCode: 200, body: '{"c":3}'), ['method' => 'post']],
			'decode-text' => [fn () => $this->promisedLog(statusCode: 200, body: 'plain words', contentType: 'text/plain'), ['decode' => 'TEXT ']],
		];

		$actual = [];
		foreach ($cases as $name => [$promise, $config]) {
			foreach (['raise', 'continue'] as $policy) {
				$this->setUp();
				$this->givenSource();
				$this->givenOwner();
				$this->callService->method('callAsync')->willReturnCallback($promise);
				$stepConfig = array_merge($this->config(), $config);
				if ($policy === 'continue') {
					$stepConfig['onError'] = 'continue';
				}

				try {
					$outcome = ['out' => $this->node->execute(new FlowNodeCall([['json' => ['n' => 1]]], $stepConfig, $this->context()))];
				} catch (FlowNodeException $exception) {
					$outcome = ['message' => $exception->getMessage(), 'details' => $exception->getDetails(), 'previous' => ($exception->getPrevious() === null ? null : $exception->getPrevious()->getMessage())];
				}

				$actual[$name . '/' . $policy] = $outcome;
			}
		}

		$golden = __DIR__ . '/../../fixtures/flow/source-call-outcomes.json';
		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('SOURCE_CALL_GOLDEN') === '1') {
			if (is_dir(dirname($golden)) === false) {
				mkdir(dirname($golden), 0777, true);
			}

			file_put_contents($golden, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents($golden), true), json_decode($encoded, true));
	}//end testEveryCallOutcomeMatchesTheGoldenFile()

	/**
	 * A templated source sends each item through the source its own record names.
	 *
	 * @return void
	 */
	public function testTemplatedSourceSendsEachItemThroughItsOwnSource(): void {
		$this->givenOwner();
		$sources = $this->givenBoardSources();

		$looked = [];
		$this->objectService->method('find')->willReturnCallback(
			static function (...$arguments) use ($sources, &$looked): ?ObjectEntity {
				$looked[] = $arguments[0];

				return ($sources[$arguments[0]] ?? null);
			}
		);

		$sent = [];
		$this->callService->expects($this->exactly(3))->method('callAsync')->willReturnCallback(
			function (...$arguments) use (&$sent): FulfilledPromise {
				$sent[] = $arguments[0]->getUuid();

				return $this->promisedLog(statusCode: 201, body: '{"id":"posted"}', statusMessage: 'Created');
			}
		);

		$out = $this->node->execute(new FlowNodeCall(
			[
				['json' => ['channel' => 'werk-nl']],
				['json' => ['channel' => 'linkedin']],
				['json' => ['channel' => 'werk-nl']],
			],
			array_merge($this->config(), ['source' => 'jobboard-{{ json.channel }}']),
			$this->context()
		));

		$this->assertSame(['werk-nl-uuid', 'linkedin-uuid', 'werk-nl-uuid'], $sent);
		$this->assertSame(['jobboard-werk-nl', 'jobboard-linkedin'], $looked);
		$this->assertSame('jobboard-werk-nl', $out[0]['json']['echo']['source']);
		$this->assertSame('jobboard-linkedin', $out[1]['json']['echo']['source']);
		$this->assertSame('linkedin-uuid', $out[1]['json']['echo']['sourceId']);

	}//end testTemplatedSourceSendsEachItemThroughItsOwnSource()

	/**
	 * Under `continue`, an item whose board has no source fails alone and the others are sent.
	 *
	 * @return void
	 */
	public function testUnresolvedTemplatedSourceFailsOnlyItsItemUnderContinue(): void {
		$this->givenOwner();
		$sources = $this->givenBoardSources();
		$this->objectService->method('find')->willReturnCallback(
			static function (...$arguments) use ($sources): ?ObjectEntity {
				return ($sources[$arguments[0]] ?? null);
			}
		);

		$this->callService->expects($this->exactly(2))->method('callAsync')->willReturn(
			$this->promisedLog(statusCode: 201, body: '{"id":"posted"}', statusMessage: 'Created')
		);

		$out = $this->node->execute(new FlowNodeCall(
			[
				['json' => ['channel' => 'werk-nl']],
				['json' => ['channel' => 'indeed']],
				['json' => ['channel' => 'linkedin']],
			],
			array_merge($this->config(), ['source' => 'jobboard-{{ json.channel }}', 'onError' => 'continue']),
			$this->context()
		));

		$this->assertCount(3, $out);
		$this->assertSame(201, $out[0]['json']['echo']['status']);
		$this->assertSame(201, $out[2]['json']['echo']['status']);
		$this->assertArrayNotHasKey('echo', $out[1]['json']);

		$failed = $out[1]['json'][FlowNodeSupport::ERROR_KEY];
		$this->assertSame('source', $failed['kind']);
		$this->assertSame('jobboard-indeed', $failed['source']);
		$this->assertStringContainsString('No source configured: "jobboard-indeed"', $failed['message']);
		$this->assertSame(1, $out[1]['pairedItem']['item']);

	}//end testUnresolvedTemplatedSourceFailsOnlyItsItemUnderContinue()

	/**
	 * Under the default policy, an unresolved per-item source refuses the step before any request.
	 *
	 * @return void
	 */
	public function testUnresolvedTemplatedSourceUnderStopMakesNoRequest(): void {
		$this->givenOwner();
		$sources = $this->givenBoardSources();
		$this->objectService->method('find')->willReturnCallback(
			static function (...$arguments) use ($sources): ?ObjectEntity {
				return ($sources[$arguments[0]] ?? null);
			}
		);
		$this->callService->expects($this->never())->method('callAsync');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessage('No source configured: "jobboard-indeed". This item makes no request.');

		$this->node->execute(new FlowNodeCall(
			[['json' => ['channel' => 'werk-nl']], ['json' => ['channel' => 'indeed']]],
			array_merge($this->config(), ['source' => 'jobboard-{{ json.channel }}']),
			$this->context()
		));

	}//end testUnresolvedTemplatedSourceUnderStopMakesNoRequest()

	/**
	 * A templated source that renders empty for an item names no source and makes no lookup.
	 *
	 * @return void
	 */
	public function testTemplatedSourceRenderingEmptyFailsWithoutALookup(): void {
		$this->givenOwner();
		$this->objectService->expects($this->never())->method('find');
		$this->callService->expects($this->never())->method('callAsync');

		$out = $this->node->execute(new FlowNodeCall(
			[['json' => []]],
			array_merge($this->config(), ['source' => '{{ json.channel }}', 'onError' => 'continue']),
			$this->context()
		));

		$this->assertSame('', $out[0]['json'][FlowNodeSupport::ERROR_KEY]['source']);
		$this->assertSame('No source configured: "". This item makes no request.', $out[0]['json'][FlowNodeSupport::ERROR_KEY]['message']);

	}//end testTemplatedSourceRenderingEmptyFailsWithoutALookup()

	/**
	 * Two job board sources keyed by their reference.
	 *
	 * @return array<string, ObjectEntity> The sources.
	 */
	private function givenBoardSources(): array {
		$sources = [];
		foreach (['werk-nl', 'linkedin'] as $board) {
			$source = new ObjectEntity();
			$source->setUuid($board . '-uuid');
			$source->setObject(['location' => 'https://' . $board . '.example.org', 'isEnabled' => true]);
			$sources['jobboard-' . $board] = $source;
		}

		return $sources;
	}//end givenBoardSources()

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

		$out = $this->node->execute(new FlowNodeCall($items, $this->config(), $this->context()));

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

		$out = $this->node->execute(new FlowNodeCall(
			$items,
			array_merge($this->config(), ['endpoint' => '/get/{{ n }}', 'concurrency' => 2]),
			$this->context()
		));

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

		$this->node->execute(new FlowNodeCall(
			$items,
			array_merge($this->config(), ['endpoint' => '/get/{{ path }}']),
			$this->context()
		));

	}//end testAnOutOfBoundsEndpointOnALaterItemPreventsEveryCall()

	/**
	 * A raw publiccode.yml, served as text/plain, becomes an object with `decode: yaml`.
	 *
	 * @return void
	 */
	public function testDecodeYamlReadsARawPubliccodeFile(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->method('callAsync')->willReturn(
			$this->promisedLog(
				statusCode: 200,
				body: $this->fixture(name: 'publiccode-v0.4-conductionnl-opencatalogi.yml'),
				contentType: 'text/plain; charset=utf-8'
			)
		);

		$out = $this->node->execute(new FlowNodeCall([['json' => []]], array_merge($this->config(), ['decode' => 'yaml']), $this->context()));

		$body = $out[0]['json']['echo']['body'];
		$this->assertIsArray($body);
		$this->assertSame('0.4', $body['publiccodeYmlVersion']);
		$this->assertSame('OpenCatalogi', $body['name']);
		$this->assertSame('https://github.com/ConductionNL/opencatalogi', $body['url']);

	}//end testDecodeYamlReadsARawPubliccodeFile()

	/**
	 * A contents API answer becomes the file it carries, not its envelope.
	 *
	 * @return void
	 */
	public function testDecodeBase64YamlReadsAContentsApiAnswer(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->method('callAsync')->willReturn(
			$this->promisedLog(statusCode: 200, body: $this->fixture(name: 'github-contents-v0.4-opencatalogi.json'))
		);

		$out = $this->node->execute(new FlowNodeCall([['json' => []]], array_merge($this->config(), ['decode' => 'base64+yaml']), $this->context()));

		$body = $out[0]['json']['echo']['body'];
		$this->assertSame('OpenCatalogi', $body['name']);
		$this->assertArrayNotHasKey('sha', $body, 'The envelope must not leak into the decoded file.');

	}//end testDecodeBase64YamlReadsAContentsApiAnswer()

	/**
	 * Without `decode`, a YAML file served as text/plain stays a string, as before.
	 *
	 * @return void
	 */
	public function testAutoDoesNotSniffYamlInPlainText(): void {
		$this->givenSource();
		$this->givenOwner();

		$yaml = $this->fixture(name: 'publiccode-v0.2-amsterdam-signals-frontend.yml');
		$this->callService->method('callAsync')->willReturn(
			$this->promisedLog(statusCode: 200, body: $yaml, contentType: 'text/plain; charset=utf-8')
		);

		$out = $this->node->execute(new FlowNodeCall([['json' => []]], $this->config(), $this->context()));

		$this->assertSame($yaml, $out[0]['json']['echo']['body']);

	}//end testAutoDoesNotSniffYamlInPlainText()

	/**
	 * One malformed file among good ones fails only its own item, with kind `decode`.
	 *
	 * @return void
	 */
	public function testMalformedFileFailsOnlyItsItem(): void {
		$this->givenSource();
		$this->givenOwner();

		$responses = [
			$this->promisedLog(200, $this->fixture(name: 'publiccode-v0.2-amsterdam-signals-frontend.yml'), 'OK', 'text/plain'),
			$this->promisedLog(200, $this->fixture(name: 'publiccode-malformed.yml'), 'OK', 'text/plain'),
			$this->promisedLog(200, $this->fixture(name: 'publiccode-v0.4-conductionnl-opencatalogi.yml'), 'OK', 'text/plain'),
		];
		$this->callService->method('callAsync')->willReturnCallback(
			static function () use (&$responses) {
				return array_shift($responses);
			}
		);

		$out = $this->node->execute(new FlowNodeCall(
			[['json' => ['n' => 1]], ['json' => ['n' => 2]], ['json' => ['n' => 3]]],
			array_merge($this->config(), ['decode' => 'yaml', 'onError' => 'continue']),
			$this->context()
		));

		$this->assertCount(3, $out);
		$this->assertSame('0.2', $out[0]['json']['echo']['body']['publiccodeYmlVersion']);
		$this->assertSame('0.4', $out[2]['json']['echo']['body']['publiccodeYmlVersion']);

		$this->assertArrayNotHasKey('echo', $out[1]['json'], 'An unreadable file must not look like a read one.');
		$error = $out[1]['json'][FlowNodeSupport::ERROR_KEY];
		$this->assertSame('decode', $error['kind']);
		$this->assertSame(200, $error['status']);
		$this->assertStringContainsString('yaml', $error['message']);
		$this->assertStringContainsString('line 7', $error['message']);

	}//end testMalformedFileFailsOnlyItsItem()

	/**
	 * Under the default policy a malformed file raises; it never maps to an empty object.
	 *
	 * @return void
	 */
	public function testMalformedFileRaisesUnderDefaultPolicy(): void {
		$this->givenSource();
		$this->givenOwner();

		$this->callService->method('callAsync')->willReturn(
			$this->promisedLog(200, $this->fixture(name: 'publiccode-malformed.yml'), 'OK', 'text/plain')
		);

		try {
			$this->node->execute(new FlowNodeCall([['json' => []]], array_merge($this->config(), ['decode' => 'yaml']), $this->context()));
			$this->fail('A malformed file must raise.');
		} catch (FlowNodeException $exception) {
			$this->assertSame('decode', $exception->getDetails()['kind']);
		}

	}//end testMalformedFileRaisesUnderDefaultPolicy()

	/**
	 * An unknown decode mode is refused when the flow is saved.
	 *
	 * @return void
	 */
	public function testValidateRejectsUnknownDecodeMode(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/decode/');

		$this->node->validateConfig(array_merge($this->config(), ['decode' => 'yml']));

	}//end testValidateRejectsUnknownDecodeMode()

	/**
	 * Every key the node reads is declared, so a preflight that refuses
	 * undeclared keys accepts a step that sets `onError`, `acceptStatuses` or
	 * `responseMapping`. Before, `onError: continue` was refused at save and one
	 * failed file cost the whole page.
	 *
	 * @return void
	 */
	public function testKeysTheNodeReadsAreDeclared(): void {
		$config = array_merge(
			$this->config(),
			['onError' => 'continue', 'acceptStatuses' => [404], 'responseMapping' => ['n' => 'id'], 'decode' => 'yaml']
		);

		$this->assertSame([], array_values(array_diff(array_keys($config), $this->node->configKeys())));
		$this->node->validateConfig($config);

		$form = array_column($this->node->configForm(), null, 'key');
		$this->assertArrayHasKey('onError', $form);

	}//end testKeysTheNodeReadsAreDeclared()

	/**
	 * An unknown onError policy is refused at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsUnknownOnErrorPolicy(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/onError/');

		$this->node->validateConfig(array_merge($this->config(), ['onError' => 'skip']));

	}//end testValidateRejectsUnknownOnErrorPolicy()

	/**
	 * Read a test fixture.
	 *
	 * @param string $name The file name under tests/fixtures/publiccode.
	 *
	 * @return string The file contents.
	 */
	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../fixtures/publiccode/' . $name);
	}//end fixture()

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
	private function promisedLog(
		int $statusCode,
		string $body,
		string $statusMessage = 'OK',
		string $contentType = 'application/json',
	): FulfilledPromise {
		return new FulfilledPromise(
			$this->callLog(statusCode: $statusCode, body: $body, statusMessage: $statusMessage, contentType: $contentType)
		);

	}//end promisedLog()

	/**
	 * Build a CallLog object shaped exactly as `CallService::call()` returns one.
	 *
	 * @param int $statusCode The HTTP status.
	 * @param string $body The response body.
	 * @param string $statusMessage The reason phrase.
	 * @param string $contentType The response Content-Type.
	 *
	 * @return ObjectEntity The CallLog double.
	 */
	private function callLog(
		int $statusCode,
		string $body,
		string $statusMessage = 'OK',
		string $contentType = 'application/json',
	): ObjectEntity {
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
					'headers' => ['Content-Type' => [$contentType]],
					'body' => $body,
					'encoding' => 'UTF-8',
				],
			]
		);

		return $callLog;
	}//end callLog()

	/**
	 * `bodyFrom` sends the mapped object at that item path whole, as JSON.
	 *
	 * @return void
	 */
	public function testBodyFromSendsTheObjectAtThatPathWhole(): void {
		$this->givenSource();
		$this->givenOwner();

		$captured = [];
		$this->callService->method('callAsync')->willReturnCallback(
			function (...$arguments) use (&$captured) {
				$captured = $arguments;
				return $this->promisedLog(statusCode: 201, body: '{"result":{"sys_id":"abc"}}');
			}
		);

		$send = ['name' => 'Zaaksysteem', 'u_bbn_level' => '2', 'install_status' => '1'];
		$this->node->execute(new FlowNodeCall(
			[['json' => ['usage' => ['uuid' => 'u-1'], 'send' => $send]]],
			[
				'source' => 'servicenow',
				'endpoint' => '/api/now/table/cmdb_ci_appl',
				'method' => 'POST',
				'bodyFrom' => 'send',
				'output' => 'created',
			],
			$this->context()
		));

		$this->assertSame($send, $captured[3]['json']);
		$this->assertArrayNotHasKey('body', $captured[3]);

	}//end testBodyFromSendsTheObjectAtThatPathWhole()

	/**
	 * A `bodyFrom` path with no object refuses the step before any call.
	 *
	 * @return void
	 */
	public function testBodyFromWithoutAnObjectSendsNothing(): void {
		$this->givenSource();
		$this->givenOwner();
		$this->callService->expects($this->never())->method('callAsync');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessage('did not resolve to an object');

		$this->node->execute(new FlowNodeCall(
			[['json' => ['send' => 'not an object']]],
			['source' => 'servicenow', 'endpoint' => '/api/now/table/cmdb_ci_appl', 'method' => 'POST', 'bodyFrom' => 'send'],
			$this->context()
		));

	}//end testBodyFromWithoutAnObjectSendsNothing()

	/**
	 * `body` and `bodyFrom` together are refused at save.
	 *
	 * @return void
	 */
	public function testBodyAndBodyFromTogetherAreRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Use "body" or "bodyFrom", not both.');

		$this->node->validateConfig(
			['source' => 'servicenow', 'endpoint' => '/x', 'method' => 'POST', 'body' => ['a' => 1], 'bodyFrom' => 'send']
		);

	}//end testBodyAndBodyFromTogetherAreRefused()

	/**
	 * Under `continue` a failed item carries the whole error state, both items
	 * keep their binary part and position, and the failure is logged once.
	 *
	 * @return void
	 */
	public function testContinueKeepsBinaryAndLogsTheWholeErrorState(): void {
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

		$logged = [];
		$this->logger->expects($this->once())->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				unset($context['line']);
				$logged = [$message, $context];
			}
		);

		$out = $this->node->execute(new FlowNodeCall(
			[['json' => ['n' => 1], 'binary' => ['f' => 'one']], ['json' => ['n' => 2], 'binary' => ['f' => 'two']]],
			array_merge($this->config(), ['onError' => 'continue', 'id' => 'call-1']),
			$this->context()
		));

		$error = $out[0]['json'][FlowNodeSupport::ERROR_KEY];
		$this->assertSame(
			['step', 'node', 'status', 'statusMessage', 'kind', 'message', 'source', 'endpoint', 'method'],
			array_keys($error)
		);
		$this->assertSame('step-echo', $error['step']);
		$this->assertSame('openconnector.source-call', $error['node']);
		$this->assertSame('GET', $error['method']);
		$this->assertSame(['json', 'binary', 'pairedItem'], array_keys($out[0]));
		$this->assertSame(['f' => 'one'], $out[0]['binary']);
		$this->assertSame(['item' => 0], $out[0]['pairedItem']);
		$this->assertSame(['json', 'binary', 'pairedItem'], array_keys($out[1]));
		$this->assertSame(['f' => 'two'], $out[1]['binary']);
		$this->assertSame(['item' => 1], $out[1]['pairedItem']);

		$this->assertSame('[openconnector.source-call] ' . $error['message'], $logged[0]);
		$this->assertSame(['file', 'step', 'source', 'endpoint', 'method'], array_keys($logged[1]));
		$this->assertSame([$error['step'], 'demo-echo-api', '/get', 'GET'], array_slice(array_values($logged[1]), 1));

	}//end testContinueKeepsBinaryAndLogsTheWholeErrorState()

	/**
	 * A source lookup that fails raises with the reason and makes no request.
	 *
	 * @return void
	 */
	public function testASourceLookupThatFailsRaisesAndMakesNoRequest(): void {
		$this->givenOwner();
		$this->objectService->method('find')->willThrowException(new \RuntimeException('database unavailable'));
		$this->callService->expects($this->never())->method('callAsync');

		try {
			$this->node->execute(new FlowNodeCall(
				[['json' => []]],
				['source' => 'demo-echo-api', 'endpoint' => '/get', 'method' => 'GET'],
				$this->context()
			));
			$this->fail('Expected the unresolvable source to raise.');
		} catch (FlowNodeException $exception) {
			$this->assertSame(
				'The source "demo-echo-api" named by this step could not be resolved: database unavailable. '
				. 'No source is created and no request is made.',
				$exception->getMessage()
			);
			$this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
		}

	}//end testASourceLookupThatFailsRaisesAndMakesNoRequest()

	/**
	 * A logged call links to its source and its call log, under the app's hash router.
	 *
	 * @return void
	 */
	public function testALoggedCallLinksToItsSourceAndCallLog(): void {
		$node = $this->nodeWithAppRoot(root: '/index.php/apps/integriq/');

		$actions = $node->logActions([
			'output' => [
				'items' => [
					['json' => ['sourceId' => ' src 1/a ', 'callLog' => 'log-9']],
					['json' => ['sourceId' => 'src-2', 'callLog' => 'log-10']],
				],
			],
		]);

		$this->assertSame(
			[
				['label' => 'Open the source', 'href' => '/index.php/apps/integriq#/sources/src%201%2Fa'],
				['label' => 'Open the call log', 'href' => '/index.php/apps/integriq#/sources/logs?call=log-9'],
			],
			$actions
		);

	}//end testALoggedCallLinksToItsSourceAndCallLog()

	/**
	 * An entry without either id offers no links at all.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutIdsOffersNoLinks(): void {
		$node = $this->nodeWithAppRoot(root: '/index.php/apps/integriq/');

		$this->assertSame([], $node->logActions(['output' => ['items' => [['json' => ['sourceId' => '  ']]]]]));
		$this->assertSame([], $node->logActions([]));

	}//end testAnEntryWithoutIdsOffersNoLinks()

	/**
	 * A node whose URL generator answers the app root for the dashboard route.
	 *
	 * @param string $root The app root.
	 *
	 * @return SourceCallNode
	 */
	private function nodeWithAppRoot(string $root): SourceCallNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->with('integriq.ui.dashboard', ['path' => ''])->willReturn($root);

		return new SourceCallNode(
			callService: $this->callService,
			concurrency: new FlowConcurrency(),
			objectService: $this->objectService,
			flowOwner: new FlowOwner(userManager: $this->userManager, userSession: $this->userSession, l10n: $l10n),
			l10n: $l10n,
			urlGenerator: $urlGenerator,
			logger: $this->logger,
			decoder: new ResponseDecoder()
		);

	}//end nodeWithAppRoot()

}//end class
