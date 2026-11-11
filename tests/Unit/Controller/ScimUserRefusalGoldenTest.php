<?php

/**
 * A refused SCIM user write answers 403 the same way on every user route.
 *
 * Pins, for create, update and delete, the exact 403 body when the refusal
 * carries a safe detail and when it does not, the warning that names the
 * consumer and the refusal, and the allowed writes' answers, so the shared
 * refusal answer can move without changing what a caller or the log sees.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ScimController;
use OCA\Integriq\Directory\ScimProvisioningService;
use OCA\Integriq\Exception\DirectorySyncRefusalException;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for refused and allowed SCIM user writes.
 */
final class ScimUserRefusalGoldenTest extends TestCase {

	/**
	 * Each user route, its provisioning method and how it is called.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function routes(): array {
		return [
			'create' => ['createUser', 'upsertUser'],
			'update' => ['updateUser', 'upsertUser'],
			'delete' => ['deleteUser', 'deactivateUser'],
		];

	}//end routes()

	/**
	 * Call one route on a controller whose provisioning method does $answer.
	 *
	 * @param string         $route  The controller action.
	 * @param string         $method The provisioning method.
	 * @param mixed          $answer A return value, or a Throwable to throw.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return JSONResponse
	 */
	private function call(string $route, string $method, mixed $answer, LoggerInterface $logger): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer right-key');
		$request->method('getParams')->willReturn(['userName' => 'alice']);
		$request->method('getParam')->willReturn([]);

		$consumer = $this->createMock(ObjectEntity::class);
		$consumer->method('getUuid')->willReturn('consumer-1');
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->method('getResolvedConsumer')->willReturn($consumer);

		$provisioning = $this->createMock(ScimProvisioningService::class);
		if ($answer instanceof \Throwable) {
			$provisioning->expects($this->once())->method($method)->willThrowException($answer);
		} else {
			$provisioning->expects($this->once())->method($method)->willReturn($answer);
		}

		$controller = new ScimController(
			appName: 'integriq',
			request: $request,
			provisioningService: $provisioning,
			authorizationService: $bridge,
			logger: $logger
		);

		if ($route === 'createUser') {
			return $controller->createUser();
		}

		return $controller->$route(id: 'alice');

	}//end call()

	/**
	 * A refusal with a safe detail answers 403 with that detail and logs the reason.
	 *
	 * @param string $route  The controller action.
	 * @param string $method The provisioning method.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testARefusalWithADetailAnswersIt(string $route, string $method): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[Scim] refused a user write for consumer consumer-1: the account is an administrator');

		$response = $this->call(
			route: $route,
			method: $method,
			answer: new DirectorySyncRefusalException(message: 'the account is an administrator', context: ['detail' => 'This account is protected.']),
			logger: $logger
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => '403', 'detail' => 'This account is protected.'],
			$response->getData()
		);

	}//end testARefusalWithADetailAnswersIt()

	/**
	 * A refusal without a detail answers the default sentence.
	 *
	 * @param string $route  The controller action.
	 * @param string $method The provisioning method.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testARefusalWithoutADetailAnswersTheDefault(string $route, string $method): void {
		$response = $this->call(
			route: $route,
			method: $method,
			answer: new DirectorySyncRefusalException(message: 'no', context: []),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('This account cannot be managed over SCIM.', $response->getData()['detail']);

	}//end testARefusalWithoutADetailAnswersTheDefault()

	/**
	 * An allowed write answers its resource, and logs no refusal.
	 *
	 * @param string $route  The controller action.
	 * @param string $method The provisioning method.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testAnAllowedWriteAnswersItsResource(string $route, string $method): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$answer = ['id' => 'alice'];
		if ($method === 'deactivateUser') {
			$answer = ['cases' => 2];
		}

		$response = $this->call(route: $route, method: $method, answer: $answer, logger: $logger);

		$expected = [
			'createUser' => [Http::STATUS_CREATED, ['id' => 'alice']],
			'updateUser' => [Http::STATUS_OK, ['id' => 'alice']],
			'deleteUser' => [Http::STATUS_OK, ['active' => false, 'openWork' => ['cases' => 2]]],
		][$route];
		$this->assertSame($expected, [$response->getStatus(), $response->getData()]);

	}//end testAnAllowedWriteAnswersItsResource()
}//end class
