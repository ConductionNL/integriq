<?php

/**
 * Tests for AuthenticationConfigAuditor (ocon#232).
 *
 * THE TWO LOAD-BEARING TESTS IN THIS FILE:
 *
 *  1. {@see testTheAuditNeverEmitsAValue()} — the audit exists so an operator can see
 *     WHAT KIND OF THING sits in `authenticationConfig` before deleting it. If it
 *     printed the values, the audit tool would itself become a secret-disclosure
 *     vector. This test walks the ENTIRE report (and every log line) hunting for the
 *     literal secrets, so it fails if a value leaks anywhere, at any depth.
 *
 *  2. {@see testTheAuditReadsRawAndNotThroughTheRenderBoundary()} — `authenticationConfig`
 *     is `writeOnly: true`, so a RENDERED read returns nothing and the audit would
 *     report every source as "clear", greenlighting the removal of live credentials.
 *     {@see MigrationSimulatingObjectService} reproduces OpenRegister's render
 *     boundary, so this is a real mutation guard: drop `_render: false` from
 *     InlineSecretMigrationPlanner::readRawSource() and this test fails.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\AuthenticationConfigAuditor;
use OCA\Integriq\Service\Security\InlineSecretMigrationPlanner;
use OCA\Integriq\Tests\Helpers\MigrationSimulatingObjectService;
use OCA\Integriq\Tests\Helpers\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\Security\AuthenticationConfigAuditor
 */
class AuthenticationConfigAuditorTest extends TestCase {

	/**
	 * A recognisable secret that must never appear in the report or a log.
	 *
	 * @var string
	 */
	private const SECRET = 'super-secret-client-secret-DO-NOT-LEAK';

	/**
	 * A second recognisable secret, nested deeper in the bag.
	 *
	 * @var string
	 */
	private const NESTED_SECRET = 'nested-private-key-DO-NOT-LEAK';

	/**
	 * The object-service double reproducing the render boundary.
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
	 * The auditor under test.
	 *
	 * @var AuthenticationConfigAuditor
	 */
	private AuthenticationConfigAuditor $auditor;

	/**
	 * Build the auditor over a real planner over the render-boundary double.
	 *
	 * The planner is REAL, not mocked: the raw-read contract is precisely what is
	 * under test, and a mocked planner would assert nothing about it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = new MigrationSimulatingObjectService();
		$this->logger = new RecordingLogger();
		$planner = new InlineSecretMigrationPlanner($this->objectService, $this->logger);
		$this->auditor = new AuthenticationConfigAuditor($planner);
	}//end setUp()

	/**
	 * Seed a source holding a rich authenticationConfig bag.
	 *
	 * @param string $uuid The uuid.
	 * @param string $name The name.
	 *
	 * @return void
	 */
	private function seedSourceHoldingAValue(string $uuid, string $name): void {
		$this->objectService->seed(
			uuid: $uuid,
			object: [
				'name' => $name,
				'authenticationConfig' => [
					'client_id' => 'public-client-id',
					'client_secret' => self::SECRET,
					'algorithm' => 'HS256',
					'expires_in' => 3600,
					'enabled' => true,
					'scopes' => ['a', 'b'],
					'keypair' => ['private' => self::NESTED_SECRET],
				],
				'configuration' => ['headers' => ['Accept' => 'application/json']],
			],
			owner: 'admin',
			organisation: 'org-1'
		);
	}//end seedSourceHoldingAValue()

	/**
	 * THE SECRET-DISCLOSURE GUARD.
	 *
	 * The report must carry key NAMES, shapes and fingerprints — never a value. This
	 * flattens the whole report (and every log line) to JSON and asserts no secret
	 * appears at any depth, so it fails if a value leaks through ANY field, present
	 * or future.
	 *
	 * @return void
	 */
	public function testTheAuditNeverEmitsAValue(): void {
		$this->seedSourceHoldingAValue(uuid: 'src-1', name: 'OAuth Source');

		$report = $this->auditor->auditAll(limit: 100);

		$flatReport = (string)json_encode($report);
		$this->assertStringNotContainsString(
			self::SECRET,
			$flatReport,
			'THE AUDIT LEAKED A SECRET VALUE. It must report key NAMES only (array_keys()) — an audit tool '
			. 'that prints credentials is worse than no audit tool.'
		);
		$this->assertStringNotContainsString(
			self::NESTED_SECRET,
			$flatReport,
			'The audit leaked a NESTED secret value from inside the authenticationConfig bag.'
		);

		$flatLogs = implode("\n", $this->logger->lines);
		$this->assertStringNotContainsString(self::SECRET, $flatLogs, 'A secret reached the log.');
		$this->assertStringNotContainsString(self::NESTED_SECRET, $flatLogs, 'A nested secret reached the log.');
	}//end testTheAuditNeverEmitsAValue()

	/**
	 * The audit reports the key NAMES, so an operator can see what is there.
	 *
	 * The complement of the leak test: proving absence of values is only meaningful
	 * alongside proof that the report is actually USEFUL.
	 *
	 * @return void
	 */
	public function testTheAuditReportsKeyNames(): void {
		$this->seedSourceHoldingAValue(uuid: 'src-1', name: 'OAuth Source');

		$report = $this->auditor->auditAll(limit: 100);
		$source = $report['sources'][0];

		$this->assertSame(AuthenticationConfigAuditor::STATE_HOLDS_VALUE, $source['state']);
		$this->assertSame('src-1', $source['uuid']);
		$this->assertSame('OAuth Source', $source['name']);
		$this->assertSame(
			['client_id', 'client_secret', 'algorithm', 'expires_in', 'enabled', 'scopes', 'keypair'],
			$source['keys'],
			'The audit must report every key name in the bag.'
		);
		$this->assertSame(1, $report['holdValue']);
		$this->assertFalse($report['schemaPropertyRemovable'], 'A source still holds a value.');
	}//end testTheAuditReportsKeyNames()

	/**
	 * The shape hint describes the value's TYPE and SIZE without disclosing it, and
	 * the fingerprint is a truncated one-way digest.
	 *
	 * @return void
	 */
	public function testTheAuditReportsShapesAndNonReversibleFingerprints(): void {
		$this->seedSourceHoldingAValue(uuid: 'src-1', name: 'OAuth Source');

		$shapes = $this->auditor->auditAll(limit: 100)['sources'][0]['shapes'];

		$this->assertSame(sprintf('string(%d)', strlen(self::SECRET)), $shapes['client_secret']['shape']);
		$this->assertSame('integer', $shapes['expires_in']['shape']);
		$this->assertSame('boolean', $shapes['enabled']['shape']);
		$this->assertSame('array(2 items)', $shapes['scopes']['shape']);
		$this->assertSame('object(1 keys)', $shapes['keypair']['shape']);

		// The fingerprint is the first 4 bytes of sha256 — reproducible, truncated,
		// and one-way. It correlates values across sources; it cannot recover one.
		$this->assertSame(
			substr(hash('sha256', self::SECRET), 0, 8),
			$shapes['client_secret']['fingerprint']
		);
		$this->assertSame(8, strlen((string)$shapes['client_secret']['fingerprint']));

		// A boolean's fingerprint is withheld: with two possible inputs a digest is
		// a perfect oracle, and the shape already says everything.
		$this->assertNull($shapes['enabled']['fingerprint']);
	}//end testTheAuditReportsShapesAndNonReversibleFingerprints()

	/**
	 * THE RENDER-BOUNDARY TRAP.
	 *
	 * `authenticationConfig` is `writeOnly`, so a rendered read strips it and the
	 * audit would report "clear" for a source that is holding a live credential —
	 * which would then greenlight dropping the schema property. The double
	 * reproduces that strip, so the assertion on `_render: false` is a genuine
	 * mutation guard, not a restatement of a stub's willReturn().
	 *
	 * @return void
	 */
	public function testTheAuditReadsRawAndNotThroughTheRenderBoundary(): void {
		$this->seedSourceHoldingAValue(uuid: 'src-1', name: 'OAuth Source');

		$report = $this->auditor->auditAll(limit: 100);

		$this->assertSame(
			AuthenticationConfigAuditor::STATE_HOLDS_VALUE,
			$report['sources'][0]['state'],
			'The audit reported a source holding a live authenticationConfig as clear. It must read with '
			. '`_render: false`; a rendered read strips writeOnly fields (openregister#389/#429) and the '
			. 'audit would greenlight deleting credentials it never saw.'
		);

		// The CONTRACT: the per-source read passed _render: false.
		$perSourceReads = array_values(
			array_filter($this->objectService->reads, static fn (array $r): bool => $r['uuid'] === 'src-1')
		);
		$this->assertNotSame([], $perSourceReads, 'The auditor never read the source raw.');
		foreach ($perSourceReads as $read) {
			$this->assertFalse($read['_render'], 'The audit MUST read with `_render: false`.');
			$this->assertFalse($read['_rbac'], 'The audit reads in system context.');
		}
	}//end testTheAuditReadsRawAndNotThroughTheRenderBoundary()

	/**
	 * An absent / null / empty authenticationConfig is `clear` — nothing to remove.
	 *
	 * `[]` is the shape the shipped `configurations/*.json` sources actually carry.
	 *
	 * @return void
	 */
	public function testAnEmptyOrAbsentAuthenticationConfigIsClear(): void {
		$this->objectService->seed(uuid: 'src-absent', object: ['name' => 'No field'], owner: null, organisation: null);
		$this->objectService->seed(
			uuid: 'src-empty',
			object: ['name' => 'Empty array', 'authenticationConfig' => []],
			owner: null,
			organisation: null
		);
		$this->objectService->seed(
			uuid: 'src-null',
			object: ['name' => 'Null', 'authenticationConfig' => null],
			owner: null,
			organisation: null
		);

		$report = $this->auditor->auditAll(limit: 100);

		foreach ($report['sources'] as $source) {
			$this->assertSame(AuthenticationConfigAuditor::STATE_CLEAR, $source['state']);
			$this->assertSame([], $source['keys']);
		}

		$this->assertSame(3, $report['clear']);
		$this->assertSame(0, $report['holdValue']);
		$this->assertTrue(
			$report['schemaPropertyRemovable'],
			'A wholly clear, unreferenced fleet may drop the schema property.'
		);
	}//end testAnEmptyOrAbsentAuthenticationConfigIsClear()

	/**
	 * THE LIVE PATH THE "vestigial" FRAMING MISSES.
	 *
	 * No PHP code reads `authenticationConfig`, but CallService::renderValue() renders
	 * every `configuration` value as Twig with the RAW source as context (`_render: false`
	 * since ocon#215). So `{{ source.authenticationConfig.client_secret }}` in an
	 * operator's header DOES resolve to a live secret. Such a source must be flagged,
	 * because deleting its value would break its outbound authentication.
	 *
	 * @return void
	 */
	public function testASourceWhoseTwigTemplateReferencesTheFieldIsFlagged(): void {
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

		$report = $this->auditor->auditAll(limit: 100);
		$source = $report['sources'][0];

		$this->assertTrue(
			$source['referenced'],
			'A Twig template referencing source.authenticationConfig makes the field LIVE for that source. '
			. 'It must be flagged, or the removal would break its outbound auth.'
		);
		$this->assertSame(['headers.Authorization'], $source['references'], 'Report WHERE the reference is.');
		$this->assertSame(1, $report['referenced']);
		$this->assertFalse(
			$report['schemaPropertyRemovable'],
			'A referenced field must never be reported as safely removable.'
		);

		// The reference PATH is reported, never the template body — which could itself
		// embed a literal secret.
		$this->assertStringNotContainsString(self::SECRET, (string)json_encode($report));
	}//end testASourceWhoseTwigTemplateReferencesTheFieldIsFlagged()

	/**
	 * The reference scan covers subscript and attribute() access too, and looks at
	 * nested configuration values — not just top-level strings.
	 *
	 * @return void
	 */
	public function testTheReferenceScanCoversEveryTwigAccessForm(): void {
		$forms = [
			'dot' => '{{ source.authenticationConfig.secret }}',
			'subscript' => "{{ source['authenticationConfig']['secret'] }}",
			'attribute' => "{{ attribute(source, 'authenticationConfig') }}",
		];

		foreach ($forms as $label => $template) {
			$objectService = new MigrationSimulatingObjectService();
			$objectService->seed(
				uuid: 'src-' . $label,
				object: [
					'name' => $label,
					'authenticationConfig' => ['secret' => self::SECRET],
					// Deliberately nested: the scan must recurse, not just read top level.
					'configuration' => ['deep' => ['nested' => ['tpl' => $template]]],
				],
				owner: null,
				organisation: null
			);

			$auditor = new AuthenticationConfigAuditor(
				new InlineSecretMigrationPlanner($objectService, new RecordingLogger())
			);

			$source = $auditor->auditAll(limit: 10)['sources'][0];
			$this->assertTrue($source['referenced'], sprintf('The `%s` access form must be detected.', $label));
			$this->assertSame(['deep.nested.tpl'], $source['references']);
		}
	}//end testTheReferenceScanCoversEveryTwigAccessForm()

	/**
	 * A source that merely mentions `configuration.authentication` is NOT referenced —
	 * that is the canonical modern path and must not be flagged.
	 *
	 * Guards against an over-broad pattern flagging the whole fleet, which would make
	 * the removal permanently unreachable.
	 *
	 * @return void
	 */
	public function testTheCanonicalAuthenticationPathIsNotFlagged(): void {
		$this->objectService->seed(
			uuid: 'src-modern',
			object: [
				'name' => 'Modern source',
				'authenticationConfig' => ['client_secret' => self::SECRET],
				'configuration' => [
					'headers' => ['Authorization' => 'Bearer {{ oauthToken(source) }}'],
					'authentication' => ['client_secret' => 'x'],
				],
			],
			owner: null,
			organisation: null
		);

		$source = $this->auditor->auditAll(limit: 100)['sources'][0];

		$this->assertFalse(
			$source['referenced'],
			'`oauthToken(source)` reads configuration.authentication, NOT authenticationConfig. '
			. 'Flagging it would block the removal for the entire fleet.'
		);
	}//end testTheCanonicalAuthenticationPathIsNotFlagged()

	/**
	 * An unreadable source is `unreadable`, never `clear` — fail closed.
	 *
	 * If it counted as clear, one unreadable source could let the fleet look clean and
	 * greenlight dropping the schema property while it still held a credential.
	 *
	 * @return void
	 */
	public function testAnUnreadableSourceIsNeverReportedAsClear(): void {
		// Listed by findAll() but with no raw body to read back.
		$this->objectService->stored['ghost'] = ['object' => [], 'owner' => null, 'organisation' => null];

		$report = $this->auditor->auditAll(limit: 100);
		$source = $report['sources'][0];

		$this->assertSame(AuthenticationConfigAuditor::STATE_UNREADABLE, $source['state']);
		$this->assertSame(0, $report['clear'], 'An unreadable source must NOT be counted clear.');
		$this->assertSame(1, $report['unreadable']);
		$this->assertFalse(
			$report['schemaPropertyRemovable'],
			'Unknown state must fail closed — never greenlight a schema drop.'
		);
	}//end testAnUnreadableSourceIsNeverReportedAsClear()

	/**
	 * One unreadable source must not abort the batch (per-source isolation).
	 *
	 * @return void
	 */
	public function testOneUnreadableSourceDoesNotAbortTheAudit(): void {
		$this->objectService->stored['ghost'] = ['object' => [], 'owner' => null, 'organisation' => null];
		$this->seedSourceHoldingAValue(uuid: 'src-1', name: 'OAuth Source');

		$report = $this->auditor->auditAll(limit: 100);

		$this->assertSame(2, $report['totalSources']);
		$this->assertSame(1, $report['unreadable']);
		$this->assertSame(1, $report['holdValue'], 'The healthy source must still be audited.');
	}//end testOneUnreadableSourceDoesNotAbortTheAudit()
}//end class
