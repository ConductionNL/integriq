<?php

/**
 * Golden characterization of SourcesController::logs().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/logs-and-statistics/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use Exception;
use OCA\Integriq\Controller\SourcesController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\SearchService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Every request shape of logs(), recorded against a golden file: the query
 * handed to OpenRegister and the response answered.
 *
 * Regenerate the golden file on the code it must describe with
 * SOURCE_LOGS_GOLDEN=1.
 */
class SourcesControllerLogsTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/controller/sources-logs.json';

	/**
	 * The cases: request parameters and what OpenRegister answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$three = ['results' => [['id' => 1], ['id' => 2], ['id' => 3]], 'total' => 47];

		return [
			'defaults' => ['params' => [], 'answer' => $three],
			'limit-and-page' => ['params' => ['_limit' => '10', '_page' => '3'], 'answer' => $three],
			'limit-zero' => ['params' => ['_limit' => 0, '_page' => 2], 'answer' => $three],
			'page-zero' => ['params' => ['_limit' => 5, '_page' => 0], 'answer' => $three],
			'plain-filters-pass' => ['params' => ['method' => 'POST', 'sourceId' => 's-1', '_order' => 'x'], 'answer' => $three],
			'special-filters-dropped' => ['params' => ['date_from' => '2026-01-01', 'date_to' => '2026-02-01', 'endpoint' => '/zaken', 'status_code' => '200,299', 'slow_requests' => '1', 'method' => 'GET'], 'answer' => $three],
			'status-code-single' => ['params' => ['status_code' => '500'], 'answer' => $three],
			'empty-special-filters-pass' => ['params' => ['date_from' => '', 'endpoint' => '0', 'status_code' => null, 'slow_requests' => 0], 'answer' => $three],
			'sort-array' => ['params' => ['_sort' => ['created' => 'desc', 'endpoint' => 'asc', 'other' => 'DESC']], 'answer' => $three],
			'sort-not-array' => ['params' => ['_sort' => 'created'], 'answer' => $three],
			'list-answer' => ['params' => ['_limit' => 2], 'answer' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]],
			'empty-answer' => ['params' => [], 'answer' => ['results' => [], 'total' => 0]],
			'or-fails' => ['params' => [], 'answer' => 'throw'],
		];
	}//end cases()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$queries = [];
			$or = $this->createMock(OrObjectService::class);
			$or->method('findAll')->willReturnCallback(
				function (array $config) use (&$queries, $case) {
					$queries[] = $config;
					if ($case['answer'] === 'throw') {
						throw new Exception('database down');
					}

					return $case['answer'];
				}
			);

			$request = $this->createMock(IRequest::class);
			$request->method('getParams')->willReturn($case['params']);
			$l = $this->createMock(IL10N::class);
			$l->method('t')->willReturnCallback(fn (string $text, array $args = []) => vsprintf($text, $args));

			$controller = new SourcesController('integriq', $request, $or, $l, $this->createMock(IUserSession::class), $this->createMock(ActionAuthService::class));
			$response = $controller->logs(new SearchService(...$this->searchServiceArgs()));

			$actual[$name] = ['status' => $response->getStatus(), 'data' => $response->getData(), 'queries' => $queries];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('SOURCE_LOGS_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()

	/**
	 * Constructor arguments for a real SearchService (only unsetSpecialQueryParams() is used).
	 *
	 * @return array<int, mixed>
	 */
	private function searchServiceArgs(): array {
		$constructor = (new \ReflectionClass(SearchService::class))->getConstructor();
		if ($constructor === null) {
			return [];
		}

		return array_map(
			fn (\ReflectionParameter $param) => $this->createMock((string)$param->getType()),
			$constructor->getParameters()
		);
	}//end searchServiceArgs()
}//end class
