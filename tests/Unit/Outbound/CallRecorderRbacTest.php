<?php

/**
 * CallRecorder reads and writes call_log past RBAC (REQ-OCD-012).
 *
 * `call_log` is admin-only in the register (Q-integriq-1, decision 137). A call
 * a non-admin triggers, replays or fires must still be recorded, and a reader
 * that CallLogController let in with `call-log.read` must still get the record:
 * the controller is the gate, so the recorder reads and writes with `_rbac: false`.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\BodyRedactor;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Outbound\Call\CallRecorder;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class CallRecorderRbacTest extends TestCase {

	/**
	 * Every call_log access: verb, rbac flag.
	 *
	 * @var array<int,array{verb:string,schema:string,rbac:bool}>
	 */
	private array $calls = [];

	/**
	 * Stored records by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $records = [];

	/**
	 * The recorder under test.
	 *
	 * @var CallRecorder
	 */
	private CallRecorder $recorder;

	/**
	 * Build the recorder on a capturing object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true) {
				$this->calls[] = ['verb' => 'save', 'schema' => (string)$schema, 'rbac' => $_rbac];
				$this->assertSame([], RegisterSchemaValidator::errors((string)$schema, $object));
				$uuid = ($uuid ?? 'call-1');
				$this->records[$uuid] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);
		$objectService->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null, bool $_rbac = true) {
				$this->calls[] = ['verb' => 'find', 'schema' => (string)$schema, 'rbac' => $_rbac];
				return ObjectServiceMockBuilder::objectEntity($this, ($this->records[$id] ?? []), (string)$id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$this->recorder = new CallRecorder($objectService, new BodyRedactor(), new BodyCapturePolicy($appConfig));

	}//end setUp()

	/**
	 * The call_log accesses only.
	 *
	 * @return array<int,array{verb:string,schema:string,rbac:bool}>
	 */
	private function callLogCalls(): array {
		return array_values(array_filter($this->calls, static fn (array $c): bool => $c['schema'] === CallRecorder::SCHEMA));
	}//end callLogCalls()

	/**
	 * Record, append, mark and read all bypass RBAC on call_log.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-call-records-are-readable-only-by-admins-and-through-integriqs-own-endpoints-req-ocd-012
	 */
	public function testEveryCallLogAccessBypassesRbac(): void {
		$this->recorder->record(['target' => 'https://partner.example/api', 'statusCode' => 502, 'statusMessage' => 'Bad gateway']);
		$this->recorder->appendAttempt('call-1', ['statusCode' => 200, 'statusMessage' => 'OK']);
		$this->recorder->markDeadLettered('call-1', 'dead-letter-1');
		$this->recorder->read('call-1');

		$calls = $this->callLogCalls();
		$verbs = array_unique(array_column($calls, 'verb'));
		sort($verbs);
		$this->assertSame(['find', 'save'], $verbs);
		foreach ($calls as $call) {
			$this->assertFalse($call['rbac'], 'call_log ' . $call['verb'] . ' must run with _rbac: false');
		}
	}//end testEveryCallLogAccessBypassesRbac()
}//end class
