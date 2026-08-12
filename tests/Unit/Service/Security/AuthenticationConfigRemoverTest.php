<?php

/**
 * Tests for AuthenticationConfigRemover (ocon#232).
 *
 * This class DELETES credential data, so the tests here are mostly about what it
 * REFUSES to do:
 *
 *  - {@see testRemovalRefusesWithoutTheExplicitOptIn()} — the deletion gate.
 *  - {@see testASourceReferencedFromATwigTemplateIsRefused()} — never delete a value
 *    something can still resolve.
 *  - {@see testOneFailingSaveDoesNotAbortOrHalfWriteTheBatch()} — per-source isolation.
 *  - {@see testASecondRunIsANoOp()} — idempotency.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Security;

use LogicException;
use OCA\OpenConnector\Service\Security\AuthenticationConfigAuditor;
use OCA\OpenConnector\Service\Security\AuthenticationConfigRemover;
use OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner;
use OCA\OpenConnector\Tests\Helpers\MigrationSimulatingObjectService;
use OCA\OpenConnector\Tests\Helpers\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenConnector\Service\Security\AuthenticationConfigRemover
 */
class AuthenticationConfigRemoverTest extends TestCase {

	/**
	 * A recognisable secret that must never reach a log.
	 *
	 * @var string
	 */
	private const SECRET = 'super-secret-client-secret-DO-NOT-LEAK';

	/**
	 * The object-service double (render boundary + persisting saves).
	 *
	 * @var MigrationSimulatingObjectService
	 */
	private MigrationSimulatingObjectService $objectService;

	/**
	 * The spy logger.
	 *
	 * @var RecordingLogger
	 */
	private RecordingLogger $logger;

	/**
	 * The remover under test.
	 *
	 * @var AuthenticationConfigRemover
	 */
	private AuthenticationConfigRemover $remover;

	/**
	 * Build the remover over a real auditor over a real planner over the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = new MigrationSimulatingObjectService();
		$this->logger = new RecordingLogger();
		$auditor = new AuthenticationConfigAuditor(
			new InlineSecretMigrationPlanner($this->objectService, $this->logger)
		);
		$this->remover = new AuthenticationConfigRemover(
			$auditor,
			$this->objectService,
			$this->logger
		);
	}//end setUp()

	/**
	 * Seed a source holding an authenticationConfig plus the OTHER writeOnly secrets.
	 *
	 * The siblings matter: they prove the removal is surgical and that the raw read
	 * kept them alive through the PUT-semantic save.
	 *
	 * @param string $uuid The uuid.
	 * @param string $name The name.
	 *
	 * @return void
	 */
	private function seedHoldingSource(string $uuid, string $name): void {
		$this->objectService->seed(
			uuid: $uuid,
			object: [
				'name' => $name,
				'authenticationConfig' => ['client_id' => 'public', 'client_secret' => self::SECRET],
				'apikey' => 'sibling-apikey-must-survive',
				'jwt' => 'sibling-jwt-must-survive',
				'configuration' => ['headers' => ['Accept' => 'application/json']],
			],
			owner: 'admin',
			organisation: 'org-1'
		);
	}//end seedHoldingSource()

	/**
	 * THE GATE — the whole point of this change.
	 *
	 * `removeAll()` DELETES credential data. Without an explicit opt-in it must throw
	 * and write NOTHING. This is the mutation guard: make the removal fire without the
	 * opt-in (delete the `$optIn !== true` check) and this test fails.
	 *
	 * @return void
	 */
	public function testRemovalRefusesWithoutTheExplicitOptIn(): void {
		$this->seedHoldingSource(uuid: 'src-1', name: 'OAuth Source');

		$this->expectException(LogicException::class);

		try {
			// The default. A caller that "just calls removeAll()" must NOT delete.
			$this->remover->removeAll(limit: 100);
		} finally {
			$this->assertSame([], $this->objectService->saves, 'NOTHING may be written without the opt-in.');
			$this->assertSame(
				['client_id' => 'public', 'client_secret' => self::SECRET],
				$this->objectService->stored['src-1']['object']['authenticationConfig'],
				'The value must be untouched when the opt-in was not given.'
			);
		}
	}//end testRemovalRefusesWithoutTheExplicitOptIn()

	/**
	 * Passing the opt-in explicitly false is still a refusal.
	 *
	 * @return void
	 */
	public function testRemovalRefusesOnAnExplicitlyFalseOptIn(): void {
		$this->seedHoldingSource(uuid: 'src-1', name: 'OAuth Source');

		$this->expectException(LogicException::class);
		$this->remover->removeAll(limit: 100, optIn: false);
	}//end testRemovalRefusesOnAnExplicitlyFalseOptIn()

	/**
	 * With the opt-in, the value is cleared — and ONLY that value.
	 *
	 * The sibling writeOnly secrets must survive: the save is PUT-semantic, so a
	 * rendered read here would have merged them away (a data-loss bug worse than the
	 * one being fixed).
	 *
	 * @return void
	 */
	public function testWithTheOptInTheValueIsClearedAndSiblingSecretsSurvive(): void {
		$this->seedHoldingSource(uuid: 'src-1', name: 'OAuth Source');

		$result = $this->remover->removeAll(limit: 100, optIn: true);

		$this->assertSame(1, $result['removed']);
		$this->assertSame(AuthenticationConfigRemover::OUTCOME_REMOVED, $result['sources'][0]['outcome']);

		$stored = $this->objectService->stored['src-1']['object'];
		$this->assertNull($stored['authenticationConfig'], 'The field must be cleared (null, not omitted).');
		$this->assertSame('sibling-apikey-must-survive', $stored['apikey'], 'A sibling writeOnly secret was destroyed.');
		$this->assertSame('sibling-jwt-must-survive', $stored['jwt'], 'A sibling writeOnly secret was destroyed.');
		$this->assertSame(['headers' => ['Accept' => 'application/json']], $stored['configuration']);
	}//end testWithTheOptInTheValueIsClearedAndSiblingSecretsSurvive()

	/**
	 * The write happens in system context (`_rbac: false`, `_multitenancy: false`),
	 * mirroring InlineSecretMigrationExecutor's source save.
	 *
	 * @return void
	 */
	public function testTheSaveRunsInSystemContext(): void {
		$this->seedHoldingSource(uuid: 'src-1', name: 'OAuth Source');

		$this->remover->removeAll(limit: 100, optIn: true);

		$this->assertCount(1, $this->objectService->saves);
		$this->assertFalse($this->objectService->saves[0]['_rbac']);
		$this->assertFalse($this->objectService->saves[0]['_multitenancy']);
		$this->assertSame('src-1', $this->objectService->saves[0]['uuid']);
	}//end testTheSaveRunsInSystemContext()

	/**
	 * A source whose Twig template still references the field is REFUSED.
	 *
	 * CallService renders `configuration` against the RAW source, so
	 * `{{ source.authenticationConfig.client_secret }}` resolves to a live secret.
	 * Clearing it would break that source's outbound authentication.
	 *
	 * @return void
	 */
	public function testASourceReferencedFromATwigTemplateIsRefused(): void {
		$this->objectService->seed(
			uuid: 'src-twig',
			object: [
				'name' => 'Twig referencing source',
				'authenticationConfig' => ['client_secret' => self::SECRET],
				'configuration' => [
					'headers' => ['Authorization' => 'Bearer {{ source.authenticationConfig.client_secret }}'],
				],
			],
			owner: null,
			organisation: null
		);

		$result = $this->remover->removeAll(limit: 100, optIn: true);

		$this->assertSame(1, $result['blocked']);
		$this->assertSame(0, $result['removed']);
		$this->assertSame(AuthenticationConfigRemover::OUTCOME_BLOCKED, $result['sources'][0]['outcome']);
		$this->assertSame([], $this->objectService->saves, 'A referenced source must not be written at all.');
		$this->assertSame(
			['client_secret' => self::SECRET],
			$this->objectService->stored['src-twig']['object']['authenticationConfig'],
			'The value of a Twig-referenced source must survive intact.'
		);
	}//end testASourceReferencedFromATwigTemplateIsRefused()

	/**
	 * PER-SOURCE ISOLATION: one failing save must not abort the batch, and must not
	 * half-write the others.
	 *
	 * @return void
	 */
	public function testOneFailingSaveDoesNotAbortOrHalfWriteTheBatch(): void {
		$this->seedHoldingSource(uuid: 'src-ok-1', name: 'First');
		$this->seedHoldingSource(uuid: 'src-boom', name: 'Explodes');
		$this->seedHoldingSource(uuid: 'src-ok-2', name: 'Last');

		$this->objectService->failSaveForUuid = 'src-boom';

		$result = $this->remover->removeAll(limit: 100, optIn: true);

		$this->assertSame(2, $result['removed'], 'The healthy sources must still be cleared.');
		$this->assertSame(1, $result['failed']);

		// The failed source keeps its value — it is still the only copy.
		$this->assertSame(
			['client_id' => 'public', 'client_secret' => self::SECRET],
			$this->objectService->stored['src-boom']['object']['authenticationConfig'],
			'A failed save must leave the value intact.'
		);

		// The sources AFTER the failure were still processed (the batch did not abort).
		$this->assertNull($this->objectService->stored['src-ok-1']['object']['authenticationConfig']);
		$this->assertNull($this->objectService->stored['src-ok-2']['object']['authenticationConfig']);

		$outcomes = [];
		foreach ($result['sources'] as $source) {
			$outcomes[$source['uuid']] = $source['outcome'];
		}

		$this->assertSame(AuthenticationConfigRemover::OUTCOME_FAILED, $outcomes['src-boom']);
		$this->assertSame(AuthenticationConfigRemover::OUTCOME_REMOVED, $outcomes['src-ok-2']);
	}//end testOneFailingSaveDoesNotAbortOrHalfWriteTheBatch()

	/**
	 * IDEMPOTENCY: an already-clear source is skipped, and a second run writes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunIsANoOp(): void {
		$this->seedHoldingSource(uuid: 'src-1', name: 'OAuth Source');

		$first = $this->remover->removeAll(limit: 100, optIn: true);
		$this->assertSame(1, $first['removed']);
		$savesAfterFirst = count($this->objectService->saves);

		$second = $this->remover->removeAll(limit: 100, optIn: true);

		$this->assertSame(0, $second['removed'], 'The second run must remove nothing.');
		$this->assertSame(1, $second['skipped'], 'An already-clear source is skipped.');
		$this->assertSame(
			AuthenticationConfigRemover::OUTCOME_SKIPPED,
			$second['sources'][0]['outcome']
		);
		$this->assertCount(
			$savesAfterFirst,
			$this->objectService->saves,
			'A second run must not issue ANY additional save.'
		);
	}//end testASecondRunIsANoOp()

	/**
	 * An already-clear source is skipped without a write on the FIRST run too.
	 *
	 * @return void
	 */
	public function testAnAlreadyClearSourceIsSkippedWithoutAWrite(): void {
		$this->objectService->seed(
			uuid: 'src-clear',
			object: ['name' => 'Clear', 'authenticationConfig' => []],
			owner: null,
			organisation: null
		);

		$result = $this->remover->removeAll(limit: 100, optIn: true);

		$this->assertSame(1, $result['skipped']);
		$this->assertSame([], $this->objectService->saves, 'A clear source needs no write.');
	}//end testAnAlreadyClearSourceIsSkippedWithoutAWrite()

	/**
	 * NO SECRET IN ANY LOG — not on the happy path, not on the failure path.
	 *
	 * @return void
	 */
	public function testNoSecretEverReachesTheLog(): void {
		$this->seedHoldingSource(uuid: 'src-ok', name: 'Fine');
		$this->seedHoldingSource(uuid: 'src-boom', name: 'Explodes');
		$this->objectService->failSaveForUuid = 'src-boom';

		$result = $this->remover->removeAll(limit: 100, optIn: true);

		$flatLogs = implode("\n", $this->logger->lines);
		$this->assertStringNotContainsString(
			self::SECRET,
			$flatLogs,
			'A secret reached the log — including from the failure path, where an exception message is '
			. 'the classic leak vector.'
		);
		$this->assertStringNotContainsString(self::SECRET, (string)json_encode($result), 'The result leaked a secret.');
	}//end testNoSecretEverReachesTheLog()

	/**
	 * An unreadable source fails closed and is never counted as removed.
	 *
	 * @return void
	 */
	public function testAnUnreadableSourceFailsClosed(): void {
		$this->objectService->stored['ghost'] = ['object' => [], 'owner' => null, 'organisation' => null];

		$result = $this->remover->removeAll(limit: 100, optIn: true);

		$this->assertSame(1, $result['failed']);
		$this->assertSame(0, $result['removed']);
		$this->assertSame([], $this->objectService->saves, 'An unreadable source must never be blind-written.');
	}//end testAnUnreadableSourceFailsClosed()
}//end class
