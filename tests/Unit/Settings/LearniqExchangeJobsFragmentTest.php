<?php

/**
 * The learniq-exchange-jobs register fragment.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Guards the job and dead letter tags and the mapping seed rows.
 *
 * The learniq slugs are a contract with learniq's change
 * data-exchange-to-integriq: renaming one silently breaks that app's jobs.
 */
class LearniqExchangeJobsFragmentTest extends TestCase {

	/**
	 * The slugs learniq's jobs name.
	 *
	 * @var array<int, string>
	 */
	private const LEARNIQ_SLUGS = [
		'learniq-bron-rod-export-learner',
		'learniq-oso-export-dossier',
		'learniq-leerplicht-export-melding',
		'learniq-swv-export-zorgvraag',
		'learniq-timetable-import-zermelo',
		'learniq-timetable-import-untis',
		'learniq-timetable-import-xedule',
		'learniq-timetable-import-timeedit',
		'learniq-lvs-results-import-uwlr',
		'learniq-oso-import-dossier',
		'learniq-uwlr-export-pupil',
		'learniq-uwlr-export-group',
		'learniq-uwlr-export-teacher',
		'learniq-uwlr-import-results',
		'learniq-edu-v-export-onderwijsdeelnemers',
		'learniq-edu-v-export-onderwijsgroepen',
		'learniq-edu-v-export-onderwijsmedewerkers',
		'learniq-basispoort-sync-learner',
		'learniq-entree-content-sync-learner',
		'learniq-migration-import-parnassys',
		'learniq-migration-import-esis',
		'learniq-migration-import-magister',
		'learniq-migration-import-somtoday',
		'learniq-bron-rod-export-schooladvies',
	];

	/**
	 * The fragment.
	 *
	 * @return array<string, mixed> The decoded fragment.
	 */
	private function fragment(): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/learniq-exchange-jobs.json';
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded, 'The fragment must be valid JSON.');

		return $decoded;

	}//end fragment()

	/**
	 * Seed rows keyed by slug.
	 *
	 * @return array<string, array<string, mixed>> The rows.
	 */
	private function objectsBySlug(): array {
		$rows = [];
		foreach ($this->fragment()['components']['objects'] as $object) {
			$rows[(string)$object['@self']['slug']] = $object;
		}

		return $rows;

	}//end objectsBySlug()

	/**
	 * The job target enum equals the catalogue, so the dispatcher, the create
	 * validation and the schema agree on the fourteen targets.
	 *
	 * @return void
	 */
	public function testJobTargetEnumMatchesTheCatalogue(): void {
		$job = $this->fragment()['components']['schemas']['job'];

		$this->assertSame((new ExchangeTargetCatalogue())->ids(), $job['properties']['exchangeTarget']['enum']);
		$this->assertCount(14, $job['properties']['exchangeTarget']['enum']);
		$this->assertSame(
			['queued', 'running', 'succeeded', 'partial', 'failed', 'refused'],
			$job['properties']['exchangeStatus']['enum']
		);

	}//end testJobTargetEnumMatchesTheCatalogue()

	/**
	 * Both extended schemas bump their version past what earlier fragments set.
	 *
	 * @return void
	 */
	public function testSchemaVersionsAreBumped(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$this->assertSame('1.3.0', $schemas['job']['version']);
		$this->assertSame('1.1.0', $schemas['sync_item_dead_letter']['version']);

	}//end testSchemaVersionsAreBumped()

	/**
	 * No new property is required, so existing jobs and dead letters stay valid.
	 *
	 * @return void
	 */
	public function testNoNewPropertyIsRequired(): void {
		foreach ($this->fragment()['components']['schemas'] as $name => $schema) {
			$this->assertArrayNotHasKey('required', $schema, $name . ' must not add required properties.');
		}

	}//end testNoNewPropertyIsRequired()

	/**
	 * Every learniq mapping slug is seeded as a mapping row with rules.
	 *
	 * @return void
	 */
	public function testEveryLearniqMappingIsSeeded(): void {
		$rows = $this->objectsBySlug();

		foreach (self::LEARNIQ_SLUGS as $slug) {
			$this->assertArrayHasKey($slug, $rows, 'Missing mapping ' . $slug);
			$this->assertSame('mapping', $rows[$slug]['@self']['schema']);
			$this->assertNotEmpty($rows[$slug]['mapping'], $slug . ' has no rules.');
			$this->assertFalse($rows[$slug]['passThrough'], $slug . ' must not pass unmapped fields through.');
		}

	}//end testEveryLearniqMappingIsSeeded()

	/**
	 * The ROD learner mapping carries the persoonsgebonden nummer and its type,
	 * never the ECK iD as a BSN, and never the encrypted BSN.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#scenario-the-learner-mapping-maps-the-number-not-the-eck-id
	 */
	public function testTheRodMappingNeverReadsTheEncryptedBsn(): void {
		$row   = $this->objectsBySlug()['learniq-bron-rod-export-learner'];
		$rules = $row['mapping'];

		$this->assertSame('givenName', $rules['voornamen']);
		$this->assertSame('persoonsgebondenNummer', $rules['persoonsgebondenNummer']);
		$this->assertSame('persoonsgebondenNummerType', $rules['persoonsgebondenNummerType']);
		$this->assertSame('eckId', $rules['eckId']);
		$this->assertArrayNotHasKey('bsn', $rules);
		$this->assertStringNotContainsString('bsnEncrypted', (string)json_encode($rules));
		$this->assertSame('1.1.0', $row['version'], 'a changed seed row needs a version bump to re-import');

	}//end testTheRodMappingNeverReadsTheEncryptedBsn()

	/**
	 * The ROD school advice mapping maps every AanleverenAdviesVO key onto itself.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-002-the-school-advice-is-sent-as-aanleverenadviesvo_request
	 */
	public function testTheRodSchoolAdviceMappingIsSeeded(): void {
		$rules = $this->objectsBySlug()['learniq-bron-rod-export-schooladvies']['mapping'];

		$keys = [
			'persoonsgebondenNummer', 'persoonsgebondenNummerType', 'adviesvolgnummer', 'onderwijsaanbieder',
			'onderwijslocatie', 'vestigingscode', 'adviesjaar', 'advies1', 'advies1Datum', 'advies2', 'advies2Datum',
		];
		foreach ($keys as $key) {
			$this->assertSame($key, $rules[$key] ?? null, $key);
		}

	}//end testTheRodSchoolAdviceMappingIsSeeded()

	/**
	 * The vocabulary rows exist: the status translation and four code catalogues.
	 *
	 * @return void
	 */
	public function testTheVocabularyRowsAreSeeded(): void {
		$rows = $this->objectsBySlug();

		$this->assertSame(
			['open' => 'failed', 'corrected' => 'failed', 'resubmitted' => 'replayed', 'accepted' => 'replayed', 'waived' => 'discarded'],
			$rows['learniq-exchange-rejection-status']['mapping']
		);
		foreach (['bron-rod', 'oso', 'leerplicht', 'integriq'] as $catalogue) {
			$this->assertArrayHasKey('learniq-exchange-error-codes-' . $catalogue, $rows);
		}

		$this->assertSame('blocking', $rows['learniq-exchange-error-codes-bron-rod']['mapping']['BRON-102']['severity']);
		$this->assertArrayHasKey('gate-app-absent', $rows['learniq-exchange-error-codes-integriq']['mapping']);
		// A floor: other changes append rows.
		$this->assertGreaterThanOrEqual(29, count($rows));

	}//end testTheVocabularyRowsAreSeeded()

	/**
	 * No em-dash in anything a person reads.
	 *
	 * @return void
	 */
	public function testNoEmDashes(): void {
		$raw = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/learniq-exchange-jobs.json');

		$this->assertStringNotContainsString("\u{2014}", $raw);

	}//end testNoEmDashes()
}//end class
