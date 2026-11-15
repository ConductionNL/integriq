<?php

/**
 * The job board mappings, run through the real MappingService over humaniq's
 * seeded vacancy and compared with each board's recorded example.
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
 * @spec openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-werknl-linkedin-and-indeed-are-job-board-templates-req-jbc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Controller\XMLResponse;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Covers the jobboard-linkedin and jobboard-indeed fragments in register.d.
 */
final class JobBoardSetsTest extends TestCase {

	/**
	 * One board's fragment objects, by schema and slug.
	 *
	 * @param string $board The board fragment name.
	 *
	 * @return array<string, array<string, array<string, mixed>>> The objects.
	 */
	private function seeds(string $board): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/jobboard-' . $board . '-source.json';
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
	 * A fixture under tests/fixtures/jobboards.
	 *
	 * @param string $file The file name.
	 *
	 * @return array<string, mixed>
	 */
	private function fixture(string $file): array {
		return json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/jobboards/' . $file), true, 512, JSON_THROW_ON_ERROR);
	}//end fixture()

	/**
	 * Map the seeded vacancy with the real MappingService.
	 *
	 * @param array<string, mixed> $mapping The mapping row.
	 *
	 * @return array<string, mixed>
	 */
	private function map(array $mapping): array {
		$service = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(ORObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);

		return $service->executeMapping(mapping: $mapping, input: $this->fixture(file: 'vacancy-vue-developer.json'));
	}//end map()

	/**
	 * With the organisation and apply address filled, the vacancy becomes the recorded LinkedIn request.
	 *
	 * @return void
	 */
	public function testTheVacancyBecomesTheRecordedLinkedInRequest(): void {
		$mapping = $this->seeds(board: 'linkedin')['mapping']['jobboard-linkedin'];
		$mapping['mapping']['elements.0.integrationContext'] = 'urn:li:organization:2414183';
		$mapping['mapping']['elements.0.companyApplyUrl'] = 'https://werken.example.nl/vacatures/{{ vacancyId }}';

		$this->assertSame($this->fixture(file: 'linkedin-simple-job-postings-request.json'), $this->map(mapping: $mapping));
	}//end testTheVacancyBecomesTheRecordedLinkedInRequest()

	/**
	 * The LinkedIn template is dormant, holds its secret by broker reference and posts as a batch create.
	 *
	 * @return void
	 */
	public function testTheLinkedInTemplateIsDormantWithItsSecretInTheBroker(): void {
		$source = $this->seeds(board: 'linkedin')['source']['jobboard-linkedin'];

		$this->assertFalse($source['isEnabled']);
		$this->assertSame(['credentialRef' => ['credentialName' => 'jobboard-linkedin-client-secret']], $source['configuration']['authentication']['client_secret']);
		$this->assertSame('batch_create', $source['configuration']['headers']['X-Restli-Method']);
		$this->assertStringContainsString('partner programme', $source['description']);

		// The recorded answer names the posting task the flow keeps as the reference.
		$answer = $this->fixture(file: 'linkedin-simple-job-postings-answer.json');
		$this->assertSame(202, $answer['elements'][0]['status']);
		$this->assertStringStartsWith('urn:li:simpleJobPostingTask:', $answer['elements'][0]['id']);
	}//end testTheLinkedInTemplateIsDormantWithItsSecretInTheBroker()

	/**
	 * With the employer and vacancy page filled, the vacancy becomes the recorded Indeed feed job, without applicant data.
	 *
	 * @return void
	 */
	public function testTheVacancyBecomesTheRecordedIndeedFeedJob(): void {
		$mapping = $this->seeds(board: 'indeed')['mapping']['jobboard-indeed'];
		$mapping['mapping']['url'] = 'https://werken.example.nl/vacatures/{{ vacancyId }}';
		$mapping['mapping']['company'] = 'Voorbeeldgemeente';
		$mapping['mapping']['sourcename'] = 'Voorbeeldgemeente';

		$job = $this->map(mapping: $mapping);

		$this->assertSame($this->fixture(file: 'indeed-feed-job.json'), $job);
		$this->assertArrayNotHasKey('questions', $job);
		$this->assertArrayNotHasKey('administrationId', $job);
	}//end testTheVacancyBecomesTheRecordedIndeedFeedJob()

	/**
	 * A vacancy without experience or closing date leaves those feed fields empty.
	 *
	 * @return void
	 */
	public function testMissingOptionalVacancyFieldsLeaveTheFeedFieldsEmpty(): void {
		$service = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(ORObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);
		$input = $this->fixture(file: 'vacancy-vue-developer.json');
		unset($input['minExperienceYears'], $input['closingDate'], $input['department']);

		$job = $service->executeMapping(mapping: $this->seeds(board: 'indeed')['mapping']['jobboard-indeed'], input: $input);

		$this->assertSame('', $job['experience']);
		$this->assertSame('', $job['expirationdate']);
		$this->assertSame('', $job['category']);
	}//end testMissingOptionalVacancyFieldsLeaveTheFeedFieldsEmpty()

	/**
	 * The feed lists the published vacancy as one Indeed job under Indeed's
	 * <source> root, rendered as the recorded feed (design D3).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-werknl-linkedin-and-indeed-are-job-board-templates-req-jbc-002
	 */
	public function testThePublishedVacancyIsRenderedAsTheRecordedIndeedFeed(): void {
		$seeds = $this->seeds(board: 'indeed');
		$job = $seeds['mapping']['jobboard-indeed'];
		$job['mapping']['url'] = 'https://werken.example.nl/vacatures/{{ vacancyId }}';
		$job['mapping']['company'] = 'Voorbeeldgemeente';
		$job['mapping']['sourcename'] = 'Voorbeeldgemeente';
		$feed = $seeds['mapping']['jobboard-indeed-feed'];
		$feed['mapping']['publisher'] = 'Voorbeeldgemeente';
		$feed['mapping']['publisherurl'] = 'https://werken.example.nl';

		$service = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(ORObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);
		$listed = ['results' => [$service->executeMapping(mapping: $job, input: $this->fixture(file: 'vacancy-vue-developer.json'))], 'count' => 1, 'page' => 1, 'pages' => 1];
		$body = $service->executeMapping(mapping: $feed, input: $listed);

		$xml = (new XMLResponse(data: $body))->render();

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/jobboards/indeed-feed.xml'), $xml);
		$this->assertStringNotContainsString('administrationId', $xml);
		$this->assertStringNotContainsString('ADM-001', $xml);
	}//end testThePublishedVacancyIsRenderedAsTheRecordedIndeedFeed()

	/**
	 * The feed endpoint is anonymous only through its throttle, lists only
	 * published vacancies offered to Indeed, and runs the job mapping before
	 * the feed shape.
	 *
	 * @return void
	 */
	public function testTheFeedEndpointListsPublishedIndeedVacanciesOnly(): void {
		$seeds = $this->seeds(board: 'indeed');
		$endpoint = $seeds['endpoint']['jobboard-indeed-feed'];

		$this->assertSame('GET', $endpoint['method']);
		$this->assertSame('humaniq/Vacancy', $endpoint['targetId']);
		$this->assertSame(['status' => 'gepubliceerd', 'channels' => 'indeed'], $endpoint['fixedFilters']);
		$this->assertSame(['requestsPerWindow' => 60, 'windowSeconds' => 60], $endpoint['anonymousRateLimit']);
		$this->assertSame(1, preg_match($endpoint['endpointRegex'], 'jobfeeds/indeed.xml'));
		$this->assertSame(0, preg_match($endpoint['endpointRegex'], 'jobfeeds/indeed.xml/1'));
		$this->assertSame(0, preg_match($endpoint['endpointRegex'], 'jobfeeds/linkedin.xml'));
		$this->assertSame(['jobboard-indeed-feed-item', 'jobboard-indeed-feed-envelope'], $endpoint['rules']);
		$this->assertSame('jobboard-indeed', $seeds['rule']['jobboard-indeed-feed-item']['configuration']['mapping']);
		$this->assertSame(['mapping' => 'jobboard-indeed-feed', 'mapResults' => false], $seeds['rule']['jobboard-indeed-feed-envelope']['configuration']);
		$this->assertLessThan($seeds['rule']['jobboard-indeed-feed-envelope']['order'], $seeds['rule']['jobboard-indeed-feed-item']['order']);
	}//end testTheFeedEndpointListsPublishedIndeedVacanciesOnly()
}//end class
