<?php

/**
 * Unit tests for DatasourceController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DatasourceController;
use OCA\Integriq\Service\Datasource\DashboardDatasourceService;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for `POST /api/datasource/{sourceId}/resolve`.
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md
 */
class DatasourceControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var DashboardDatasourceService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $service;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	private DatasourceController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(DashboardDatasourceService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(
			static function (string $text, array $params = []) {
				return vsprintf(str_replace('%s', '%s', $text), $params);
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new DatasourceController(
			appName: 'integriq',
			request: $this->request,
			l: $this->l,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A successful resolve returns the service's `{value, fetchedAt, stale}` verbatim.
	 */
	public function testResolveReturnsServiceResult(): void {
		$this->request->method('getParams')->willReturn(['valueExpr' => '$.data.open_count']);
		$this->service->method('resolve')->willReturn(['value' => 5, 'fetchedAt' => '2026-07-23T00:00:00+00:00', 'stale' => false]);

		$response = $this->controller->resolve(service: $this->service, sourceId: 'src-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['value' => 5, 'fetchedAt' => '2026-07-23T00:00:00+00:00', 'stale' => false], $response->getData());
	}//end testResolveReturnsServiceResult()

	/**
	 * A missing valueExpr is rejected with 400 before the service is called.
	 */
	public function testMissingValueExprReturns400(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->expects($this->never())->method('resolve');

		$response = $this->controller->resolve(service: $this->service, sourceId: 'src-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testMissingValueExprReturns400()

	/**
	 * A caller-supplied url/host in `params` never reaches the service —
	 * the controller strips it before calling resolve().
	 */
	public function testCallerSuppliedUrlIsStrippedBeforeServiceCall(): void {
		$this->request->method('getParams')->willReturn(
			[
				'valueExpr' => '$.ok',
				'params' => ['url' => 'https://evil.example', 'host' => 'evil.example', 'q' => 'kept'],
			]
		);

		$this->service->expects($this->once())
			->method('resolve')
			->with(
				$this->equalTo('src-1'),
				$this->equalTo('$.ok'),
				$this->callback(
					static fn (array $params): bool => array_key_exists('url', $params) === false
						&& array_key_exists('host', $params) === false
						&& ($params['q'] ?? null) === 'kept'
				),
				$this->anything(),
			)
			->willReturn(['value' => true, 'fetchedAt' => 'now', 'stale' => false]);

		$this->controller->resolve(service: $this->service, sourceId: 'src-1');
	}//end testCallerSuppliedUrlIsStrippedBeforeServiceCall()

	/**
	 * NotAuthorizedException from the service maps to 403 — the source's own
	 * read-authorization is honoured.
	 */
	public function testUnauthorizedSourceReturns403(): void {
		$this->request->method('getParams')->willReturn(['valueExpr' => '$.data.open_count']);
		$this->service->method('resolve')->willThrowException(new NotAuthorizedException('nope'));

		$response = $this->controller->resolve(service: $this->service, sourceId: 'src-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testUnauthorizedSourceReturns403()

	/**
	 * DoesNotExistException from the service maps to 404.
	 */
	public function testMissingSourceReturns404(): void {
		$this->request->method('getParams')->willReturn(['valueExpr' => '$.data.open_count']);
		$this->service->method('resolve')->willThrowException($this->createMock(DoesNotExistException::class));

		$response = $this->controller->resolve(service: $this->service, sourceId: 'src-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testMissingSourceReturns404()

	/**
	 * The ttl and params a request carries, and what reaches the service.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:int|null}>
	 */
	public static function requestReadings(): array {
		return [
			'no ttl, no params'       => [[], [], null],
			'numeric string ttl'      => [['ttl' => '30'], [], 30],
			'fractional ttl'          => [['ttl' => 12.7], [], 12],
			'non-numeric ttl'         => [['ttl' => 'soon'], [], null],
			'null ttl'                => [['ttl' => null], [], null],
			'params not a list'       => [['params' => 'q=1'], [], null],
			'params kept, url gone'   => [['params' => ['q' => 1, 'url' => 'x']], ['q' => 1], null],
		];

	}//end requestReadings()

	/**
	 * Each reading reaches the service as recorded.
	 *
	 * @param array<string,mixed> $body   The request body besides valueExpr.
	 * @param array<string,mixed> $params The params the service sees.
	 * @param int|null            $ttl    The ttl the service sees.
	 *
	 * @return void
	 *
	 * @dataProvider requestReadings
	 */
	public function testTheRequestReachesTheService(array $body, array $params, ?int $ttl): void {
		$this->request->method('getParams')->willReturn(['valueExpr' => '$.ok'] + $body);
		$this->service->expects($this->once())->method('resolve')
			->with('src-1', '$.ok', $params, $ttl)
			->willReturn(['value' => 1]);

		$this->assertSame(['value' => 1], $this->controller->resolve(service: $this->service, sourceId: 'src-1')->getData());

	}//end testTheRequestReachesTheService()

	/**
	 * The error bodies, and only the unexpected failure is logged.
	 *
	 * @return void
	 */
	public function testTheErrorBodies(): void {
		$this->request->method('getParams')->willReturnOnConsecutiveCalls(
			['valueExpr' => ''],
			['valueExpr' => '$.ok'],
			['valueExpr' => '$.ok'],
			['valueExpr' => '$.ok']
		);
		$failure = new \RuntimeException('boom');
		$this->service->method('resolve')->willReturnOnConsecutiveCalls(
			$this->throwException(new NotAuthorizedException('nope')),
			$this->throwException($this->createMock(DoesNotExistException::class)),
			$this->throwException($failure)
		);
		$this->logger->expects($this->once())->method('error')
			->with('dashboard-http-datasource: resolve failed unexpectedly: boom', ['exception' => $failure]);

		$answers = [];
		for ($i = 0; $i < 4; $i++) {
			$response = $this->controller->resolve(service: $this->service, sourceId: 'src-1');
			$answers[] = [$response->getStatus(), $response->getData()];
		}

		$this->assertSame(
			[
				[Http::STATUS_BAD_REQUEST, ['error' => 'valueExpr is required']],
				[Http::STATUS_FORBIDDEN, ['error' => 'Not authorized to read this source']],
				[Http::STATUS_NOT_FOUND, ['error' => 'Source not found']],
				[Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Failed to resolve value']],
			],
			$answers
		);

	}//end testTheErrorBodies()
}//end class
