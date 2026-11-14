<?php

/**
 * Golden test for SloCurriculumSourceAdapter::importFramework().
 *
 * Every import runs offline against the recorded fixture of real SLO data, so
 * the whole result and the debug line are stable and pinned by digest.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Slo;

use OCA\Integriq\Adapters\Slo\JsonTagReader;
use OCA\Integriq\Adapters\Slo\SloCurriculumClientMock;
use OCA\Integriq\Adapters\Slo\SloCurriculumMapper;
use OCA\Integriq\Adapters\Slo\SloCurriculumNodeReader;
use OCA\Integriq\Adapters\Slo\SloCurriculumPresetRegistry;
use OCA\Integriq\Adapters\Slo\SloCurriculumSourceAdapter;
use OCA\Integriq\Adapters\Slo\SloCurriculumTreeWalker;
use OCA\Integriq\Adapters\Slo\SloYearAllocator;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the full import result and its debug line per set flavour.
 */
class SloImportFrameworkGoldenTest extends TestCase {

	private const TENANT = '00000000-0000-4000-8000-000000000001';

	/**
	 * Each import and the digest of its result and debug line.
	 *
	 * @return array<string,array{0:string,1:string|null,2:array<string,string>,3:string}>
	 */
	public static function imports(): array {
		return [
			'per-root with a subject map' => [
				'fo-kerndoelen',
				'612afa33-c49c-4b12-a7d1-7e44f2d69d25',
				[' Burgerschap ' => '00000000-0000-4000-8000-0000000000c1'],
				'9d646cac7a530d5012d686be190e5e58f624a04a74bb610b70cd0be25eb43fc9',
			],
			'aggregate po' => [
				'kerndoelen-2006-po',
				null,
				[],
				'eb91cd83e057afdf5637d2e4a58653fbed47357cbe83e26f5b0cb52c7073240e',
			],
			'aggregate vo' => ['kerndoelen-2006-onderbouw-vo', null, [], '7f9dc3678f49ad36ec494a7872f80e429b459c91cb5f8a917e0e64124d643d42'],
			'examenprogramma' => ['examenprogramma', '43beb4d1-9950-4e88-b18e-0dee930169fe', [], 'dd0c27b9e55586d45e6c2e06548f976c4347962fa4d6a89beab99cb80488cbf1'],
			'leerdoelenkaarten' => ['leerdoelenkaarten', '9f638551-cd79-439a-a41e-b11e29899164', [], 'bb442b8709f49bfcc0aeb264a56a14eedb19461201407db71c40df4ea4634484'],
		];

	}//end imports()

	/**
	 * The import answers and logs exactly what it did before.
	 *
	 * @param string               $setKey   The set.
	 * @param string|null          $rootUuid The root.
	 * @param array<string,string> $subjects The subject map.
	 * @param string               $golden   The digest.
	 *
	 * @return void
	 */
	#[DataProvider('imports')]
	public function testImportIsUnchanged(string $setKey, ?string $rootUuid, array $subjects, string $golden): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('0');
		$debug = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context = []) use (&$debug): void {
				$debug[] = [$message, $context];
			}
		);
		$adapter = new SloCurriculumSourceAdapter(
			$config,
			$logger,
			new SloCurriculumClientMock(),
			new SloCurriculumPresetRegistry(),
			new SloCurriculumTreeWalker(new JsonTagReader(), new SloCurriculumNodeReader(new JsonTagReader())),
			new SloCurriculumMapper(new SloYearAllocator())
		);

		$result = $adapter->importFramework($setKey, self::TENANT, $rootUuid, $subjects);

		$this->assertSame($golden, hash('sha256', json_encode([$result, $debug], JSON_THROW_ON_ERROR)));

	}//end testImportIsUnchanged()
}//end class
