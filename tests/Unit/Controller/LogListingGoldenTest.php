<?php

/**
 * Golden characterization of the job and synchronization log listings.
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
 * @spec openspec/specs/job-scheduling/spec.md
 * @spec openspec/specs/synchronization-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\JobsController;
use OCA\Integriq\Controller\SynchronizationsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SearchService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Lists job logs and synchronization logs over paging and filter parameters,
 * the shapes OpenRegister answers in and a failing query. Records the query
 * handed to OpenRegister and the response against a golden file.
 *
 * Regenerate the golden file on the code it must describe with
 * LOG_LISTING_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Controller\JobsController
 * @covers \OCA\Integriq\Controller\SynchronizationsController
 * @uses   \OCA\Integriq\Service\SearchService
 */
class LogListingGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/controller/log-listing.json';

	/**
	 * The cases: request parameters and what OpenRegister answers.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: mixed}>
	 */
	private function cases(): array {
		$page = ['results' => [['id' => 'a'], ['id' => 'b']], 'total' => 45];
		return [
			'defaults' => [[], $page],
			'limit-and-page' => [['_limit' => '10', '_page' => '3'], $page],
			'limit-zero' => [['_limit' => 0], $page],
			'negative-page' => [['_page' => -1, '_limit' => 5], $page],
			'special-filters-pass-through' => [['date_from' => '2026-01-01', 'date_to' => '2026-02-01', 'status' => 'error', 'slow_executions' => '1', 'slow_syncs' => '1', '_search' => 'x', 'name' => 'n'], $page],
			'empty-special-filters' => [['date_from' => '', 'status' => 0, 'other' => 'kept'], $page],
			'bare-list-answer' => [[], [['id' => 'only']]],
			'no-total' => [['_limit' => 1], ['results' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]]],
			'query-fails' => [[], 'throw'],
		];
	}//end cases()

	/**
	 * Run one listing.
	 *
	 * @param string               $controller Which controller.
	 * @param array<string, mixed> $params     The request parameters.
	 * @param mixed                $answer     What OpenRegister answers, or 'throw'.
	 *
	 * @return array<string, mixed>
	 */
	private function listing(string $controller, array $params, mixed $answer): array {
		$queries = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$queries, $answer) {
				$queries[] = $config;
				if ($answer === 'throw') {
					throw new RuntimeException('database gone');
				}

				return $answer;
			}
		);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $args = []) => vsprintf($text, $args));

		if ($controller === 'jobs') {
			$subject = new JobsController(
				'integriq',
				$request,
				$objects,
				$this->createMock(JobService::class),
				$l,
				$this->createMock(IUserSession::class),
				$this->createMock(ActionAuthService::class)
			);
		} else {
			$subject = new SynchronizationsController(
				'integriq',
				$request,
				$objects,
				$this->createMock(SynchronizationService::class),
				$l,
				$this->createMock(LoggerInterface::class),
				$this->createMock(IUserSession::class),
				$this->createMock(ActionAuthService::class)
			);
		}

		$response = $subject->logs(searchService: new SearchService($this->createMock(IURLGenerator::class)));

		return ['status' => $response->getStatus(), 'data' => $response->getData(), 'queries' => $queries];
	}//end listing()

	/**
	 * Every listing behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryListingMatchesTheGoldenFile(): void {
		$actual = [];
		foreach (['jobs', 'synchronizations'] as $controller) {
			foreach ($this->cases() as $name => [$params, $answer]) {
				$actual[$controller . '/' . $name] = $this->listing(controller: $controller, params: $params, answer: $answer);
			}
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('LOG_LISTING_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryListingMatchesTheGoldenFile()
}//end class
