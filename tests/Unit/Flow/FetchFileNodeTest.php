<?php

/**
 * Unit tests for `openconnector.fetch-file`.
 *
 * The node is a thin adapter over `SynchronizationService::runFetchFileRule()`,
 * so what is worth testing is not the fetch — the service owns that and has its
 * own tests — but the decisions the STEP makes around it: which records it acts
 * on, which it deliberately skips, and what a failure does to the rest of the
 * page.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Flow
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Flow;

use OCA\OpenConnector\Flow\FetchFileNode;
use OCA\OpenConnector\Flow\FlowNodeSupport;
use OCA\OpenConnector\Flow\FlowOwner;
use OCA\OpenConnector\Service\SynchronizationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tests for the fetch-file step.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
class FetchFileNodeTest extends TestCase {

	/**
	 * The engine double that owns the actual fetch.
	 *
	 * @var SynchronizationService&MockObject
	 */
	private $synchronizations;

	/**
	 * The user manager, so the step can resolve its run owner.
	 *
	 * @var IUserManager&MockObject
	 */
	private $userManager;

	/**
	 * The node under test.
	 *
	 * @var FetchFileNode
	 */
	private FetchFileNode $node;

	/**
	 * Build the node over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->synchronizations = $this->createMock(SynchronizationService::class);
		$this->userManager = $this->createMock(IUserManager::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				if (is_array($parameters) === false || $parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/openconnector/img/icon.svg');

		$this->node = new FetchFileNode(
			synchronizations: $this->synchronizations,
			flowOwner: new FlowOwner(
				userManager: $this->userManager,
				userSession: $this->createMock(IUserSession::class),
				l10n: $l10n
			),
			l10n: $l10n,
			urlGenerator: $urlGenerator,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * The run context every execute() call is given.
	 *
	 * @return array The context.
	 */
	private function context(): array {
		return ['triggeredBy' => 'alice', 'stepId' => 'step-files'];

	}//end context()

	/**
	 * Make the user manager resolve the run owner.
	 *
	 * @return void
	 */
	private function givenOwner(): void {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->willReturn($owner);

	}//end givenOwner()

	/**
	 * One item carrying a written object id.
	 *
	 * @param array $json The record.
	 *
	 * @return array The item.
	 */
	private function item(array $json): array {
		return ['json' => $json, 'binary' => []];

	}//end item()

	/**
	 * The config vocabulary is pinned, so a drift fails here.
	 *
	 * @return void
	 */
	public function testTheConfigVocabularyIsPinned(): void {
		$this->assertSame(
			['rule', 'objectIdPath', 'synchronization', 'onError'],
			$this->node->configKeys()
		);

		// Every field the form offers must be a key the node reads, or the
		// dialog writes something nothing consumes.
		foreach ($this->node->configForm() as $field) {
			$this->assertContains($field['key'], $this->node->configKeys());
		}

	}//end testTheConfigVocabularyIsPinned()

	/**
	 * A step with no rule is refused before it runs.
	 *
	 * @return void
	 */
	public function testAStepWithNoRuleIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node->validateConfig(config: ['objectIdPath' => 'syncedId']);

	}//end testAStepWithNoRuleIsRefused()

	/**
	 * The rule runs once per record, against the written object's id.
	 *
	 * @return void
	 */
	public function testTheRuleRunsOncePerRecordWithTheWrittenObjectId(): void {
		$this->givenOwner();

		$seen = [];
		$this->synchronizations->method('runFetchFileRule')->willReturnCallback(
			static function (string $ruleId, array $data, ?string $objectId) use (&$seen): array {
				$seen[] = [$ruleId, $objectId];
				$data['files'] = 'placeholder';
				return $data;
			}
		);

		$items = $this->node->execute(
			items: [
				$this->item(['syncedId' => 'obj-1']),
				$this->item(['syncedId' => 'obj-2']),
			],
			config: ['rule' => 'grab-docs'],
			context: $this->context()
		);

		$this->assertSame([['grab-docs', 'obj-1'], ['grab-docs', 'obj-2']], $seen);
		// The service's RETURN is kept — it carries the placeholder values the
		// legacy engine writes back onto the record. Dropping it would make a
		// migrated synchronization produce a different record than the one it
		// replaced.
		$this->assertSame('placeholder', $items[0]['json']['files']);
		$this->assertCount(2, $items);

	}//end testTheRuleRunsOncePerRecordWithTheWrittenObjectId()

	/**
	 * A record with NO written object is skipped, not fetched.
	 *
	 * This happens legitimately on every re-run: an item the contract step
	 * decided is unchanged never reaches `object-write`, so it has no id. An
	 * `after` rule attaches files to an object; with nothing to attach them to
	 * the service would fetch into the void and report success.
	 *
	 * @return void
	 */
	public function testARecordWithNoWrittenObjectIsSkipped(): void {
		$this->givenOwner();

		$this->synchronizations->expects($this->never())->method('runFetchFileRule');

		$items = $this->node->execute(
			items: [$this->item(['syncedId' => '']), $this->item(['other' => 'thing'])],
			config: ['rule' => 'grab-docs'],
			context: $this->context()
		);

		// Skipped, but still PASSED ON. An item dropped here would vanish from
		// the page before `contract-commit` and `contract-sweep` see it, and
		// the sweep would then treat its object as stale.
		$this->assertCount(2, $items);

	}//end testARecordWithNoWrittenObjectIsSkipped()

	/**
	 * The object id path is configurable, and defaults to what the write sets.
	 *
	 * @return void
	 */
	public function testTheObjectIdPathIsConfigurable(): void {
		$this->givenOwner();

		$seen = [];
		$this->synchronizations->method('runFetchFileRule')->willReturnCallback(
			static function (string $ruleId, array $data, ?string $objectId) use (&$seen): array {
				$seen[] = $objectId;
				return $data;
			}
		);

		$this->node->execute(
			items: [$this->item(['written' => ['uuid' => 'obj-9']])],
			config: ['rule' => 'grab-docs', 'objectIdPath' => 'written.uuid'],
			context: $this->context()
		);

		$this->assertSame(['obj-9'], $seen);

	}//end testTheObjectIdPathIsConfigurable()

	/**
	 * Under `continue`, one failed record does not lose the rest of the page.
	 *
	 * @return void
	 */
	public function testAFailedRecordCarriesErrorStateAndThePageGoesOn(): void {
		$this->givenOwner();

		$this->synchronizations->method('runFetchFileRule')->willReturnCallback(
			static function (string $ruleId, array $data, ?string $objectId): array {
				if ($objectId === 'obj-1') {
					throw new RuntimeException('the source refused');
				}

				return $data;
			}
		);

		$items = $this->node->execute(
			items: [$this->item(['syncedId' => 'obj-1']), $this->item(['syncedId' => 'obj-2'])],
			config: ['rule' => 'grab-docs', 'onError' => 'continue'],
			context: $this->context()
		);

		$this->assertCount(2, $items);
		$error = $items[0]['json'][FlowNodeSupport::ERROR_KEY];
		$this->assertSame('fetch-file', $error['kind']);
		$this->assertSame('grab-docs', $error['rule']);
		$this->assertStringContainsString('the source refused', $error['message']);
		// The healthy record is untouched — no error state leaks across items.
		$this->assertArrayNotHasKey(FlowNodeSupport::ERROR_KEY, $items[1]['json']);

	}//end testAFailedRecordCarriesErrorStateAndThePageGoesOn()

	/**
	 * Under `stop`, a failure ends the run.
	 *
	 * The negative control for the test above: if `onError` were ignored,
	 * every failure would look like `continue` and a synchronization asking to
	 * stop would quietly keep going.
	 *
	 * @return void
	 */
	public function testUnderStopAFailureEndsTheRun(): void {
		$this->givenOwner();

		$this->synchronizations->method('runFetchFileRule')->willThrowException(
			new RuntimeException('the source refused')
		);

		$this->expectExceptionMessageMatches('/the source refused/');

		$this->node->execute(
			items: [$this->item(['syncedId' => 'obj-1'])],
			config: ['rule' => 'grab-docs', 'onError' => 'stop'],
			context: $this->context()
		);

	}//end testUnderStopAFailureEndsTheRun()

	/**
	 * An empty page produces no items and touches nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyPageFetchesNothing(): void {
		$this->synchronizations->expects($this->never())->method('runFetchFileRule');

		$this->assertSame(
			[],
			$this->node->execute(items: [], config: ['rule' => 'grab-docs'], context: $this->context())
		);

	}//end testAnEmptyPageFetchesNothing()
}//end class
