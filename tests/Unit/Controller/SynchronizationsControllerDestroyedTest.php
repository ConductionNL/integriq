<?php

/**
 * The signed destroyed route of a synchronization.
 *
 * `POST /api/synchronizations/{id}/destroyed` is a public route: the only
 * thing between an anonymous caller and a permanent delete is the signature
 * of the synchronization's source. A bad or missing signature is refused
 * before the body is read and nothing is deleted.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SynchronizationsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\SourceDestructionService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The destroyed route through the real signature check and destruction service.
 */
final class SynchronizationsControllerDestroyedTest extends TestCase {
	private const SECRET = 'whsec-dms-source';

	private const BODY = '{"originId":"doc-2","reference":"vernietigingslijst-2026-14"}';

	/**
	 * Build the controller for one request.
	 *
	 * @param IRequest&MockObject     $request The request.
	 * @param SynchronizationService  $engine  The engine.
	 * @param string                  $rawBody The raw body bytes.
	 *
	 * @return SynchronizationsController
	 */
	private function makeController(IRequest $request, SynchronizationService $engine, string $rawBody = self::BODY): SynchronizationsController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$sync = ObjectServiceMockBuilder::objectEntity($this, ['sourceId' => 'source-dms', 'targetType' => 'register/schema'], 'sync-1');
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['webhookSignature' => ['scheme' => 'openconnector', 'secret' => self::SECRET, 'header' => 'X-Dms-Signature']]],
			'source-dms'
		);
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('find')->willReturnCallback(
			fn (...$args) => match ((string)$args[0]) {
				'sync-1' => $sync,
				'source-dms' => $source,
				default => null,
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		$controller = $this->getMockBuilder(SynchronizationsController::class)
			->setConstructorArgs(
				[
					'integriq',
					$request,
					$objects,
					$engine,
					$l,
					$logger,
					$this->createMock(IUserSession::class),
					$this->createMock(ActionAuthService::class),
					null,
					new SourceDestructionService($objects, $engine, $logger),
					new WebhookSignatureService($logger),
				]
			)
			->onlyMethods(['getRawContent'])
			->getMock();
		$controller->method('getRawContent')->willReturn($rawBody);

		return $controller;
	}//end makeController()

	/**
	 * A request whose signature header carries the given value.
	 *
	 * @param string $signature The X-Dms-Signature header value.
	 *
	 * @return IRequest&MockObject
	 */
	private function request(string $signature): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name) => ($name === 'X-Dms-Signature') ? $signature : '');

		return $request;
	}//end request()

	/**
	 * A correctly signed call purges through the engine and answers with the outcome.
	 *
	 * @return void
	 */
	public function testASignedCallReachesTheEngine(): void {
		$request = $this->request((new WebhookSignatureService($this->createMock(LoggerInterface::class)))->sign(self::BODY, self::SECRET));
		$request->method('getParams')->willReturn(['id' => 'sync-1', 'originId' => 'doc-2', 'reference' => 'vernietigingslijst-2026-14']);

		$engine = $this->createMock(SynchronizationService::class);
		$engine->expects($this->once())->method('applySourceDestruction')->willReturnCallback(
			function ($synchronization, array $originIds, ?string $reference): array {
				$this->assertSame('sync-1', $synchronization->getUuid());
				$this->assertSame(['doc-2'], $originIds, 'The body\'s originId, not the route\'s synchronization id.');
				$this->assertSame('vernietigingslijst-2026-14', $reference);
				return ['outcome' => 'purged', 'synchronizationId' => 'sync-1', 'originId' => 'doc-2', 'targetId' => 'pub-2'];
			}
		);

		$response = $this->makeController($request, $engine)->destroyed('sync-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('purged', $response->getData()['outcome']);
	}//end testASignedCallReachesTheEngine()

	/**
	 * A forged call is refused before the body is read, and nothing is deleted.
	 *
	 * @return void
	 */
	public function testAForgedCallIsRefusedBeforeTheBodyIsRead(): void {
		$request = $this->request((new WebhookSignatureService($this->createMock(LoggerInterface::class)))->sign(self::BODY, 'not-the-secret'));
		$request->expects($this->never())->method('getParams');

		$engine = $this->createMock(SynchronizationService::class);
		$engine->expects($this->never())->method('applySourceDestruction');

		$response = $this->makeController($request, $engine)->destroyed('sync-1');

		$this->assertSame(401, $response->getStatus());
	}//end testAForgedCallIsRefusedBeforeTheBodyIsRead()

	/**
	 * An unsigned call is refused, and so is a call for a synchronization that does not exist.
	 *
	 * @return void
	 */
	public function testAnUnsignedCallOrAnUnknownSynchronizationIsRefused(): void {
		$engine = $this->createMock(SynchronizationService::class);
		$engine->expects($this->never())->method('applySourceDestruction');

		$unsigned = $this->request('');
		$unsigned->expects($this->never())->method('getParams');
		$this->assertSame(401, $this->makeController($unsigned, $engine)->destroyed('sync-1')->getStatus());

		$signed = $this->request((new WebhookSignatureService($this->createMock(LoggerInterface::class)))->sign(self::BODY, self::SECRET));
		$signed->expects($this->never())->method('getParams');
		$this->assertSame(401, $this->makeController($signed, $engine)->destroyed('sync-unknown')->getStatus(), 'The same answer: never leak whether it exists.');
	}//end testAnUnsignedCallOrAnUnknownSynchronizationIsRefused()

	/**
	 * A signed call without the record's id is a bad request, and nothing is deleted.
	 *
	 * @return void
	 */
	public function testASignedCallWithoutARecordIdIsABadRequest(): void {
		$request = $this->request((new WebhookSignatureService($this->createMock(LoggerInterface::class)))->sign('{}', self::SECRET));
		$request->method('getParams')->willReturn(['id' => 'sync-1']);

		$engine = $this->createMock(SynchronizationService::class);
		$engine->expects($this->never())->method('applySourceDestruction');

		$this->assertSame(400, $this->makeController($request, $engine, '{}')->destroyed('sync-1')->getStatus());
	}//end testASignedCallWithoutARecordIdIsABadRequest()
}//end class
