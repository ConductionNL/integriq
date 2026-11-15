<?php

/**
 * Unit tests for DirectorySource — reading a directory connection, and the
 * absence claim that integriq holds no credential for a synchronised account.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Directory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Directory;

use OCA\Integriq\Directory\DirectoryEntry;
use OCA\Integriq\Directory\DirectorySource;
use OCA\Integriq\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for reading a directory connection.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
 */
class DirectorySourceTest extends TestCase {

	/**
	 * The source under test, over a call service that must never be called in
	 * mock mode.
	 *
	 * @param CallService|null $callService An explicit call service, or null for a strict mock.
	 *
	 * @return DirectorySource The source.
	 */
	private function source(?CallService $callService = null): DirectorySource {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new DirectorySource(
			orObjectService: $this->createMock(OrObjectService::class),
			callService: ($callService ?? $this->createMock(CallService::class)),
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end source()

	/**
	 * A connection in mock mode carrying a fixture.
	 *
	 * @param array<string,mixed> $fixture The fixture.
	 *
	 * @return ObjectEntity The connection.
	 */
	private function connection(array $fixture): ObjectEntity {
		$connection = new ObjectEntity();
		$connection->setUuid('conn-1');
		$connection->setObject(['type' => 'directory', 'configuration' => ['mock' => true, 'fixture' => $fixture]]);

		return $connection;
	}//end connection()

	/**
	 * The mock fixture is read into entries, groups and all.
	 *
	 * @return void
	 */
	public function testTheMockFixtureIsReadIntoEntries(): void {
		$snapshot = $this->source()->read(
			connection: $this->connection(
				fixture: [
					'users' => [
						['userName' => 'anja', 'displayName' => 'Anja', 'groups' => ['OU=Vergunningen']],
						['userName' => 'bram', 'groups' => [['display' => 'OU=Toezicht', 'value' => 'g-2']]],
					],
				]
			)
		);

		$this->assertTrue($snapshot->isComplete());
		$this->assertCount(2, $snapshot->getEntries());
		$this->assertSame('anja', $snapshot->getEntries()[0]->getUserId());
		$this->assertSame(['OU=Toezicht'], $snapshot->getEntries()[1]->getDirectoryGroups());
		$this->assertSame(['OU=Vergunningen', 'OU=Toezicht'], $snapshot->getDirectoryGroups());

	}//end testTheMockFixtureIsReadIntoEntries()

	/**
	 * A fixture declaring itself truncated answers an incomplete snapshot.
	 *
	 * @return void
	 */
	public function testATruncatedFixtureAnswersAnIncompleteSnapshot(): void {
		$snapshot = $this->source()->read(
			connection: $this->connection(
				fixture: ['complete' => false, 'users' => [['userName' => 'anja']]]
			)
		);

		$this->assertFalse($snapshot->isComplete());

	}//end testATruncatedFixtureAnswersAnIncompleteSnapshot()

	/**
	 * A row without an account name is captured with its reason, not dropped.
	 *
	 * @return void
	 */
	public function testARowWithoutAnAccountNameIsCaptured(): void {
		$snapshot = $this->source()->read(
			connection: $this->connection(fixture: ['users' => [['groups' => ['OU=Toezicht']]]])
		);

		$this->assertCount(0, $snapshot->getEntries());
		$this->assertCount(1, $snapshot->getFailures());
		$this->assertStringContainsString('no account name', $snapshot->getFailures()[0]['reason']);

	}//end testARowWithoutAnAccountNameIsCaptured()

	/**
	 * Integriq persists no credential for a synchronised account.
	 *
	 * REQ-DS-001 says integriq MUST NOT hold a password, and the scenario is an
	 * absence claim about storage rather than something a browser can see. The
	 * check is structural: neither the entry value object nor the snapshot has a
	 * property that could carry one, so there is nowhere for a credential to be
	 * kept even by accident.
	 *
	 * @return void
	 */
	public function testNoCredentialIsPersistedForASynchronisedAccount(): void {
		$snapshot = $this->source()->read(
			connection: $this->connection(
				fixture: [
					'users' => [
						[
							'userName' => 'anja',
							'groups' => ['OU=Vergunningen'],
							// A directory that volunteers a credential must not
							// get one stored: nothing reads these keys.
							'password' => 'hunter2',
							'secret' => 'nope',
						],
					],
				]
			)
		);

		$entry = $snapshot->getEntries()[0];
		$serialised = json_encode($entry->jsonSerialize());

		$this->assertStringNotContainsString('hunter2', (string)$serialised);
		$this->assertStringNotContainsString('nope', (string)$serialised);

		$properties = [];
		foreach ((new ReflectionClass(DirectoryEntry::class))->getProperties() as $property) {
			$properties[] = strtolower($property->getName());
		}

		foreach (['password', 'secret', 'credential', 'token', 'apikey'] as $forbidden) {
			$this->assertNotContains($forbidden, $properties);
		}

	}//end testNoCredentialIsPersistedForASynchronisedAccount()

	/**
	 * Read a SCIM directory whose pages answer as scripted, recording the
	 * snapshot, the queries sent and the warnings logged.
	 *
	 * @param array<int, mixed>   $pages         One answer per call: a call-log object, or 'throw'.
	 * @param array<string,mixed> $configuration The connection configuration.
	 *
	 * @return array<string,mixed> The users read, completeness, failures, queries and warnings.
	 */
	private function readScimPages(array $pages, array $configuration): array {
		$queries = [];
		$calls = $this->getMockBuilder(CallService::class)->disableOriginalConstructor()->onlyMethods(['call'])->getMock();
		$calls->method('call')->willReturnCallback(
			function (ObjectEntity $source, string $endpoint = '', string $method = 'GET', array $config = []) use (&$queries, &$pages): ObjectEntity {
				$queries[] = [$endpoint, $method, $config];
				$answer = array_shift($pages);
				if ($answer === 'throw' || $answer === null) {
					throw new \RuntimeException('directory down');
				}

				$log = new ObjectEntity();
				$log->setObject($answer);
				return $log;
			}
		);
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$warnings): void {
				unset($context['exception']);
				$warnings[] = [$message, $context];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$source = new DirectorySource(orObjectService: $this->createMock(OrObjectService::class), callService: $calls, l10n: $l10n, logger: $logger);

		$connection = new ObjectEntity();
		$connection->setObject(['type' => 'directory', 'configuration' => $configuration]);
		$snapshot = $source->read(connection: $connection);

		return [
			'users' => array_map(static fn ($entry): string => $entry->getUserId(), $snapshot->getEntries()),
			'complete' => $snapshot->isComplete(),
			'failures' => count($snapshot->getFailures()),
			'queries' => $queries,
			'warnings' => $warnings,
		];
	}//end readScimPages()

	/**
	 * A SCIM read pages until the total, stops on an empty page, and is
	 * incomplete when a page fails, answers non-2xx or an unreadable body.
	 *
	 * @return void
	 */
	public function testAScimReadPagesAndReportsCompleteness(): void {
		$page = static fn (array $users, int $total, mixed $body = null): array => [
			'response' => ['statusCode' => 200, 'body' => ($body ?? json_encode(['totalResults' => $total, 'Resources' => array_map(static fn (string $u): array => ['userName' => $u], $users)]))],
		];

		$this->assertSame(
			[
				'users' => ['anja', 'bram', 'cees'],
				'complete' => true,
				'failures' => 0,
				'queries' => [
					['/scim/Users', 'GET', ['query' => ['startIndex' => 1, 'count' => 2]]],
					['/scim/Users', 'GET', ['query' => ['startIndex' => 3, 'count' => 2]]],
				],
				'warnings' => [],
			],
			$this->readScimPages(pages: [$page(['anja', 'bram'], 3), $page(['cees'], 3)], configuration: ['usersEndpoint' => '/scim/Users', 'pageSize' => 2])
		);

		$this->assertSame(
			['users' => ['anja'], 'complete' => true, 'failures' => 0, 'queries' => [['/Users', 'GET', ['query' => ['startIndex' => 1, 'count' => 200]]], ['/Users', 'GET', ['query' => ['startIndex' => 2, 'count' => 200]]]], 'warnings' => []],
			$this->readScimPages(pages: [$page(['anja'], 9), $page([], 9)], configuration: ['pageSize' => 0])
		);

		$arrayBody = ['response' => ['statusCode' => 201, 'body' => ['Resources' => [['userName' => 'dana']]]]];
		$this->assertSame(['dana'], $this->readScimPages(pages: [$arrayBody], configuration: [])['users']);

		$failed = $this->readScimPages(pages: [$page(['anja'], 5), 'throw'], configuration: []);
		$this->assertSame([['anja'], false], [$failed['users'], $failed['complete']]);
		$this->assertSame([['[DirectorySource] directory read failed: directory down', []]], $failed['warnings']);

		$status = $this->readScimPages(pages: [['response' => ['statusCode' => 503]]], configuration: []);
		$this->assertSame([[], false, [['[DirectorySource] directory answered status 503', ['startIndex' => 1]]]], [$status['users'], $status['complete'], $status['warnings']]);

		$this->assertSame([[], false, []], array_values(array_intersect_key($this->readScimPages(pages: [$page([], 0, 'not json')], configuration: []), ['users' => 1, 'complete' => 1, 'warnings' => 1])));
		$this->assertSame(false, $this->readScimPages(pages: [['response' => ['statusCode' => 200, 'body' => 42]]], configuration: [])['complete']);
	}//end testAScimReadPagesAndReportsCompleteness()

	/**
	 * A source over an object service that answers the given matches and records how it was asked.
	 *
	 * @param mixed              $matches The findAll answer.
	 * @param array<int, mixed>  $calls   Receives the findAll arguments.
	 *
	 * @return DirectorySource The source.
	 */
	private function sourceFinding(mixed $matches, array &$calls): DirectorySource {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (...$arguments) use ($matches, &$calls): array {
				$calls[] = $arguments;
				return $matches;
			}
		);
		$l10n = $this->createMock(IL10N::class);

		return new DirectorySource(
			orObjectService: $objects,
			callService: $this->createMock(CallService::class),
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end sourceFinding()

	/**
	 * A Source row of the given type.
	 *
	 * @param string $uuid The uuid.
	 * @param string $type The source type.
	 *
	 * @return ObjectEntity The row.
	 */
	private function sourceRow(string $uuid, string $type): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setObject(['type' => $type]);

		return $row;
	}//end sourceRow()

	/**
	 * Connections are looked up as directory Sources, and only directory
	 * entities are kept even when the register ignores the type filter.
	 *
	 * @return void
	 */
	public function testFindConnectionsKeepsOnlyDirectorySources(): void {
		$calls = [];
		$matches = ['results' => [$this->sourceRow('d1', 'directory'), $this->sourceRow('r1', 'rest'), ['type' => 'directory'], $this->sourceRow('d2', 'directory')]];

		$found = $this->sourceFinding($matches, $calls)->findConnections();

		$this->assertSame(['d1', 'd2'], array_map(static fn (ObjectEntity $row): ?string => $row->getUuid(), $found));
		$this->assertCount(1, $calls);
		$this->assertSame(['register' => 'integriq', 'schema' => 'source', 'type' => 'directory'], $calls[0][0]['filters']);
		$this->assertSame(100, $calls[0][0]['limit']);
		$this->assertSame([true, true], [$calls[0][1], $calls[0][2]], 'the user lookup keeps the access checks');
	}//end testFindConnectionsKeepsOnlyDirectorySources()

	/**
	 * The policy lookup reads every directory Source without the user's access
	 * checks, and accepts a plain list as well as a results envelope.
	 *
	 * @return void
	 */
	public function testFindConnectionsForPolicyReadsWithoutAccessChecks(): void {
		$calls = [];

		$found = $this->sourceFinding([$this->sourceRow('d1', 'directory'), $this->sourceRow('x', '')], $calls)->findConnectionsForPolicy();

		$this->assertSame(['d1'], array_map(static fn (ObjectEntity $row): ?string => $row->getUuid(), $found));
		$this->assertSame(['register' => 'integriq', 'schema' => 'source', 'type' => 'directory'], $calls[0][0]['filters']);
		$this->assertSame([false, false], [$calls[0][1], $calls[0][2]]);
	}//end testFindConnectionsForPolicyReadsWithoutAccessChecks()

	/**
	 * A directory that keeps answering stops at the page cap, and that read is incomplete.
	 *
	 * @return void
	 */
	public function testAReadThatHitsThePageCapIsIncomplete(): void {
		$pages = [];
		for ($n = 0; $n < 501; $n++) {
			$pages[] = ['response' => ['statusCode' => 200, 'body' => json_encode(['totalResults' => 100000, 'Resources' => [['userName' => 'user'.$n]]])]];
		}

		$read = $this->readScimPages(pages: $pages, configuration: ['pageSize' => 1]);

		$this->assertFalse($read['complete']);
		$this->assertCount(500, $read['queries']);
		$this->assertSame(['startIndex' => 500, 'count' => 1], $read['queries'][499][2]['query']);
	}//end testAReadThatHitsThePageCapIsIncomplete()
}//end class
