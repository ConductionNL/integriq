<?php

/**
 * Unit tests for the dormant SLO curriculum source adapter.
 *
 * Every import runs offline against the recorded fixture of real SLO data.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Sources\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Slo;

use InvalidArgumentException;
use OCA\Integriq\Adapters\Slo\JsonTagReader;
use OCA\Integriq\Adapters\Slo\SloCurriculumClient;
use OCA\Integriq\Adapters\Slo\SloCurriculumClientMock;
use OCA\Integriq\Adapters\Slo\SloCurriculumMapper;
use OCA\Integriq\Adapters\Slo\SloCurriculumPresetRegistry;
use OCA\Integriq\Adapters\Slo\SloCurriculumTreeWalker;
use OCA\Integriq\Adapters\Slo\SloYearAllocator;
use OCA\Integriq\Exception\SloCurriculumException;
use OCA\Integriq\Exception\UnknownSloCurriculumSetException;
use OCA\Integriq\Sources\Slo\SloCurriculumSourceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Discovery and import produce contract-shaped, attributed, stable records.
 */
class SloCurriculumSourceAdapterTest extends TestCase {
	private const TENANT = '00000000-0000-4000-8000-000000000001';

	private const BURGERSCHAP = '612afa33-c49c-4b12-a7d1-7e44f2d69d25';

	private const TEKENEN = '43beb4d1-9950-4e88-b18e-0dee930169fe';

	private const LDK_NEDERLANDS = '9f638551-cd79-439a-a41e-b11e29899164';

	/**
	 * @param SloCurriculumClient|null $client The client (defaults to the recorded mock).
	 * @param LoggerInterface|null $logger The logger.
	 * @param string $flag The dormant flag value.
	 *
	 * @return SloCurriculumSourceAdapter
	 */
	private function adapter(?SloCurriculumClient $client = null, ?LoggerInterface $logger = null, string $flag = '0'): SloCurriculumSourceAdapter {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($flag);

		return new SloCurriculumSourceAdapter(
			$config,
			$logger ?? $this->createMock(LoggerInterface::class),
			$client ?? new SloCurriculumClientMock(),
			new SloCurriculumPresetRegistry(),
			new SloCurriculumTreeWalker(new JsonTagReader()),
			new SloCurriculumMapper(new SloYearAllocator())
		);
	}//end adapter()

	/**
	 * @return void
	 */
	public function testIsActiveFollowsTheFlag(): void {
		$this->assertFalse($this->adapter()->isActive());
		$this->assertTrue($this->adapter(flag: '1')->isActive());
		$this->assertTrue($this->adapter(flag: 'true')->isActive());
	}//end testIsActiveFollowsTheFlag()

	/**
	 * @return void
	 */
	public function testDescribeSetsListsTheProfiles(): void {
		$keys = array_column($this->adapter()->describeSets(), 'key');

		$this->assertContains('fo-kerndoelen', $keys);
		$this->assertContains('leerdoelenkaarten', $keys);
	}//end testDescribeSetsListsTheProfiles()

	/**
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-roots-are-discovered-through-slos-collection-routes-req-008
	 *
	 * @return void
	 */
	public function testDiscoveryListsTheRecordedRoots(): void {
		$adapter = $this->adapter();

		$fo = array_column($adapter->discoverRoots('fo-kerndoelen'), null, 'uuid');
		$this->assertArrayHasKey(self::BURGERSCHAP, $fo);
		$this->assertSame('Kerndoelen burgerschap', $fo[self::BURGERSCHAP]['title']);
		$this->assertSame('definitief concept', $fo[self::BURGERSCHAP]['status']);
		$this->assertGreaterThanOrEqual(16, count($fo));

		$exams = array_column($adapter->discoverRoots('examenprogramma'), 'title', 'uuid');
		$this->assertSame('Examenprogramma Tekenen vwo', $exams[self::TEKENEN]);

		$this->assertGreaterThanOrEqual(26, count($adapter->discoverRoots('kerndoelen-2006-po')));
	}//end testDiscoveryListsTheRecordedRoots()

	/**
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-roots-are-discovered-through-slos-collection-routes-req-008
	 *
	 * @return void
	 */
	public function testDiscoveryFollowsPagesWithPerPageAndSkipsDeprecated(): void {
		$client = $this->createMock(SloCurriculumClient::class);
		$client->expects($this->exactly(2))
			->method('fetch')
			->willReturnCallback(
				function (string $path, array $query): string {
					$this->assertSame('examenprogramma', $path);
					$this->assertSame(1000, $query['perPage']);
					if ($query['page'] === 0) {
						return '{"data":[{"uuid":"a","title":"A"},{"uuid":"b","title":"B","deprecated":true}],"count":3}';
					}

					return '{"data":[{"uuid":"c","title":"C"},"junk",{"title":"no id"}],"count":3}';
				}
			);

		$roots = $this->adapter($client)->discoverRoots('examenprogramma');

		$this->assertSame(['a', 'c'], array_column($roots, 'uuid'));
	}//end testDiscoveryFollowsPagesWithPerPageAndSkipsDeprecated()

	/**
	 * @return void
	 */
	public function testDiscoveryStopsAtThePageLimit(): void {
		$client = $this->createMock(SloCurriculumClient::class);
		$client->method('fetch')->willReturn('{"data":[{"uuid":"a","title":"A"}],"count":100000}');

		$this->expectException(SloCurriculumException::class);
		$this->adapter($client)->discoverRoots('examenprogramma');
	}//end testDiscoveryStopsAtThePageLimit()

	/**
	 * @return void
	 */
	public function testARenewedKerndoelensetImportsAsOneAttributedFramework(): void {
		$result = $this->adapter()->importFramework('fo-kerndoelen', self::TENANT, self::BURGERSCHAP);
		$framework = $result['framework'];
		$attribution = (new SloCurriculumPresetRegistry())->attribution();

		$this->assertSame('learniq', $framework['register']);
		$this->assertSame('competency-framework', $framework['schema']);
		$this->assertSame(self::BURGERSCHAP, $framework['originId']);
		$this->assertSame('Kerndoelen burgerschap', $framework['object']['name']);
		$this->assertSame('slo-kerndoelen', $framework['object']['sourceAuthority']);
		$this->assertSame('https://opendata.slo.nl/curriculum/uuid/' . self::BURGERSCHAP, $framework['object']['sourceRef']);
		$this->assertSame('definitief concept', $framework['object']['edition']);
		$this->assertNull($framework['object']['level']);
		$this->assertStringEndsWith($attribution['text'], $framework['object']['description']);
		$this->assertStringContainsString('CC BY 4.0', $framework['object']['description']);
		$this->assertCount(3, $framework['object']['proficiencyLevels']);
		$this->assertSame(self::TENANT, $framework['object']['tenant_id']);

		$this->assertCount(19, $result['competencies']);
		$this->assertSame(19, $result['stats']['competencies']);
		$this->assertSame(0, $result['stats']['withYears']);
		$this->assertSame('mock', $result['flavour']);
		$this->assertSame($attribution, $result['attribution']);

		$seen = [];
		foreach ($result['competencies'] as $record) {
			$object = $record['object'];
			$this->assertSame($framework['uuid'], $object['frameworkId']);
			$this->assertSame([], $object['applicableYears']);
			$this->assertNotSame('', $object['code']);
			$this->assertNotSame('', $object['title']);
			if ($object['parentId'] !== null) {
				$this->assertArrayHasKey($object['parentId'], $seen, 'parents come first');
			}

			$seen[$record['uuid']] = true;
		}
	}//end testARenewedKerndoelensetImportsAsOneAttributedFramework()

	/**
	 * @return void
	 */
	public function testAReImportYieldsTheSameIds(): void {
		$first = $this->adapter()->importFramework('fo-kerndoelen', self::TENANT, self::BURGERSCHAP);
		$second = $this->adapter()->importFramework('fo-kerndoelen', self::TENANT, self::BURGERSCHAP);

		$this->assertSame($first['framework']['uuid'], $second['framework']['uuid']);
		$this->assertSame(array_column($first['competencies'], 'uuid'), array_column($second['competencies'], 'uuid'));
		$this->assertSame(array_column($first['competencies'], 'originHash'), array_column($second['competencies'], 'originHash'));
	}//end testAReImportYieldsTheSameIds()

	/**
	 * @return void
	 */
	public function testASubjectMapFillsOnlyTheTopLevel(): void {
		$course = '00000000-0000-4000-8000-0000000000c1';
		$result = $this->adapter()->importFramework('fo-kerndoelen', self::TENANT, self::BURGERSCHAP, [' Burgerschap ' => $course]);

		$top = 0;
		foreach ($result['competencies'] as $record) {
			if ($record['object']['parentId'] === null) {
				$top++;
				$this->assertSame($course, $record['object']['subjectId']);
				continue;
			}

			$this->assertNull($record['object']['subjectId']);
		}

		$this->assertSame(3, $top);
	}//end testASubjectMapFillsOnlyTheTopLevel()

	/**
	 * @return void
	 */
	public function testThe2006KerndoelenImportAsAggregateFrameworks(): void {
		$po = $this->adapter()->importFramework('kerndoelen-2006-po', self::TENANT);

		$this->assertNull($po['rootUuid']);
		$this->assertSame('kerndoelen-2006-po', $po['framework']['originId']);
		$this->assertSame('Kerndoelen primair onderwijs (2006)', $po['framework']['object']['name']);
		$this->assertSame('2006', $po['framework']['object']['edition']);
		$this->assertSame('po', $po['framework']['object']['level']);
		$this->assertSame('https://opendata.slo.nl/curriculum/api/v1/kerndoel_vakleergebied/', $po['framework']['object']['sourceRef']);
		$this->assertCount(78, $po['competencies']);
		$this->assertSame(58, $po['stats']['leaves']);

		$vo = $this->adapter()->importFramework('kerndoelen-2006-onderbouw-vo', self::TENANT);
		$this->assertSame('vo', $vo['framework']['object']['level']);
		$this->assertCount(65, $vo['competencies']);
		$this->assertNotSame($po['framework']['uuid'], $vo['framework']['uuid']);
	}//end testThe2006KerndoelenImportAsAggregateFrameworks()

	/**
	 * @return void
	 */
	public function testAnExamenprogrammaTakesItsVersieAsEdition(): void {
		$result = $this->adapter()->importFramework('examenprogramma', self::TENANT, self::TEKENEN);

		$this->assertSame('2020', $result['framework']['object']['edition']);
		$this->assertSame('slo-eindtermen', $result['framework']['object']['sourceAuthority']);
		$this->assertSame('vo', $result['framework']['object']['level']);
		$this->assertCount(9, $result['competencies']);
	}//end testAnExamenprogrammaTakesItsVersieAsEdition()

	/**
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-years-come-only-from-slos-own-niveaus-req-006
	 *
	 * @return void
	 */
	public function testLeerdoelenkaartDoelniveausCarryTheirYears(): void {
		$result = $this->adapter()->importFramework('leerdoelenkaarten', self::TENANT, self::LDK_NEDERLANDS);

		$this->assertSame('Leerdoelenkaart Nederlands', $result['framework']['object']['name']);
		$this->assertSame('other', $result['framework']['object']['sourceAuthority']);
		$this->assertSame(2, $result['stats']['withYears']);

		$years = array_values(
			array_filter(
				array_map(static fn (array $record): array => $record['object']['applicableYears'], $result['competencies']),
				static fn (array $labels): bool => $labels !== []
			)
		);
		$this->assertSame([['groep 3', 'groep 4'], ['groep 5', 'groep 6']], $years);
	}//end testLeerdoelenkaartDoelniveausCarryTheirYears()

	/**
	 * @return void
	 */
	public function testATenantIdThatIsNotAUuidIsRefusedBeforeAnyFetch(): void {
		$client = $this->createMock(SloCurriculumClient::class);
		$client->expects($this->never())->method('fetch');

		$this->expectException(InvalidArgumentException::class);
		$this->adapter($client)->importFramework('fo-kerndoelen', 'school-1', self::BURGERSCHAP);
	}//end testATenantIdThatIsNotAUuidIsRefusedBeforeAnyFetch()

	/**
	 * @return void
	 */
	public function testAPerRootSetNeedsARoot(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('discoverRoots()');
		$this->adapter()->importFramework('fo-kerndoelen', self::TENANT);
	}//end testAPerRootSetNeedsARoot()

	/**
	 * @return void
	 */
	public function testASubjectMapValueMustBeAUuid(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->adapter()->importFramework('fo-kerndoelen', self::TENANT, self::BURGERSCHAP, ['burgerschap' => 'course-7']);
	}//end testASubjectMapValueMustBeAUuid()

	/**
	 * @return void
	 */
	public function testAnUnknownSetIsNamed(): void {
		$this->expectException(UnknownSloCurriculumSetException::class);
		$this->adapter()->importFramework('mbo-kwalificatiedossiers', self::TENANT, 'x');
	}//end testAnUnknownSetIsNamed()

	/**
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-no-personal-data-and-no-secrets-req-009
	 *
	 * @return void
	 */
	public function testTheLogCarriesCountsOnly(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('debug')
			->with(
				'slo-curriculum.importFramework',
				$this->callback(
					function (array $context): bool {
						$this->assertSame(['set', 'root', 'flavour', 'active', 'competencies', 'leaves'], array_keys($context));
						$this->assertSame(19, $context['competencies']);
						return true;
					}
				)
			);

		$this->adapter(logger: $logger)->importFramework('fo-kerndoelen', self::TENANT, self::BURGERSCHAP);
	}//end testTheLogCarriesCountsOnly()
}//end class
