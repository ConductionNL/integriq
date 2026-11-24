<?php

/**
 * The Studytube learning platform set, run through the real MappingService and
 * checked against humaniq's TrainingRecord contract.
 *
 * The completions mapping runs over a recorded learning-results page and every
 * object it produces is validated against
 * `tests/fixtures/hr-data-exchange/training-record.schema.json`, the fields
 * humaniq `talent-training-and-lms` D1 defines. The people mapping runs over a
 * recorded people feed. The synchronizations are checked for what makes a repeat
 * pull write nothing new: incremental with a cursor the results carry, and keyed
 * on the platform's result id.
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
 * @spec openspec/changes/connectors-hr-data-exchange/specs/hr-data-exchange/spec.md#requirement-a-learning-platform-learns-who-works-where-and-finished-trainings-come-back-req-hrx-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use Adbar\Dot;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Covers lib/Settings/register.d/studytube-lms-source.json.
 */
final class StudytubeLmsSetTest extends TestCase {

	/**
	 * The fragment's objects, by schema and slug.
	 *
	 * @return array<string, array<string, array<string, mixed>>> The objects.
	 */
	private function seeds(): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/studytube-lms-source.json';
		$fragment = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$seeds = [];
		foreach ($fragment['components']['objects'] as $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$seeds[$self['schema']][$self['slug']] = $object;
		}

		return $seeds;
	}//end seeds()

	/**
	 * A recorded fixture under tests/fixtures/hr-data-exchange.
	 *
	 * @param string $file The fixture file.
	 *
	 * @return array<mixed> The decoded fixture.
	 */
	private function fixture(string $file): array {
		return json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/hr-data-exchange/' . $file), true, 512, JSON_THROW_ON_ERROR);
	}//end fixture()

	/**
	 * The finished courses on the recorded page, as the completions pull reads them.
	 *
	 * @return array<int, array<string, mixed>> The results.
	 */
	private function results(): array {
		$position = $this->seeds()['synchronization']['lms-completions-pull']['sourceConfig']['resultsPosition'];

		return (new Dot($this->fixture(file: 'studytube/learning-results.json')))->get($position);
	}//end results()

	/**
	 * Map one input with the real MappingService.
	 *
	 * @param string               $slug  The mapping slug.
	 * @param array<string, mixed> $input The input object.
	 *
	 * @return array<string, mixed> The mapped object.
	 */
	private function map(string $slug, array $input): array {
		$service = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(ORObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);

		return $service->executeMapping(mapping: $this->seeds()['mapping'][$slug], input: $input);
	}//end map()

	/**
	 * Every recorded finished course becomes a TrainingRecord the contract accepts.
	 *
	 * @return void
	 */
	public function testEveryFinishedCourseBecomesATrainingRecordTheContractAccepts(): void {
		$contract = $this->fixture(file: 'training-record.schema.json');
		$checked = 0;
		foreach ($this->results() as $result) {
			$mapped = $this->map(slug: 'studytube-completion-to-training-record', input: $result);
			$this->assertSame([], RegisterSchemaValidator::errorsAgainst(schema: $contract, object: $mapped), 'result ' . $result['id']);
			$checked++;
		}

		$this->assertSame(2, $checked);
	}//end testEveryFinishedCourseBecomesATrainingRecordTheContractAccepts()

	/**
	 * The BHV refresher of the scenario lands as followed, from the LMS, with its certificate's expiry.
	 *
	 * @return void
	 */
	public function testTheBhvRefresherIsAFollowedTrainingFromTheLms(): void {
		$mapped = $this->map(slug: 'studytube-completion-to-training-record', input: $this->results()[0]);

		$this->assertSame(
			[
				'employeeId' => '6f1c2b9e-3d4a-4e5f-9a8b-7c6d5e4f3a21',
				'title' => 'BHV herhaling',
				'provider' => 'NIBHV',
				'status' => 'gevolgd',
				'completedOn' => '2026-10-02',
				'validUntil' => '2027-10-02',
				'competenceCode' => 'BHV',
				'source' => 'lms',
				'sourceRef' => '88123',
			],
			$mapped
		);
	}//end testTheBhvRefresherIsAFollowedTrainingFromTheLms()

	/**
	 * A course without a certificate or a competence leaves those fields out instead of writing empty dates.
	 *
	 * @return void
	 */
	public function testACourseWithoutCertificateLeavesValidUntilOut(): void {
		$mapped = $this->map(slug: 'studytube-completion-to-training-record', input: $this->results()[1]);

		$this->assertArrayNotHasKey('validUntil', $mapped);
		$this->assertArrayNotHasKey('competenceCode', $mapped);
		$this->assertSame('Studytube', $mapped['provider']);
		$this->assertSame('2026-10-03', $mapped['completedOn']);
	}//end testACourseWithoutCertificateLeavesValidUntilOut()

	/**
	 * Pulling the same completion twice gives the same origin id and the same record, so one TrainingRecord exists.
	 *
	 * @return void
	 */
	public function testARepeatPullOfTheSameCompletionKeepsOneRecord(): void {
		$sync = $this->seeds()['synchronization']['lms-completions-pull'];
		$first = $this->results()[0];
		$again = $this->results()[0];

		$idPosition = $sync['sourceConfig']['idPosition'];
		$this->assertSame('id', $idPosition);
		$this->assertSame((new Dot($first))->get($idPosition), (new Dot($again))->get($idPosition));
		$this->assertSame(
			$this->map(slug: 'studytube-completion-to-training-record', input: $first),
			$this->map(slug: 'studytube-completion-to-training-record', input: $again)
		);
		$this->assertSame((string)$first['id'], $this->map(slug: 'studytube-completion-to-training-record', input: $first)['sourceRef']);
	}//end testARepeatPullOfTheSameCompletionKeepsOneRecord()

	/**
	 * The completions pull is incremental on a cursor the results carry, asks for finished courses only and writes TrainingRecord.
	 *
	 * @return void
	 */
	public function testTheCompletionsPullIsIncrementalAndWritesTrainingRecord(): void {
		$sync = $this->seeds()['synchronization']['lms-completions-pull'];

		$this->assertSame('incremental', $sync['syncMode']);
		$this->assertSame('studytube-lms', $sync['sourceId']);
		$this->assertSame('studytube-completion-to-training-record', $sync['sourceTargetMapping']);
		$this->assertSame('humaniq/TrainingRecord', $sync['targetId']);
		$this->assertSame('register/schema', $sync['targetType']);
		$this->assertSame('completed', $sync['sourceConfig']['query']['status']);
		$this->assertSame('{{ cursor }}', $sync['sourceConfig']['query']['updated_since']);
		foreach ($this->results() as $result) {
			$this->assertNotEmpty((new Dot($result))->get($sync['sourceConfig']['cursorField']), 'result ' . $result['id'] . ' carries no cursor');
		}

		$this->assertFalse($sync['isEnabled']);
	}//end testTheCompletionsPullIsIncrementalAndWritesTrainingRecord()

	/**
	 * A person from humaniq's feed becomes a Studytube user keyed on the humaniq employee id.
	 *
	 * @return void
	 */
	public function testAPersonBecomesAStudytubeUserKeyedOnTheEmployeeId(): void {
		$people = $this->fixture(file: 'studytube/people.json');

		$this->assertSame(
			[
				'uid' => '6f1c2b9e-3d4a-4e5f-9a8b-7c6d5e4f3a21',
				'first_name' => 'Sanne',
				'last_name' => 'de Vries',
				'username' => 'sdevries',
				'team' => 'Advies',
				'function' => 'Adviseur',
				'manager_uids' => ['pkade'],
				'start_date' => '2021-03-01',
			],
			$this->map(slug: 'humaniq-person-to-studytube-user', input: $people[0])
		);

		$leaver = $this->map(slug: 'humaniq-person-to-studytube-user', input: $people[1]);
		$this->assertSame('2026-12-31', $leaver['end_date']);
		$this->assertSame([], $leaver['manager_uids']);
	}//end testAPersonBecomesAStudytubeUserKeyedOnTheEmployeeId()

	/**
	 * The people push reads humaniq's feed since its cursor and sends each person to Studytube's users.
	 *
	 * @return void
	 */
	public function testThePeoplePushReadsTheFeedIncrementallyAndWritesStudytubeUsers(): void {
		$sync = $this->seeds()['synchronization']['lms-people-push'];

		$this->assertSame('incremental', $sync['syncMode']);
		$this->assertSame('humaniq-learning-people', $sync['sourceId']);
		$this->assertSame('/people', $sync['sourceConfig']['endpoint']);
		$this->assertSame('{{ cursor }}', $sync['sourceConfig']['query']['modifiedSince']);
		$this->assertSame('studytube-lms', $sync['targetId']);
		$this->assertSame('api', $sync['targetType']);
		$this->assertSame('/users', $sync['targetConfig']['endpoint']);
		foreach ($this->fixture(file: 'studytube/people.json') as $person) {
			$this->assertNotEmpty($person[$sync['sourceConfig']['cursorField']]);
		}

		$this->assertFalse($sync['isEnabled']);
	}//end testThePeoplePushReadsTheFeedIncrementallyAndWritesStudytubeUsers()

	/**
	 * Both sources ship dormant, with their secret only as a broker reference.
	 *
	 * @return void
	 */
	public function testBothSourcesAreDormantWithBrokeredSecrets(): void {
		$sources = $this->seeds()['source'];

		$this->assertFalse($sources['studytube-lms']['isEnabled']);
		$this->assertSame(
			'studytube-lms-client-secret',
			$sources['studytube-lms']['configuration']['authentication']['client_secret']['credentialRef']['credentialName']
		);
		$this->assertSame('', $sources['studytube-lms']['configuration']['authentication']['client_id']);

		$this->assertFalse($sources['humaniq-learning-people']['isEnabled']);
		$this->assertSame(
			'humaniq-learning-people-app-password',
			$sources['humaniq-learning-people']['configuration']['authentication']['password']['credentialRef']['credentialName']
		);
		$this->assertSame('', $sources['humaniq-learning-people']['location']);
	}//end testBothSourcesAreDormantWithBrokeredSecrets()
}//end class
