<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use GuzzleHttp\Exception\TransferException;
use OCA\Integriq\Exception\TablesNotFoundException;
use OCA\Integriq\Exception\TablesPermissionDeniedException;
use OCA\Integriq\Exception\TablesUpstreamException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\OcsApi;
use OCA\Integriq\Service\Helper\OcsApiDispatcher;
use OCA\Integriq\Service\Helper\OcsRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One OCS call through CallService: the status code chooses the API's own
 * exception (401/403 permission, 404 not found, other non-2xx upstream), a
 * transport failure is logged and raised as upstream, and a 2xx answer is
 * the decoded JSON body or null.
 *
 * @covers \OCA\Integriq\Service\Helper\OcsApiDispatcher
 * @uses   \OCA\Integriq\Service\Helper\OcsApi
 * @uses   \OCA\Integriq\Service\Helper\OcsRequest
 * @uses   \OCA\Integriq\Exception\TablesNotFoundException
 * @uses   \OCA\Integriq\Exception\TablesPermissionDeniedException
 * @uses   \OCA\Integriq\Exception\TablesUpstreamException
 */
final class OcsApiDispatcherTest extends TestCase {

	private CallService&MockObject $callService;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->callService = $this->createMock(CallService::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
	}

	public function testItCallsTheSourceWithTheRequestAndReturnsTheDecodedBody(): void {
		$request = $this->request(endpoint: 'apps/tables/api/2/tables', method: 'POST', config: ['json' => ['a' => 1]]);
		$this->callService->expects($this->once())->method('call')
			->with($request->source, 'apps/tables/api/2/tables', 'POST', ['json' => ['a' => 1]])
			->willReturn($this->callLog(status: 201, body: '{"ocs":{"data":[1,2]}}'));

		$this->assertSame(['ocs' => ['data' => [1, 2]]], $this->dispatcher()->dispatch(request: $request));
	}

	public function testAnEmptyBodyIsNull(): void {
		$this->callService->method('call')->willReturn($this->callLog(status: 204, body: ''));

		$this->assertNull($this->dispatcher()->dispatch(request: $this->request()));
	}

	public function testABodyThatIsNotAJsonObjectOrListIsNull(): void {
		$this->callService->method('call')->willReturn($this->callLog(status: 200, body: '"just a string"'));
		$this->assertNull($this->dispatcher()->dispatch(request: $this->request()));
	}

	public function testABodyThatIsNotJsonIsNull(): void {
		$this->callService->method('call')->willReturn($this->callLog(status: 200, body: '<html>'));
		$this->assertNull($this->dispatcher()->dispatch(request: $this->request()));
	}

	/**
	 * @dataProvider deniedStatuses
	 */
	public function testA401Or403IsThePermissionExceptionWithItsStatus(int $status): void {
		$this->callService->method('call')->willReturn($this->callLog(status: $status, body: '{}'));

		try {
			$this->dispatcher()->dispatch(request: $this->request());
			$this->fail('Expected a permission refusal');
		} catch (TablesPermissionDeniedException $e) {
			$this->assertSame("Tables API denied GET apps/tables/api/2/tables (HTTP {$status})", $e->getMessage());
			$this->assertSame($status, $e->getCode());
		}
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function deniedStatuses(): array {
		return ['401' => [401], '403' => [403]];
	}

	public function testA404IsTheNotFoundException(): void {
		$this->callService->method('call')->willReturn($this->callLog(status: 404, body: '{}'));

		$this->expectException(TablesNotFoundException::class);
		$this->expectExceptionMessage('Tables API resource not found: GET apps/tables/api/2/tables');
		$this->dispatcher()->dispatch(request: $this->request());
	}

	/**
	 * @dataProvider failedStatuses
	 */
	public function testAnyOtherNon2xxIsUpstreamNamingTheCallLog(int $status): void {
		$this->callService->method('call')->willReturn($this->callLog(status: $status, body: '{"ok":true}'));

		$this->expectException(TablesUpstreamException::class);
		$this->expectExceptionMessage("Tables API returned HTTP {$status} for GET apps/tables/api/2/tables (see CallLog log-1)");
		$this->dispatcher()->dispatch(request: $this->request());
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function failedStatuses(): array {
		return ['no status' => [0], 'redirect' => [302], 'server error' => [500], 'below 200' => [199]];
	}

	public function testATransportFailureIsLoggedAndRaisedAsUpstream(): void {
		$this->callService->method('call')->willThrowException(new TransferException('connection refused'));
		$this->logger->expects($this->once())->method('warning')->with(
			'TablesOcsClient: transport failure calling Tables API',
			['endpoint' => 'apps/tables/api/2/tables', 'method' => 'GET', 'error' => 'connection refused']
		);

		$this->expectException(TablesUpstreamException::class);
		$this->expectExceptionMessage('Failed to reach the Tables API (GET apps/tables/api/2/tables): connection refused');
		$this->dispatcher()->dispatch(request: $this->request());
	}

	public function testAnUnlistedExceptionIsNotCaught(): void {
		$this->callService->method('call')->willThrowException(new \LogicException('bug'));
		$this->logger->expects($this->never())->method('warning');

		$this->expectException(\LogicException::class);
		$this->dispatcher()->dispatch(request: $this->request());
	}

	private function dispatcher(): OcsApiDispatcher {
		return new OcsApiDispatcher(callService: $this->callService, logger: $this->logger);
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function request(string $endpoint='apps/tables/api/2/tables', string $method='GET', array $config=[]): OcsRequest {
		$api = new OcsApi(
			label: 'Tables',
			permissionDenied: TablesPermissionDeniedException::class,
			notFound: TablesNotFoundException::class,
			upstream: TablesUpstreamException::class
		);

		return new OcsRequest(api: $api, source: new ObjectEntity(), endpoint: $endpoint, method: $method, config: $config);
	}

	private function callLog(int $status, string $body): ObjectEntity {
		$log = new ObjectEntity();
		$log->setUuid('log-1');
		$log->setObject(['response' => ['statusCode' => $status, 'body' => $body]]);

		return $log;
	}
}
