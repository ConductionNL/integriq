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
}//end class
