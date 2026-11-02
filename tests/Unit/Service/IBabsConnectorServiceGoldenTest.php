<?php

/**
 * Golden characterization of the IBabsConnectorService API calls.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\IBabsConnectorService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs every public call of the connector against scripted iBabs answers and
 * records each request, log line, file write and result against a golden
 * file. Regenerate it on the code it must describe with IBABS_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\IBabsConnectorService
 */
class IBabsConnectorServiceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/ibabs/connector-calls.json';

	/**
	 * Everything the calls requested, logged and wrote, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * Scripted answers by endpoint suffix: [status, body] or 'throw'.
	 *
	 * @var array<string, mixed>
	 */
	private array $answers = [];

	/**
	 * Paths that exist in the user folder.
	 *
	 * @var array<int, string>
	 */
	private array $existing = [];

	/**
	 * The cases: name => [method, arguments, answers, existing paths, configuration].
	 *
	 * @return array<string, array{0: string, 1: array, 2: array, 3?: array, 4?: mixed}>
	 */
	private function cases(): array {
		$meetings = [
			['id' => 'm-past', 'datum' => '2000-01-01'],
			['id' => 'm-nodate'],
			['id' => 'm-bad', 'datum' => 'not a date'],
			['vergaderingId' => 'm-future', 'date' => '2999-01-01'],
			['id' => 'm-later', 'datum' => '2999-06-01'],
		];
		$proposal = ['onderwerp' => 'Begroting', 'portefeuillehouder' => 'Wethouder', 'zaaktype' => 'raadsvoorstel', 'geheimhouding' => 1];
		$besluitenlijst = ['RIS-besluiten', 'RIS-besluiten/2026', '/RIS-besluiten/2026/2026-10-01'];

		return [
			'test-no-org' => ['testConnection', [], [], [], []],
			'test-config-json' => ['testConnection', [], ['/org-1/vergaderingen' => [200, []]], [], '{"organisatieId":"org-1"}'],
			'test-config-scalar' => ['testConnection', [], [], [], 5],
			'test-401' => ['testConnection', [], ['/org-1/vergaderingen' => [401, null]]],
			'test-500' => ['testConnection', [], ['/org-1/vergaderingen' => [500, null]]],
			'test-throws' => ['testConnection', [], ['/org-1/vergaderingen' => 'throw']],
			'push-no-org' => ['pushProposal', [$proposal], [], [], []],
			'push-id' => ['pushProposal', [$proposal], ['/org-1/documents' => [201, ['id' => 'doc-1', 'documentId' => 'doc-2']]]],
			'push-document-id' => ['pushProposal', [[]], ['/org-1/documents' => [299, ['documentId' => 'doc-2']]]],
			'push-no-ids' => ['pushProposal', [[]], ['/org-1/documents' => [200, ['other' => 1]]]],
			'push-string-body' => ['pushProposal', [[]], ['/org-1/documents' => [200, 'accepted']]],
			'push-status-0' => ['pushProposal', [$proposal], ['/org-1/documents' => [0, null]]],
			'push-199' => ['pushProposal', [[]], ['/org-1/documents' => [199, null]]],
			'push-300' => ['pushProposal', [[]], ['/org-1/documents' => [300, null]]],
			'push-401' => ['pushProposal', [[]], ['/org-1/documents' => [401, null]]],
			'push-413' => ['pushProposal', [[]], ['/org-1/documents' => [413, null]]],
			'push-throws' => ['pushProposal', [[]], ['/org-1/documents' => 'throw']],
			'agenda-no-org' => ['createAgendaItem', ['doc-1', 'm-1'], [], [], []],
			'agenda-given-meeting' => ['createAgendaItem', ['doc-1', 'm-1'], ['/org-1/vergaderingen/m-1/agendapunten' => [201, ['id' => 'ap-1', 'agendapuntId' => 'ap-2']]]],
			'agenda-agendapunt-id' => ['createAgendaItem', ['doc-1', 'm-1'], ['/org-1/vergaderingen/m-1/agendapunten' => [200, ['agendapuntId' => 'ap-2']]]],
			'agenda-string-body' => ['createAgendaItem', ['doc-1', 'm-1'], ['/org-1/vergaderingen/m-1/agendapunten' => [200, 'ok']]],
			'agenda-next-meeting' => ['createAgendaItem', ['doc-1'], ['/org-1/vergaderingen' => [200, $meetings], '/org-1/vergaderingen/m-future/agendapunten' => [201, ['id' => 'ap-3']]]],
			'agenda-next-meeting-items' => ['createAgendaItem', ['doc-1', null], ['/org-1/vergaderingen' => [200, ['items' => [['id' => 'm-x', 'datum' => '2999-01-01']]]], '/org-1/vergaderingen/m-x/agendapunten' => [201, []]]],
			'agenda-next-meeting-no-id' => ['createAgendaItem', ['doc-1'], ['/org-1/vergaderingen' => [200, [['datum' => '2999-01-01']]]]],
			'agenda-all-past' => ['createAgendaItem', ['doc-1'], ['/org-1/vergaderingen' => [200, [['id' => 'm-past', 'datum' => '2000-01-01']]]]],
			'agenda-no-meetings' => ['createAgendaItem', ['doc-1'], ['/org-1/vergaderingen' => [200, []]]],
			'agenda-items-not-list' => ['createAgendaItem', ['doc-1'], ['/org-1/vergaderingen' => [200, ['items' => 'none']]]],
			'agenda-meetings-string' => ['createAgendaItem', ['doc-1'], ['/org-1/vergaderingen' => [200, 'none']]],
			'agenda-meetings-500' => ['createAgendaItem', ['doc-1'], ['/org-1/vergaderingen' => [500, []]]],
			'agenda-meetings-throw' => ['createAgendaItem', ['doc-1'], ['/org-1/vergaderingen' => 'throw']],
			'agenda-post-199' => ['createAgendaItem', ['doc-1', 'm-1'], ['/org-1/vergaderingen/m-1/agendapunten' => [199, null]]],
			'agenda-post-300' => ['createAgendaItem', ['doc-1', 'm-1'], ['/org-1/vergaderingen/m-1/agendapunten' => [300, null]]],
			'agenda-post-throw' => ['createAgendaItem', ['doc-1', 'm-1'], ['/org-1/vergaderingen/m-1/agendapunten' => 'throw']],
			'poll-no-org' => ['pollDecisions', [[['risMeetingId' => 'm-1', 'caseId' => 'c-1']]], [], [], []],
			'poll-none' => ['pollDecisions', [], []],
			'poll-mixed' => [
				'pollDecisions',
				[
					[
						['risMeetingId' => 'm-1'],
						['caseId' => 'c-0'],
						['risMeetingId' => 'm-1', 'caseId' => 'c-1'],
						['risMeetingId' => 'm-2', 'caseId' => 'c-2'],
						['risMeetingId' => 'm-3', 'caseId' => 'c-3'],
						['risMeetingId' => 'm-4', 'caseId' => 'c-4'],
						['risMeetingId' => 'm-5', 'caseId' => 'c-5'],
						['risMeetingId' => 'm-6', 'caseId' => 'c-6'],
						['risMeetingId' => 'm-7', 'caseId' => 'c-7'],
					],
				],
				[
					'/org-1/vergaderingen/m-1/besluiten' => [200, ['besluitStatus' => 'AANGENOMEN', 'status' => 'x', 'besluitDatum' => '2026-10-01', 'datum' => '2026-09-01']],
					'/org-1/vergaderingen/m-2/besluiten' => [200, ['status' => 'Verworpen', 'datum' => '2026-09-01']],
					'/org-1/vergaderingen/m-3/besluiten' => [200, ['status' => 'iets anders']],
					'/org-1/vergaderingen/m-4/besluiten' => [200, ['other' => 1]],
					'/org-1/vergaderingen/m-5/besluiten' => [404, null],
					'/org-1/vergaderingen/m-6/besluiten' => [200, 'pending'],
					'/org-1/vergaderingen/m-7/besluiten' => 'throw',
				],
			],
			'list-no-org' => ['retrieveBesluitenlijst', ['m-1', '2026-10-01', 'alice'], [], [], []],
			'list-404' => ['retrieveBesluitenlijst', ['m-1', '2026-10-01', 'alice'], ['/org-1/vergaderingen/m-1/besluitenlijst' => [404, null]]],
			'list-500' => ['retrieveBesluitenlijst', ['m-1', '2026-10-01', 'alice'], ['/org-1/vergaderingen/m-1/besluitenlijst' => [500, 'x']]],
			'list-empty' => ['retrieveBesluitenlijst', ['m-1', '2026-10-01', 'alice'], ['/org-1/vergaderingen/m-1/besluitenlijst' => [200, null]]],
			'list-string' => ['retrieveBesluitenlijst', ['m-1', '2026-10-01', 'alice'], ['/org-1/vergaderingen/m-1/besluitenlijst' => [200, '%PDF-1.7']]],
			'list-json' => ['retrieveBesluitenlijst', ['m-1', '2026-10-01', 'bob'], ['/org-1/vergaderingen/m-1/besluitenlijst' => [200, ['besluiten' => [1, 2]]]], [$besluitenlijst[2]]],
			'list-existing-file' => ['retrieveBesluitenlijst', ['m-1', '2026-10-01', 'alice'], ['/org-1/vergaderingen/m-1/besluitenlijst' => [200, 'new']], [$besluitenlijst[2], $besluitenlijst[2] . '/besluitenlijst.pdf']],
			'list-existing-not-file' => ['retrieveBesluitenlijst', ['m-1', '2026-10-01', 'alice'], ['/org-1/vergaderingen/m-1/besluitenlijst' => [200, 'new']], [$besluitenlijst[2], $besluitenlijst[2] . '/besluitenlijst.pdf', 'folder-node']],
			'list-throws' => ['retrieveBesluitenlijst', ['m-1', '2026-10-01', 'alice'], ['/org-1/vergaderingen/m-1/besluitenlijst' => 'throw']],
			'map-status' => ['mapDecisionStatus', ['Aangehouden'], []],
		];

	}//end cases()

	/**
	 * Build the service with mocks that record every call into the trace.
	 *
	 * @return IBabsConnectorService
	 */
	private function service(): IBabsConnectorService {
		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willReturnCallback(
			function (...$args): ObjectEntity {
				$endpoint = (string)($args['endpoint'] ?? $args[1]);
				$this->trace[] = ['call' => $endpoint, 'method' => ($args['method'] ?? $args[2]), 'config' => ($args['config'] ?? $args[3] ?? null)];
				$suffix = str_replace('/api/v1/organisations', '', $endpoint);
				$answer = ($this->answers[$suffix] ?? [418, null]);
				if ($answer === 'throw') {
					throw new RuntimeException('network down');
				}

				$body = $answer[1];
				if (is_array($body) === true) {
					$body = json_encode($body);
				}

				$log = new ObjectEntity();
				$log->setUuid('log-1');
				$log->setObject(['statusCode' => $answer[0], 'response' => ['body' => $body]]);
				return $log;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->trace[] = ['log' => $level, 'message' => (string)$message, 'context' => $context];
				}
			);
		}

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $userId): Folder {
				$this->trace[] = ['userFolder' => $userId];
				return $this->userFolder();
			}
		);

		return new IBabsConnectorService($calls, $logger, $root);

	}//end service()

	/**
	 * A user folder that records every write.
	 *
	 * @return Folder
	 */
	private function userFolder(): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(
			fn (string $path): bool => in_array($path, $this->existing, true)
		);
		$folder->method('newFolder')->willReturnCallback(
			function (string $path) use ($folder): Folder {
				$this->trace[] = ['newFolder' => $path];
				return $folder;
			}
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content = null): File {
				$this->trace[] = ['newFile' => $path, 'content' => $content];
				return $this->createMock(File::class);
			}
		);
		$folder->method('get')->willReturnCallback(
			function (string $path): \OCP\Files\Node {
				$this->trace[] = ['get' => $path];
				if (in_array('folder-node', $this->existing, true) === true) {
					return $this->createMock(Folder::class);
				}

				$file = $this->createMock(File::class);
				$file->method('putContent')->willReturnCallback(
					function ($data) use ($path): void {
						$this->trace[] = ['putContent' => $path, 'content' => $data];
					}
				);
				return $file;
			}
		);

		return $folder;

	}//end userFolder()

	/**
	 * Every call matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryCallMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			[$method, $arguments, $answers] = $case;
			$this->answers = $answers;
			$this->existing = ($case[3] ?? []);
			$this->trace = [];

			$source = new ObjectEntity();
			$source->setUuid('source-1');
			$source->setObject(['configuration' => (array_key_exists(4, $case) === true ? $case[4] : ['organisatieId' => 'org-1'])]);
			if ($method !== 'mapDecisionStatus') {
				array_unshift($arguments, $source);
			}

			try {
				$outcome = ['return' => $this->service()->{$method}(...$arguments)];
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			}

			$actual[$name] = ['trace' => $this->trace, 'outcome' => $outcome];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('IBABS_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame((string)file_get_contents(self::GOLDEN), $encoded);

	}//end testEveryCallMatchesTheGoldenFile()
}//end class
