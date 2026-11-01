<?php

/**
 * Golden characterization of LogsController::export().
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

use OCA\Integriq\Controller\LogsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exports over the session and action checks, the filters handed to
 * OpenRegister, CSV quoting and missing fields, and a failing query. Records
 * the acting user the action check ran for (also with a forged userId request
 * parameter), the query and the response against a golden file.
 *
 * Regenerate the golden file on the code it must describe with
 * LOGS_EXPORT_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Controller\LogsController
 */
class LogsControllerExportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/controller/logs-export.json';

	/**
	 * A log row as OpenRegister returns it.
	 *
	 * @param string|null          $uuid   The uuid.
	 * @param array<string, mixed> $object The log body.
	 *
	 * @return ObjectEntity
	 */
	private function row(?string $uuid, array $object): ObjectEntity {
		$log = $this->createMock(ObjectEntity::class);
		$log->method('getObject')->willReturn($object);
		$log->method('getUuid')->willReturn($uuid);
		return $log;
	}//end row()

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$rows = [
			$this->row('u-1', ['level' => 'error', 'message' => 'said "hi", twice', 'synchronizationId' => 's-1', 'userId' => 'alice', 'sessionId' => 'x', 'created' => '2026-01-01', 'expires' => '2026-02-01']),
			$this->row('', ['message' => 'plain']),
			$this->row('u-3', ['created' => null, 'expires' => '2026-03-01', 'level' => 'info']),
		];
		return [
			'no-session' => ['session' => false],
			'refused' => ['refuse' => true],
			'forged-user-param' => ['params' => ['userId' => 'mallory'], 'answer' => ['results' => $rows]],
			'all-filters' => ['args' => ['level' => 'error', 'message' => 'm', 'synchronizationId' => 's-1', 'dateFrom' => '2026-01-01', 'dateTo' => '2026-12-31'], 'answer' => ['results' => $rows]],
			'some-filters' => ['args' => ['message' => '', 'dateTo' => '2026-12-31'], 'answer' => ['results' => []]],
			'bare-list' => ['answer' => $rows],
			'query-fails' => ['answer' => 'throw'],
		];
	}//end cases()

	/**
	 * Every export behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryExportMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$calls = [];
			$actingUser = $this->createMock(IUser::class);
			$actingUser->method('getUID')->willReturn('alice');
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn(($case['session'] ?? true) === true ? $actingUser : null);

			$auth = $this->createMock(ActionAuthService::class);
			$auth->method('requireAction')->willReturnCallback(
				function (IUser $user, string $action) use (&$calls, $actingUser, $case) {
					$calls[] = ['requireAction', ($user === $actingUser ? 'session-user' : $user->getUID()), $action];
					if (($case['refuse'] ?? false) === true) {
						throw new OCSForbiddenException('not for you');
					}
				}
			);

			$objects = $this->createMock(OrObjectService::class);
			$objects->method('findAll')->willReturnCallback(
				function (array $config) use (&$calls, $case) {
					$calls[] = ['findAll', $config];
					if (($case['answer'] ?? null) === 'throw') {
						throw new RuntimeException('gone');
					}

					return ($case['answer'] ?? ['results' => []]);
				}
			);

			$request = $this->createMock(IRequest::class);
			$request->method('getParams')->willReturn(($case['params'] ?? []));
			$l = $this->createMock(IL10N::class);
			$l->method('t')->willReturnArgument(0);

			$controller = new LogsController('integriq', $request, $objects, $l, $session, $auth);
			$response = $controller->export(...($case['args'] ?? []));
			$data = $response->getData();
			if (isset($data['filename']) === true) {
				$data['filename'] = preg_replace('/\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}/', '(date)', $data['filename']);
			}

			$actual[$name] = ['status' => $response->getStatus(), 'data' => $data, 'calls' => $calls];
		}//end foreach

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('LOGS_EXPORT_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryExportMatchesTheGoldenFile()
}//end class
