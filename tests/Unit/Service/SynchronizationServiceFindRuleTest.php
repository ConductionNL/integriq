<?php

/**
 * `findRule()` reads a rule the way the MIGRATION needs it read.
 *
 * WHY THIS TEST EXISTS, WITH THE NUMBER THAT PAID FOR IT.
 *
 * `openconnector.fetch-file` shipped complete and was unreachable. A sweep of
 * all 240 synchronizations on the dev instance still refused exactly the same
 * 74 as before it existed, and ALL 74 gave one reason:
 *
 *   actions: rule "xxllnc-fetch-files" could not be resolved
 *
 * Not one was refused for its rule's TYPE. The rule was in the database the
 * whole time (`slug=xxllnc-fetch-files`, `type=fetch_file`). `findRule()`
 * delegated to `getRuleById()`, which reads WITH rbac and multitenancy — right
 * for the legacy engine's in-request pipeline, wrong for a generator that runs
 * under `occ`, where there is no user session and a scoped read matches
 * nothing.
 *
 * With the flags passed, the same sweep returned 235/240. The feature did not
 * change; only whether the generator could see its own input.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The rule lookup the flow generator depends on.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
class SynchronizationServiceFindRuleTest extends TestCase {

	/**
	 * The OpenRegister object service double.
	 *
	 * @var ORObjectService&MockObject
	 */
	private $orObjectService;

	/**
	 * The service under test.
	 *
	 * @var SynchronizationService
	 */
	private SynchronizationService $service;

	/**
	 * Build the service over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);

		$this->service = new SynchronizationService(
			$this->createMock(CallService::class),
			$this->createMock(MappingService::class),
			$this->createMock(ContainerInterface::class),
			$this->orObjectService,
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SynchronizationLogService::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(\OCA\Integriq\Service\ApprovalService::class)
		);

	}//end setUp()

	/**
	 * The rule read is UNSCOPED, because the generator runs without a session.
	 *
	 * @return void
	 */
	public function testTheRuleIsReadWithoutRbacOrMultitenancy(): void {
		// A MOCKED entity, not a real one. `ObjectEntity::jsonSerialize()` needs
		// more state than a rule fixture carries and throws without it — which
		// `findRule()` catches and turns into null, so a real entity here makes
		// the test fail for a reason that has nothing to do with the rule
		// lookup. What this test is about is which FLAGS the read is made with.
		$entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(
			['slug' => 'xxllnc-fetch-files', 'type' => 'fetch_file', 'timing' => 'after']
		);

		// CAPTURED, NOT MATCHED WITH `with()`.
		//
		// `findRule()` catches `\Throwable` and returns null, and PHPUnit raises
		// an argument mismatch AS an exception at call time — so a `with()` that
		// does not match is SWALLOWED by the code under test and reappears only
		// as a null return, with no diff and nothing naming the argument that
		// differed. A test whose failure mode is indistinguishable from the bug
		// it is meant to catch is not much of a test.
		//
		// ⚠️ THE PARAMETER ORDER IS (id, register, schema, _rbac, _multitenancy, …)
		// — VERIFIED against the mock, not read off a source file.
		//
		// openregister ships more than one `ObjectService::find()` signature: the
		// copy on its development branch orders them
		// (id, _extend, files, register, schema, _rbac, …), and the copy this app
		// autoloads does not. Mirroring the wrong one silently captures the wrong
		// argument — my first attempt read `files` as `_rbac` and failed while the
		// production code was correct. If this test starts failing on the flags,
		// dump the arguments before changing the assertion.
		$seen = [];
		$this->orObjectService->expects($this->once())
			->method('find')
			->willReturnCallback(
				function (
					$id,
					$register = null,
					$schema = null,
					bool $_rbac = true,
					bool $_multitenancy = true,
					...$rest,
				) use ($entity, &$seen) {
					$seen = compact('id', 'register', 'schema', '_rbac', '_multitenancy');

					return $entity;
				}
			);

		$rule = $this->service->findRule(id: 'xxllnc-fetch-files');

		$this->assertNotNull($rule, 'The rule exists, so it must be returned.');
		$this->assertSame('fetch_file', $rule['type']);

		$this->assertSame('xxllnc-fetch-files', $seen['id']);
		$this->assertSame('integriq', $seen['register']);
		$this->assertSame('rule', $seen['schema']);

		// THE ASSERTIONS THIS FILE IS FOR. Under `occ` there is no user session,
		// so a scoped read matches nothing, `findRule()` returns null, and the
		// generator refuses the synchronization for a rule it could not see —
		// which reads identically to "this rule type is unsupported".
		$this->assertFalse(
			$seen['_rbac'],
			'The generator runs without a session; an rbac-scoped read returns null '
				. 'and is indistinguishable from an unsupported rule.'
		);
		$this->assertFalse(
			$seen['_multitenancy'],
			'Same for multitenancy — a rule is configuration, and the migration must read all of it.'
		);

	}//end testTheRuleIsReadWithoutRbacOrMultitenancy()

	/**
	 * A read that THROWS yields null rather than a fatal.
	 *
	 * The catch is fully qualified (`\Throwable`) on purpose: this file's class
	 * declares `namespace OCA\Integriq\Service` and imports `Exception` but not
	 * `Throwable`, so a bare `Throwable` would resolve to
	 * `OCA\Integriq\Service\Throwable`, match nothing, and let the failure
	 * escape. `php -l` does not catch that, so it is asserted here.
	 *
	 * @return void
	 */
	public function testAFailingReadYieldsNullRatherThanEscaping(): void {
		$this->orObjectService->method('find')->willThrowException(
			new \RuntimeException('the register boundary refused this read')
		);

		$this->assertNull($this->service->findRule(id: 'anything'));

	}//end testAFailingReadYieldsNullRatherThanEscaping()

}//end class
