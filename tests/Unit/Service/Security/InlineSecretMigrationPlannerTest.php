<?php

/**
 * Tests for InlineSecretMigrationPlanner.
 *
 * THE LOAD-BEARING TEST IN THIS FILE is {@see testRawReadSeesSecretsThroughTheRenderBoundary()}.
 *
 * These tests deliberately do NOT stub `find()` "regardless of arguments" —
 * that blind spot is exactly how ocon#215 shipped. Instead
 * {@see RenderBoundarySimulatingObjectService} REPRODUCES OpenRegister's render
 * boundary: it strips `writeOnly` properties on every read unless the caller
 * passed `_render: false`, exactly as
 * openregister lib/Service/Object/RenderObject.php::renderEntity() does at HEAD.
 *
 * That makes the CONTRACT (which read context the planner used) the thing under
 * test, not the double's willReturn(). It is also the mutation guard: delete
 * `_render: false` from InlineSecretMigrationPlanner::readRawSource() and
 * testRawReadSeesSecretsThroughTheRenderBoundary() fails, because the fake
 * strips the secret precisely as production would.
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

use OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner;
use OCA\OpenConnector\Tests\Helpers\RenderBoundarySimulatingObjectService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * A logger that records everything, so tests can assert no secret ever reaches it.
 */
class SpyLogger extends \Psr\Log\AbstractLogger {

	/**
	 * Every line and context this logger was handed, flattened to a string.
	 *
	 * @var array<int, string>
	 */
	public array $lines = [];

	/**
	 * Record a log call.
	 *
	 * Untyped $message to stay compatible with the PSR-3 version pinned here.
	 *
	 * @param mixed $level The log level.
	 * @param mixed $message The message.
	 * @param array $context The context.
	 *
	 * @return void
	 */
	public function log($level, $message, array $context = []): void {
		$this->lines[] = (string)$message . ' ' . json_encode($context);
	}//end log()
}//end class

/**
 * @covers \OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner
 */
class InlineSecretMigrationPlannerTest extends TestCase {

	/**
	 * A recognisable secret value that must never appear in a log.
	 *
	 * @var string
	 */
	private const SECRET = 'super-secret-live-apikey-DO-NOT-LEAK';

	/**
	 * The double under the planner.
	 *
	 * @var RenderBoundarySimulatingObjectService
	 */
	private RenderBoundarySimulatingObjectService $objectService;

	/**
	 * The spy logger.
	 *
	 * @var SpyLogger
	 */
	private SpyLogger $logger;

	/**
	 * The planner under test.
	 *
	 * @var InlineSecretMigrationPlanner
	 */
	private InlineSecretMigrationPlanner $planner;

	/**
	 * Set up the planner over the render-boundary-simulating double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = new RenderBoundarySimulatingObjectService();
		$this->logger = new SpyLogger();
		$this->planner = new InlineSecretMigrationPlanner(
			$this->objectService,
			$this->logger
		);
	}//end setUp()

	/**
	 * THE TRAP TEST — the single most important assertion in this suite.
	 *
	 * The planner's read MUST see a live inline secret. If it reads through the
	 * render path, the secret is already gone before it looks: the migration
	 * would report "nothing to migrate", and phase D would then drop the columns
	 * and destroy every credential in the fleet.
	 *
	 * This asserts the CONTRACT, not the stub: the double strips writeOnly on any
	 * rendered read, so this can only pass when the planner passed `_render: false`.
	 *
	 * @return void
	 */
	public function testRawReadSeesSecretsThroughTheRenderBoundary(): void {
		$this->objectService->stored['src-1'] = [
			'name' => 'Live API source',
			'apikey' => self::SECRET,
		];

		$raw = $this->planner->readRawSource(uuid: 'src-1');

		$this->assertArrayHasKey(
			'apikey',
			$raw,
			'The raw read lost the apikey — it went through the render boundary. '
			. 'A migration reading this way sees no secrets and reports a false "nothing to migrate".'
		);
		$this->assertSame(self::SECRET, $raw['apikey'], 'The raw read must return the secret byte-for-byte.');
	}//end testRawReadSeesSecretsThroughTheRenderBoundary()

	/**
	 * The read must explicitly declare the raw context. Asserting the recorded
	 * arguments makes the contract itself the thing under test, so a future
	 * refactor that drops `_render: false` fails here and not silently in prod.
	 *
	 * @return void
	 */
	public function testRawReadDeclaresTheRawReadContract(): void {
		$this->objectService->stored['src-1'] = ['name' => 'x', 'apikey' => self::SECRET];

		$this->planner->readRawSource(uuid: 'src-1');

		$this->assertCount(1, $this->objectService->reads);
		$this->assertFalse(
			$this->objectService->reads[0]['_render'],
			'readRawSource() must pass _render: false — it is the ONLY read that still carries writeOnly secrets.'
		);
		$this->assertFalse(
			$this->objectService->reads[0]['_rbac'],
			'readRawSource() runs in system context and must pass _rbac: false.'
		);
	}//end testRawReadDeclaresTheRawReadContract()

	/**
	 * Proves the double is honest: a RENDERED read really does lose the secret.
	 *
	 * Without this, testRawReadSeesSecretsThroughTheRenderBoundary() could pass
	 * against a double that never strips anything — which would make it worthless.
	 *
	 * @return void
	 */
	public function testRenderedReadLosesTheSecretProvingTheDoubleIsHonest(): void {
		$this->objectService->stored['src-1'] = ['name' => 'x', 'apikey' => self::SECRET];

		$rendered = $this->objectService->find(id: 'src-1', register: 'openconnector', schema: 'source');

		$this->assertNotNull($rendered);
		$this->assertArrayNotHasKey(
			'apikey',
			$rendered->getObject(),
			'The double must strip writeOnly on a rendered read, mirroring openregister#389.'
		);
	}//end testRenderedReadLosesTheSecretProvingTheDoubleIsHonest()

	/**
	 * An already-migrated field (a `{credentialRef}` placeholder) is skipped —
	 * idempotency, and the basis of a no-op second run.
	 *
	 * @return void
	 */
	public function testAlreadyMigratedFieldIsSkipped(): void {
		$plan = $this->planner->planSource(
			uuid: 'src-1',
			name: 'Migrated source',
			rawData: ['apikey' => ['credentialRef' => ['credentialId' => 'abc-123']]]
		);

		$this->assertSame(0, $plan['wouldMigrate']);
		$this->assertSame(0, $plan['needsReview']);
		$this->assertSame('already-migrated', $plan['fields'][0]['state']);
	}//end testAlreadyMigratedFieldIsSkipped()

	/**
	 * A `credentialRef` carrying sibling keys is NOT a placeholder — it must not
	 * be mistaken for already-migrated. Mirrors BrokeredCallService::isPlaceholder(),
	 * which requires `credentialRef` to be the SOLE key.
	 *
	 * @return void
	 */
	public function testCredentialRefWithSiblingKeysIsNotTreatedAsMigrated(): void {
		$plan = $this->planner->planSource(
			uuid: 'src-1',
			name: 'Half-migrated source',
			rawData: ['apikey' => ['credentialRef' => ['credentialId' => 'abc'], 'legacy' => 'x']]
		);

		$this->assertNotSame(
			'already-migrated',
			$plan['fields'][0]['state'],
			'credentialRef must be the sole key to count as a placeholder (BrokeredCallService::isPlaceholder).'
		);
	}//end testCredentialRefWithSiblingKeysIsNotTreatedAsMigrated()

	/**
	 * Empty / null inline values are nothing to migrate and must not be reported.
	 *
	 * @return void
	 */
	public function testEmptyInlineValuesAreNotReported(): void {
		$plan = $this->planner->planSource(
			uuid: 'src-1',
			name: 'Empty source',
			rawData: ['apikey' => '', 'password' => null, 'jwt' => []]
		);

		$this->assertSame([], $plan['fields']);
		$this->assertSame(0, $plan['wouldMigrate']);
	}//end testEmptyInlineValuesAreNotReported()

	/**
	 * The provider mapping is a credential-correctness contract: minting a secret
	 * under the wrong provider mints a credential whose semantics are a lie.
	 *
	 * @return void
	 */
	public function testProviderMappingMatchesTheInjectOnlyCatalogue(): void {
		$plan = $this->planner->planSource(
			uuid: 'src-1',
			name: 'Everything source',
			rawData: [
				'apikey' => 'k',
				'password' => 'p',
				'secret' => 's',
				'jwt' => 'j',
			]
		);

		$byField = [];
		foreach ($plan['fields'] as $field) {
			$byField[$field['field']] = $field['provider'];
		}

		$this->assertSame('generic-apikey', $byField['apikey'], 'secret is the bare API key');
		$this->assertSame('generic-basic', $byField['password'], 'secret is the PASSWORD only; username stays app-side');
		$this->assertSame('generic-oauth2', $byField['secret'], 'secret is the OAuth2 client_secret');
		$this->assertSame(
			'generic-bearer',
			$byField['jwt'],
			'source.jwt is a pre-issued BEARER token, not a signing key — generic-jwt would misdescribe it.'
		);
		$this->assertSame(4, $plan['wouldMigrate']);
	}//end testProviderMappingMatchesTheInjectOnlyCatalogue()

	/**
	 * `authenticationConfig` is an object that may hold several values at once,
	 * and every inject-only provider stores exactly one opaque secret. The planner
	 * must flag it for a human rather than invent a decomposition.
	 *
	 * @return void
	 */
	public function testAuthenticationConfigIsFlaggedForManualReviewNotGuessed(): void {
		$plan = $this->planner->planSource(
			uuid: 'src-1',
			name: 'OAuth source',
			rawData: ['authenticationConfig' => ['client_id' => 'public', 'client_secret' => 'shh']]
		);

		$this->assertSame('needs-manual-review', $plan['fields'][0]['state']);
		$this->assertNull($plan['fields'][0]['provider'], 'The planner must not guess a provider for a multi-value bag.');
		$this->assertSame(1, $plan['needsReview']);
	}//end testAuthenticationConfigIsFlaggedForManualReviewNotGuessed()

	/**
	 * The phase D gate: `clean` is true ONLY when no source holds an unmigrated
	 * inline secret. Phase D must not remove the schema properties otherwise.
	 *
	 * @return void
	 */
	public function testPhaseDGateIsNotCleanWhileAnInlineSecretRemains(): void {
		$this->objectService->stored['src-1'] = ['name' => 'Dirty', 'apikey' => self::SECRET];

		$plan = $this->planner->planAll();

		$this->assertFalse($plan['clean'], 'A source still holds an inline secret — phase D must be blocked.');
		$this->assertSame(1, $plan['wouldMigrate']);
	}//end testPhaseDGateIsNotCleanWhileAnInlineSecretRemains()

	/**
	 * The phase D gate is clean once every source carries only placeholders.
	 *
	 * @return void
	 */
	public function testPhaseDGateIsCleanWhenEverySourceIsMigrated(): void {
		$this->objectService->stored['src-1'] = [
			'name' => 'Migrated',
			'apikey' => ['credentialRef' => ['credentialId' => 'abc-123']],
		];

		$plan = $this->planner->planAll();

		$this->assertTrue($plan['clean'], 'Every secret is a placeholder — phase D may proceed.');
		$this->assertSame(0, $plan['wouldMigrate']);
		$this->assertSame(0, $plan['needsReview']);
	}//end testPhaseDGateIsCleanWhenEverySourceIsMigrated()

	/**
	 * `authenticationConfig` alone must still block phase D: an unmappable secret
	 * is still a secret at rest.
	 *
	 * @return void
	 */
	public function testManualReviewFieldAloneStillBlocksPhaseD(): void {
		$this->objectService->stored['src-1'] = [
			'name' => 'OAuth',
			'authenticationConfig' => ['client_secret' => 'shh'],
		];

		$plan = $this->planner->planAll();

		$this->assertFalse($plan['clean'], 'An unmappable inline secret is still an inline secret.');
		$this->assertSame(1, $plan['needsReview']);
	}//end testManualReviewFieldAloneStillBlocksPhaseD()

	/**
	 * Planning is read-only: it must never write, and therefore a second run is a
	 * no-op that reports identically.
	 *
	 * @return void
	 */
	public function testPlanningIsIdempotentAndNeverMutatesTheSource(): void {
		$this->objectService->stored['src-1'] = ['name' => 'Dirty', 'apikey' => self::SECRET];

		$first = $this->planner->planAll();
		$second = $this->planner->planAll();

		$this->assertEquals($first, $second, 'A second run must report identically — the planner writes nothing.');
		$this->assertSame(
			self::SECRET,
			$this->objectService->stored['src-1']['apikey'],
			'The inline secret must be left completely intact.'
		);
	}//end testPlanningIsIdempotentAndNeverMutatesTheSource()

	/**
	 * Per-source isolation: an unreadable source must not abort the batch or hide
	 * the rest of the fleet's state.
	 *
	 * @return void
	 */
	public function testOneUnreadableSourceDoesNotAbortTheBatch(): void {
		$this->objectService->stored['good'] = ['name' => 'Good', 'apikey' => self::SECRET];
		$this->objectService->stored['bad'] = ['name' => 'Bad', 'apikey' => self::SECRET];

		// Make the raw read of 'bad' fail, leaving 'good' readable.
		$failing = new class extends RenderBoundarySimulatingObjectService {

			/**
			 * Throw for the 'bad' uuid only.
			 *
			 * @param string|int $id The uuid.
			 * @param string|null $register Unused.
			 * @param string|null $schema Unused.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 * @param bool $_render Unused.
			 *
			 * @return ObjectEntity|null
			 */
			public function find(
				$id,
				?string $register = null,
				?string $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
			bool $_audit = true,
			?array $_extend = [],
			): ?ObjectEntity {
				if ((string)$id === 'bad') {
					throw new \RuntimeException('boom');
				}

				return parent::find($id, $register, $schema, $_rbac, $_multitenancy, $_render);
			}
		};

		$failing->stored = $this->objectService->stored;
		$planner = new InlineSecretMigrationPlanner($failing, $this->logger);

		$plan = $planner->planAll();

		$this->assertSame(1, $plan['wouldMigrate'], 'The readable source must still be planned.');
		$this->assertSame('good', $plan['sources'][0]['uuid']);
	}//end testOneUnreadableSourceDoesNotAbortTheBatch()

	/**
	 * NO SECRET MAY EVER REACH A LOG — not in a message, not in a context array,
	 * not in exception text. CredentialStore's docblock is categorical, and log
	 * lines get shipped somewhere.
	 *
	 * @return void
	 */
	public function testNoSecretEverReachesTheLogger(): void {
		$this->objectService->stored['src-1'] = [
			'name' => 'Everything',
			'apikey' => self::SECRET,
			'password' => 'p4ssw0rd-live',
			'authenticationConfig' => ['client_secret' => 'client-secret-live'],
		];

		// Exercise the happy path AND the failure path (which logs).
		$this->planner->planAll();
		$this->planner->readRawSource(uuid: 'does-not-exist');

		$joined = implode("\n", $this->logger->lines);
		foreach ([self::SECRET, 'p4ssw0rd-live', 'client-secret-live'] as $secret) {
			$this->assertStringNotContainsString(
				$secret,
				$joined,
				'A secret reached the logger. Log lines get shipped somewhere; this is a disclosure.'
			);
		}
	}//end testNoSecretEverReachesTheLogger()

	/**
	 * The plan itself is an artefact that gets printed, piped and pasted. It must
	 * carry field names and provider ids only — never a secret VALUE.
	 *
	 * @return void
	 */
	public function testThePlanNeverCarriesASecretValue(): void {
		$this->objectService->stored['src-1'] = [
			'name' => 'Everything',
			'apikey' => self::SECRET,
			'password' => 'p4ssw0rd-live',
		];

		$encoded = (string)json_encode($this->planner->planAll());

		$this->assertStringNotContainsString(self::SECRET, $encoded);
		$this->assertStringNotContainsString('p4ssw0rd-live', $encoded);
		$this->assertStringContainsString('generic-apikey', $encoded, 'The plan should still name the provider.');
	}//end testThePlanNeverCarriesASecretValue()
}//end class
