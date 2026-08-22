<?php

/**
 * The run-log link the synchronization-shaped nodes earn.
 *
 * Four nodes share one trait, so what is under test is the trait's contract:
 * find the reference wherever the step's configured output key happened to put
 * it, build a link to a page that EXISTS, and stay silent when there is nothing
 * to point at.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Flow
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

namespace OCA\Integriq\Tests\Unit\Flow;

use OCA\Integriq\Flow\SynchronizationLogActions;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The trait's behaviour, over a host that has only what it needs.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
class SynchronizationLogActionsTest extends TestCase {

	/**
	 * The subject: the trait on a minimal host.
	 *
	 * @var object
	 */
	private object $node;

	/**
	 * Build the host with translations and a URL generator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/index.php/apps/openconnector/');

		$this->node = new class($l10n, $urls) {
			use SynchronizationLogActions;

			/**
			 * Constructor.
			 *
			 * @param IL10N         $l10n         Translations.
			 * @param IURLGenerator $urlGenerator The app root.
			 */
			public function __construct(
				private readonly IL10N $l10n,
				private readonly IURLGenerator $urlGenerator,
			) {

			}
		};

	}//end setUp()

	/**
	 * Wrap one item's json as a log entry.
	 *
	 * @param array  $json The item's json.
	 * @param string $side 'output' or 'input'.
	 *
	 * @return array The log entry.
	 */
	private function entryWith(array $json, string $side = 'output'): array {
		return [$side => ['items' => [['json' => $json]]]];

	}//end entryWith()

	/**
	 * The reference is found under the step's CONFIGURED output key.
	 *
	 * This is the whole reason the trait searches instead of addressing a
	 * path. `source-paginate` places its payload at whatever `output` names —
	 * `page` by default, but an author may call it anything — and a log entry
	 * carries no config to look it up with.
	 *
	 * @return void
	 */
	public function testTheReferenceIsFoundUnderAnArbitraryOutputKey(): void {
		$actions = $this->node->logActions(
			entry: $this->entryWith(
				[
					'whateverTheAuthorCalledIt' => [
						'page' => 1,
						'synchronization' => 'sync-123',
					],
				]
			)
		);

		$this->assertCount(1, $actions);
		$this->assertSame('Open the synchronization', $actions[0]['label']);
		$this->assertSame(
			'/index.php/apps/openconnector#/synchronizations/sync-123',
			$actions[0]['href']
		);

	}//end testTheReferenceIsFoundUnderAnArbitraryOutputKey()

	/**
	 * The contract payload's spelling is understood too.
	 *
	 * `contract-commit` writes `synchronizationId`, not `synchronization`.
	 * Supporting one spelling would have left a quarter of the callers linkless
	 * while looking like it worked.
	 *
	 * @return void
	 */
	public function testTheContractSpellingIsUnderstood(): void {
		$actions = $this->node->logActions(
			entry: $this->entryWith(['contract' => ['synchronizationId' => 'sync-9']])
		);

		$this->assertSame(
			'/index.php/apps/openconnector#/synchronizations/sync-9',
			$actions[0]['href']
		);

	}//end testTheContractSpellingIsUnderstood()

	/**
	 * A step that failed before producing anything still links, from its input.
	 *
	 * The log entry an operator most wants a link from is the one that went
	 * wrong, and that is exactly the entry with no output.
	 *
	 * @return void
	 */
	public function testAFailedStepLinksFromWhatItReceived(): void {
		$actions = $this->node->logActions(
			entry: $this->entryWith(['page' => ['synchronization' => 'sync-in']], side: 'input')
		);

		$this->assertSame(
			'/index.php/apps/openconnector#/synchronizations/sync-in',
			$actions[0]['href']
		);

	}//end testAFailedStepLinksFromWhatItReceived()

	/**
	 * Nothing to point at yields NO link, not a link to the list page.
	 *
	 * The interface is explicit about this, and the reason is behavioural: a
	 * link that lands somewhere unrelated is followed once, and thereafter none
	 * of them are trusted.
	 *
	 * @return void
	 */
	public function testAnEntryWithNoReferenceEarnsNoLink(): void {
		$this->assertSame([], $this->node->logActions(entry: $this->entryWith(['page' => ['count' => 3]])));
		$this->assertSame([], $this->node->logActions(entry: []));
		// Present but empty is still nothing to point at.
		$this->assertSame([], $this->node->logActions(entry: $this->entryWith(['synchronization' => '   '])));

	}//end testAnEntryWithNoReferenceEarnsNoLink()

	/**
	 * A reference is ESCAPED into the fragment, never concatenated raw.
	 *
	 * `$entry` is the caller's own POST body, so the reference is attacker-
	 * controlled text. It is echoed rather than resolved — that is what keeps
	 * this free of the IDOR the interface warns about — but echoed text still
	 * has to be escaped where it lands.
	 *
	 * @return void
	 */
	public function testAHostileReferenceIsEscaped(): void {
		$actions = $this->node->logActions(
			entry: $this->entryWith(['page' => ['synchronization' => '../../evil?x=1&y=2']])
		);

		$this->assertSame(
			'/index.php/apps/openconnector#/synchronizations/..%2F..%2Fevil%3Fx%3D1%26y%3D2',
			$actions[0]['href']
		);

	}//end testAHostileReferenceIsEscaped()

	/**
	 * A deep or cyclic sample cannot turn one log render into a long walk.
	 *
	 * @return void
	 */
	public function testTheSearchIsBounded(): void {
		$deep = ['synchronization' => 'too-deep'];
		for ($i = 0; $i < 8; $i++) {
			$deep = ['nested' => $deep];
		}

		$this->assertSame(
			[],
			$this->node->logActions(entry: $this->entryWith($deep)),
			'Beyond the bound the reference is not found — deliberately, and cheaply.'
		);

	}//end testTheSearchIsBounded()
}//end class
