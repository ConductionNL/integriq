<?php

/**
 * Unit tests for EndpointMessageGate: when an endpoint's message is checked,
 * against what, how a mismatch is refused, and how findings are recorded.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\MessageValidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\MessageValidation;

use OCA\Integriq\Service\MessageValidation\EndpointMessageGate;
use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\OpenApiChecker;
use OCA\Integriq\Service\MessageValidation\ValidationOutcome;
use OCA\Integriq\Service\MessageValidation\XsdChecker;
use OCA\Integriq\Service\MessageValidationService;
use OCA\Integriq\Tests\Helpers\RecordingLogger;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * The gate over the real validators; OpenRegister is the boundary.
 *
 * @covers \OCA\Integriq\Service\MessageValidation\EndpointMessageGate
 * @uses   \OCA\Integriq\Service\MessageValidationService
 * @uses   \OCA\Integriq\Service\MessageValidation\JsonSchemaChecker
 * @uses   \OCA\Integriq\Service\MessageValidation\XsdChecker
 * @uses   \OCA\Integriq\Service\MessageValidation\OpenApiChecker
 * @uses   \OCA\Integriq\Service\MessageValidation\ValidationOutcome
 */
class EndpointMessageGateTest extends TestCase {

	private const JSON_SCHEMA = 'schema-json';

	private const XSD_SCHEMA = 'schema-xsd';

	private const CALL_LOG = 'log-1';

	/**
	 * The OpenRegister double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The logger.
	 *
	 * @var RecordingLogger
	 */
	private RecordingLogger $logger;

	/**
	 * Stored objects by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $stored = [];

	/**
	 * Saves, as [schema, uuid, object].
	 *
	 * @var array<int, array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $saves = [];

	/**
	 * Build the in-memory register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = new RecordingLogger();
		$this->saves = [];
		$this->stored = [
			'message_schema' => [
				self::JSON_SCHEMA => [
					'kind' => 'json-schema',
					'document' => json_encode(['type' => 'object', 'required' => ['zaak'], 'properties' => ['zaak' => ['type' => 'string']]]),
				],
				self::XSD_SCHEMA => [
					'kind' => 'xsd',
					'document' => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="Zaak" type="xs:string"/></xs:schema>',
				],
			],
			'call_log' => [
				self::CALL_LOG => ['statusCode' => 200, 'direction' => 'outbound'],
			],
		];

		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null): ?ObjectEntity {
				$this->assertSame('integriq', $register);
				$data = ($this->stored[$schema][(string)$id] ?? null);
				if ($data === 'throw') {
					throw new RuntimeException('database gone');
				}

				if ($data === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($data);
				return $entity;
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null) {
				if (($this->stored['saveFails'] ?? false) === true) {
					throw new RuntimeException('write refused');
				}

				$this->saves[] = [$schema, $uuid, $object];
				return new ObjectEntity();
			}
		);

	}//end setUp()

	/**
	 * The gate over the real validators.
	 *
	 * @return EndpointMessageGate
	 */
	private function gate(): EndpointMessageGate {
		$json = new JsonSchemaChecker();

		return new EndpointMessageGate(
			new MessageValidationService($json, new XsdChecker(), new OpenApiChecker($json)),
			$this->objects,
			$this->logger
		);

	}//end gate()

	/**
	 * An endpoint whose direction declares no message schema is not checked.
	 *
	 * @return void
	 */
	public function testNothingDeclaredMeansNoCheck(): void {
		$this->objects->expects($this->never())->method('find');
		$gate = $this->gate();

		$this->assertNull($gate->check([], EndpointMessageGate::REQUEST, '{}'));
		$this->assertNull($gate->check(['validation' => ['request' => 'schema-json']], EndpointMessageGate::REQUEST, '{}'));
		$this->assertNull($gate->check(['validation' => ['request' => ['messageSchema' => '']]], EndpointMessageGate::REQUEST, '{}'));
		$this->assertNull($gate->check(['validation' => ['request' => ['messageSchema' => self::JSON_SCHEMA]]], EndpointMessageGate::RESPONSE, '{}'));

	}//end testNothingDeclaredMeansNoCheck()

	/**
	 * A JSON body is decoded and checked against the declared schema.
	 *
	 * @return void
	 */
	public function testAJsonBodyIsCheckedAgainstTheDeclaredSchema(): void {
		$endpoint = ['validation' => ['request' => ['messageSchema' => self::JSON_SCHEMA]]];
		$gate = $this->gate();

		$this->assertTrue($gate->check($endpoint, EndpointMessageGate::REQUEST, '{"zaak":"Z-1"}')->isValid());
		$this->assertTrue($gate->check($endpoint, EndpointMessageGate::REQUEST, (object)['zaak' => 'Z-1'])->isValid());

		$outcome = $gate->check($endpoint, EndpointMessageGate::REQUEST, '{"zaak":42}');
		$this->assertFalse($outcome->isValid());
		$this->assertNotSame([], $outcome->paths());

	}//end testAJsonBodyIsCheckedAgainstTheDeclaredSchema()

	/**
	 * A body that is not JSON fails before the schema is consulted.
	 *
	 * @return void
	 */
	public function testABodyThatIsNotJsonFails(): void {
		$outcome = $this->gate()->checkDeclared(['messageSchema' => self::JSON_SCHEMA], EndpointMessageGate::REQUEST, '{"zaak":');

		$this->assertSame([['path' => '/', 'message' => 'The message is not JSON: Syntax error']], $outcome->errors());

	}//end testABodyThatIsNotJsonFails()

	/**
	 * An XSD-checked body is handed over as text, not decoded as JSON.
	 *
	 * @return void
	 */
	public function testAnXsdBodyIsCheckedAsText(): void {
		$gate = $this->gate();

		$this->assertTrue($gate->checkDeclared(['messageSchema' => self::XSD_SCHEMA], EndpointMessageGate::RESPONSE, '<Zaak>Z-1</Zaak>')->isValid());
		$this->assertFalse($gate->checkDeclared(['messageSchema' => self::XSD_SCHEMA], EndpointMessageGate::RESPONSE, '<Besluit>B-1</Besluit>')->isValid());

	}//end testAnXsdBodyIsCheckedAsText()

	/**
	 * A declared schema that does not exist, or cannot be read, fails the
	 * check instead of passing the message unchecked.
	 *
	 * @return void
	 */
	public function testAMissingOrUnreadableSchemaFailsTheCheck(): void {
		$this->stored['message_schema']['broken'] = 'throw';
		$gate = $this->gate();

		foreach (['absent', 'broken'] as $uuid) {
			$outcome = $gate->checkDeclared(['messageSchema' => $uuid], EndpointMessageGate::REQUEST, '{}');
			$this->assertSame(
				[['path' => '/', 'message' => 'Message schema ' . $uuid . ' does not exist, so the message could not be checked']],
				$outcome->errors()
			);
		}

	}//end testAMissingOrUnreadableSchemaFailsTheCheck()

	/**
	 * The direction, and the operation id when declared, reach the validator.
	 *
	 * @return void
	 */
	public function testTheDirectionAndOperationReachTheValidator(): void {
		$contexts = [];
		$validator = $this->createMock(MessageValidationService::class);
		$validator->method('validate')->willReturnCallback(
			function (array $messageSchema, mixed $payload, array $context) use (&$contexts): ValidationOutcome {
				$contexts[] = $context;
				return ValidationOutcome::valid();
			}
		);
		$gate = new EndpointMessageGate($validator, $this->objects, $this->logger);

		$gate->checkDeclared(['messageSchema' => self::JSON_SCHEMA, 'operationId' => 'zaak_create'], EndpointMessageGate::REQUEST, '{}', ['endpoint' => 'zaken']);
		$gate->checkDeclared(['messageSchema' => self::JSON_SCHEMA, 'operationId' => ''], EndpointMessageGate::RESPONSE, '{}');

		$this->assertSame(
			[
				['endpoint' => 'zaken', 'direction' => 'request', 'operationId' => 'zaak_create'],
				['direction' => 'response'],
			],
			$contexts
		);

	}//end testTheDirectionAndOperationReachTheValidator()

	/**
	 * Only mode "refuse" refuses; the default is to record.
	 *
	 * @return void
	 */
	public function testOnlyRefuseModeRefuses(): void {
		$gate = $this->gate();

		$this->assertFalse($gate->refuses([]));
		$this->assertFalse($gate->refuses(['validation' => ['mode' => 'record']]));
		$this->assertTrue($gate->refuses(['validation' => ['mode' => 'refuse']]));

	}//end testOnlyRefuseModeRefuses()

	/**
	 * A refused request is a 400 problem, a refused answer a 502, each with
	 * at most twenty errors.
	 *
	 * @return void
	 */
	public function testARefusalIsAProblemResponseByDirection(): void {
		$errors = [];
		for ($i = 0; $i < 25; $i++) {
			$errors[] = ['path' => '/' . $i, 'message' => 'wrong'];
		}

		$outcome = ValidationOutcome::failed($errors);
		$gate = $this->gate();

		$request = $gate->refusal($outcome, EndpointMessageGate::REQUEST);
		$this->assertSame(400, $request->getStatus());
		$this->assertSame('application/problem+json', (new ReflectionProperty(Response::class, 'headers'))->getValue($request)['Content-Type']);
		$this->assertSame('The request does not match its message schema', $request->getData()['title']);
		$this->assertSame(400, $request->getData()['status']);
		$this->assertSame('about:blank', $request->getData()['type']);
		$this->assertCount(20, $request->getData()['errors']);
		$this->assertSame('/0', $request->getData()['errors'][0]['path']);

		$response = $gate->refusal($outcome, EndpointMessageGate::RESPONSE);
		$this->assertSame(502, $response->getStatus());
		$this->assertSame('The source answered with a message that does not match its message schema', $response->getData()['title']);
		$this->assertSame(502, $response->getData()['status']);

	}//end testARefusalIsAProblemResponseByDirection()

	/**
	 * A finding names the direction, the schema and the first errors.
	 *
	 * @return void
	 */
	public function testAFindingNamesDirectionSchemaAndErrors(): void {
		$finding = $this->gate()->finding(
			ValidationOutcome::failure(message: 'zaak is required', path: '/zaak'),
			EndpointMessageGate::RESPONSE,
			['validation' => ['response' => ['messageSchema' => self::JSON_SCHEMA]]]
		);

		$this->assertSame(
			['direction' => 'response', 'messageSchema' => self::JSON_SCHEMA, 'errors' => [['path' => '/zaak', 'message' => 'zaak is required']]],
			$finding
		);
		$this->assertSame('', $this->gate()->finding(ValidationOutcome::valid(), EndpointMessageGate::REQUEST, [])['messageSchema']);

	}//end testAFindingNamesDirectionSchemaAndErrors()

	/**
	 * No findings: nothing is logged or written.
	 *
	 * @return void
	 */
	public function testNoFindingsRecordNothing(): void {
		$this->objects->expects($this->never())->method('find');
		$this->gate()->record($this->callLog(), [], 'zaken');

		$this->assertSame([], $this->logger->lines);

	}//end testNoFindingsRecordNothing()

	/**
	 * Findings are always logged; without a call log (or one without an id)
	 * they stay in the log only.
	 *
	 * @return void
	 */
	public function testFindingsWithoutACallLogAreOnlyLogged(): void {
		$this->objects->expects($this->never())->method('find');
		$findings = [['direction' => 'request', 'messageSchema' => self::JSON_SCHEMA, 'errors' => []]];

		$this->gate()->record(null, $findings, 'zaken');
		$this->gate()->record(new ObjectEntity(), $findings, 'zaken');

		$this->assertCount(2, $this->logger->lines);
		$this->assertStringContainsString('endpoint zaken let a message through that does not match its message schema (mode record)', $this->logger->lines[0]);
		$this->assertSame([], $this->saves);

	}//end testFindingsWithoutACallLogAreOnlyLogged()

	/**
	 * Findings are merged into the stored call log, never replacing it.
	 *
	 * @return void
	 */
	public function testFindingsAreMergedIntoTheStoredCallLog(): void {
		$findings = [['direction' => 'response', 'messageSchema' => self::JSON_SCHEMA, 'errors' => [['path' => '/', 'message' => 'x']]]];

		$this->gate()->record($this->callLog(), $findings, 'zaken');

		$this->assertSame(
			[['call_log', self::CALL_LOG, ['statusCode' => 200, 'direction' => 'outbound', 'validation' => $findings]]],
			$this->saves
		);

	}//end testFindingsAreMergedIntoTheStoredCallLog()

	/**
	 * A call log that cannot be read, is gone, or cannot be written is
	 * reported and left alone.
	 *
	 * @return void
	 */
	public function testCallLogProblemsAreReportedNotThrown(): void {
		$findings = [['direction' => 'request', 'messageSchema' => self::JSON_SCHEMA, 'errors' => []]];

		$this->stored['call_log'][self::CALL_LOG] = 'throw';
		$this->gate()->record($this->callLog(), $findings, 'zaken');
		$this->assertStringContainsString('could not read call log ' . self::CALL_LOG . ' to write validation findings: database gone', $this->logger->flatten());

		unset($this->stored['call_log'][self::CALL_LOG]);
		$this->gate()->record($this->callLog(), $findings, 'zaken');
		$this->assertStringContainsString('call log ' . self::CALL_LOG . ' not found; validation findings are in the server log only', $this->logger->flatten());

		$this->stored['call_log'][self::CALL_LOG] = ['statusCode' => 200];
		$this->stored['saveFails'] = true;
		$this->gate()->record($this->callLog(), $findings, 'zaken');
		$this->assertStringContainsString('could not write validation findings to call log ' . self::CALL_LOG . ': write refused', $this->logger->flatten());

		$this->assertSame([], $this->saves);

	}//end testCallLogProblemsAreReportedNotThrown()

	/**
	 * The call log entity of the current call.
	 *
	 * @return ObjectEntity
	 */
	private function callLog(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::CALL_LOG);

		return $entity;

	}//end callLog()
}//end class
