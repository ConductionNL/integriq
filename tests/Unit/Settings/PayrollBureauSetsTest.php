<?php

/**
 * The three payroll bureau sets, run through the real MappingService.
 *
 * One humaniq PayrollHandoff with three mutations (September 2026 of
 * Adviesbureau Kade B.V., the REQ-HRX-001 scenario) is mapped for Loket.nl,
 * Nmbrs and the SFTP drop, and each output is compared with that bureau's
 * example under `tests/fixtures/hr-data-exchange/payroll/`. The sources are
 * checked for what keeps them safe to ship: dormant, and with every secret held
 * by the credential broker.
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
 * @spec openspec/changes/connectors-hr-data-exchange/specs/hr-data-exchange/spec.md#requirement-a-ready-payroll-handoff-reaches-the-bureau-and-its-payslips-come-back-req-hrx-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Covers lib/Settings/register.d/loket-payroll-source.json,
 * nmbrs-payroll-source.json and payroll-sftp-drop-source.json.
 */
final class PayrollBureauSetsTest extends TestCase {

	/**
	 * Per bureau: the fragment, the source slug, the mapping slug, the mapped list and the example.
	 *
	 * @var array<string, array{fragment: string, source: string, mapping: string, list: string, example: string}>
	 */
	private const BUREAUS = [
		'loket'     => [
			'fragment' => 'loket-payroll-source.json',
			'source'   => 'loket-payroll',
			'mapping'  => 'payroll-handoff-to-loket-mutations',
			'list'     => 'mutations',
			'example'  => 'loket-mutations.json',
		],
		'nmbrs'     => [
			'fragment' => 'nmbrs-payroll-source.json',
			'source'   => 'nmbrs-payroll',
			'mapping'  => 'payroll-handoff-to-nmbrs-changes',
			'list'     => 'changes',
			'example'  => 'nmbrs-changes.json',
		],
		'sftp-drop' => [
			'fragment' => 'payroll-sftp-drop-source.json',
			'source'   => 'payroll-sftp-drop',
			'mapping'  => 'payroll-handoff-to-csv-rows',
			'list'     => 'rows',
			'example'  => 'sftp-drop-file.json',
		],
	];

	/**
	 * A fragment's objects, by schema and slug.
	 *
	 * @param string $fragment The fragment file under lib/Settings/register.d.
	 *
	 * @return array<string, array<string, array<string, mixed>>> The objects.
	 */
	private function seeds(string $fragment): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/' . $fragment;
		$decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$seeds = [];
		foreach ($decoded['components']['objects'] as $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$seeds[$self['schema']][$self['slug']] = $object;
		}

		return $seeds;
	}//end seeds()

	/**
	 * A fixture under tests/fixtures/hr-data-exchange/payroll.
	 *
	 * @param string $file The fixture file.
	 *
	 * @return array<mixed> The decoded fixture.
	 */
	private function fixture(string $file): array {
		$path = dirname(__DIR__, 2) . '/fixtures/hr-data-exchange/payroll/' . $file;

		return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}//end fixture()

	/**
	 * Map one handoff with the real MappingService.
	 *
	 * @param string               $bureau The bureau key in BUREAUS.
	 * @param array<string, mixed> $input  The handoff, with its mutations and administration.
	 *
	 * @return array<string, mixed> The mapped delivery.
	 */
	private function map(string $bureau, array $input): array {
		$service = new MappingService(
			loader: new ArrayLoader(templates: []),
			callService: $this->createMock(originalClassName: CallService::class),
			fileService: $this->createMock(originalClassName: FileService::class),
			objectService: $this->createMock(originalClassName: ObjectService::class),
			orObjectService: $this->createMock(originalClassName: ORObjectService::class),
			synchronizationContractService: $this->createMock(originalClassName: SynchronizationContractService::class),
		);
		$set = self::BUREAUS[$bureau];

		return $service->executeMapping(mapping: $this->seeds(fragment: $set['fragment'])['mapping'][$set['mapping']], input: $input);
	}//end map()

	/**
	 * The bureaus, as a data provider.
	 *
	 * @return array<string, array{0: string}> One case per bureau.
	 */
	public static function bureaus(): array {
		return array_combine(
			array_keys(self::BUREAUS),
			array_map(static fn (string $bureau): array => [$bureau], array_keys(self::BUREAUS))
		);
	}//end bureaus()

	/**
	 * September's three mutations, mapped for each bureau, match that bureau's example.
	 *
	 * @param string $bureau The bureau key.
	 *
	 * @return void
	 *
	 * @dataProvider bureaus
	 */
	public function testTheHandoffMatchesTheBureausExample(string $bureau): void {
		$mapped = $this->map(bureau: $bureau, input: $this->fixture(file: 'handoff-2026-09.json'));

		$this->assertSame(expected: $this->fixture(file: self::BUREAUS[$bureau]['example']), actual: $mapped);
	}//end testTheHandoffMatchesTheBureausExample()

	/**
	 * Each of the three mutations is delivered exactly once, and the delivery names the handoff.
	 *
	 * @param string $bureau The bureau key.
	 *
	 * @return void
	 *
	 * @dataProvider bureaus
	 */
	public function testEachMutationIsDeliveredOnce(string $bureau): void {
		$handoff = $this->fixture(file: 'handoff-2026-09.json');
		$mapped = $this->map(bureau: $bureau, input: $handoff);
		$list = $mapped[self::BUREAUS[$bureau]['list']];

		$this->assertCount(expectedCount: $handoff['mutationCount'], haystack: $list);
		$this->assertSame(expected: $handoff['id'], actual: $mapped['handoffId']);
	}//end testEachMutationIsDeliveredOnce()

	/**
	 * Loket.nl gets the new values only, in its own words, with the mutation id as the external reference.
	 *
	 * @return void
	 */
	public function testLoketGetsTheNewValueInItsOwnWords(): void {
		$first = $this->map(bureau: 'loket', input: $this->fixture(file: 'handoff-2026-09.json'))['mutations'][0];

		$this->assertSame(expected: 'salaris', actual: $first['mutationType']);
		$this->assertSame(expected: ['grossMonthlySalary' => 3950], actual: $first['values']);
		$this->assertSame(expected: 'c1e4b7a0-2f3d-4c5b-8a9e-1d2c3b4a5f60', actual: $first['externalReference']);
	}//end testLoketGetsTheNewValueInItsOwnWords()

	/**
	 * A mutation without an effective date applies from the first day of the period at an API bureau.
	 *
	 * @return void
	 */
	public function testAMutationWithoutADateStartsOnTheFirstOfThePeriod(): void {
		$handoff = $this->fixture(file: 'handoff-2026-09.json');

		$this->assertSame(expected: '2026-09-01', actual: $this->map(bureau: 'loket', input: $handoff)['mutations'][2]['startDate']);
		$this->assertSame(expected: '2026-09-01', actual: $this->map(bureau: 'nmbrs', input: $handoff)['changes'][2]['validFrom']);
	}//end testAMutationWithoutADateStartsOnTheFirstOfThePeriod()

	/**
	 * The SFTP file is named after the loonheffingennummer and the period, and a starter's empty old value stays empty.
	 *
	 * @return void
	 */
	public function testTheDropFileIsNamedPerPeriodAndAStarterHasNoOldValue(): void {
		$handoff = $this->fixture(file: 'handoff-2026-09.json');
		$handoff['mutations'] = [
			[
				'id'            => 'f4b7e0d3-5c6a-4f8e-9d2b-4a5f6e7d8c93',
				'employeeId'    => '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e',
				'kind'          => 'start',
				'effectiveDate' => '2026-09-15',
				'fields'        => ['startDate' => ['old' => null, 'new' => '2026-09-15']],
			],
		];

		$mapped = $this->map(bureau: 'sftp-drop', input: $handoff);

		$this->assertSame(expected: '812345671L01-2026-09.csv', actual: $mapped['fileName']);
		$this->assertSame(expected: '', actual: $mapped['rows'][0]['oud']);
		$this->assertSame(expected: '2026-09-15', actual: $mapped['rows'][0]['nieuw']);
	}//end testTheDropFileIsNamedPerPeriodAndAStarterHasNoOldValue()

	/**
	 * A handoff without mutations maps to an empty delivery instead of failing.
	 *
	 * @param string $bureau The bureau key.
	 *
	 * @return void
	 *
	 * @dataProvider bureaus
	 */
	public function testAHandoffWithoutMutationsMapsToAnEmptyList(string $bureau): void {
		$handoff = $this->fixture(file: 'handoff-2026-09.json');
		unset($handoff['mutations']);

		$this->assertSame(expected: [], actual: $this->map(bureau: $bureau, input: $handoff)[self::BUREAUS[$bureau]['list']]);
	}//end testAHandoffWithoutMutationsMapsToAnEmptyList()

	/**
	 * Every bureau source ships dormant, and no secret is written into it.
	 *
	 * @param string $bureau The bureau key.
	 *
	 * @return void
	 *
	 * @dataProvider bureaus
	 */
	public function testTheSourceIsDormantAndHoldsNoSecret(string $bureau): void {
		$set = self::BUREAUS[$bureau];
		$source = $this->seeds(fragment: $set['fragment'])['source'][$set['source']];

		$this->assertFalse(condition: $source['isEnabled']);
		$secret = $source['configuration']['authentication']['client_secret'] ?? null;
		if ($secret !== null) {
			$this->assertSame(expected: ['credentialRef' => ['credentialName' => $set['source'] . '-client-secret']], actual: $secret);
		}
	}//end testTheSourceIsDormantAndHoldsNoSecret()
}//end class
