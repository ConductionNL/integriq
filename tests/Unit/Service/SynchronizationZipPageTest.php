<?php

/**
 * Tests for how SynchronizationService reads a page that is a zip archive.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Unit\Service\Synchronization\ZipArchiveMembersTest;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins that every member of a zip page is parsed and joined, and that an
 * unreadable member or archive fails the page instead of emptying it.
 *
 * @covers \OCA\Integriq\Service\SynchronizationService
 * @uses   \OCA\Integriq\Service\Synchronization\SourcePage
 * @uses   \OCA\Integriq\Service\Synchronization\ZipArchiveMembers
 *
 * @spec openspec/changes/native-data-gathering-provider/tasks.md
 */
class SynchronizationZipPageTest extends TestCase {

	/**
	 * @var SynchronizationService
	 */
	private SynchronizationService $service;

	/**
	 * The error lines logged.
	 *
	 * @var list<string>
	 */
	private array $errors = [];

	/**
	 * Set up a service without its constructor, with a recording logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = (new ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		(function (LoggerInterface $logger): void {
			$this->logger = $logger;
		})->call($this->service, $logger);
	}//end setUp()

	/**
	 * Fetches page 1 of a prefetched answer carrying the given bytes, base64-encoded as CallService stores binary bodies.
	 *
	 * @param string $bytes        The response body.
	 * @param array  $source       The source.
	 * @param string $endpoint     The endpoint.
	 * @param array  $sourceConfig The synchronization's sourceConfig.
	 *
	 * @return array The page.
	 */
	private function fetch(string $bytes, array $source = [], string $endpoint = '/export', array $sourceConfig = ['resultsPosition' => '_root']): array {
		$callLog = new ObjectEntity();
		$callLog->setUuid('call-1');
		$callLog->setObject(
			[
				'statusCode' => 200,
				'response'   => ['body' => base64_encode($bytes), 'headers' => [], 'encoding' => 'base64'],
			]
		);
		(function (ObjectEntity $callLog): void {
			$this->pagePrefetch = [1 => $callLog];
		})->call($this->service, $callLog);

		return (new ReflectionMethod(SynchronizationService::class, 'fetchSinglePageData'))->invoke(
			$this->service,
			$source + ['location' => 'https://source.example'],
			$endpoint,
			['pagination' => ['index' => 1, 'page' => 1]],
			['sourceConfig' => $sourceConfig]
		);
	}//end fetch()

	/**
	 * The objects of every member are joined in archive order.
	 *
	 * @return void
	 */
	public function testEveryMemberIsReadAndJoined(): void {
		$archive = ZipArchiveMembersTest::archive(['day-1.json' => '[{"id":1},{"id":2}]', 'day-2.json' => '[{"id":3}]']);
		$page = $this->fetch($archive);

		$this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $page['objects']);
		$this->assertArrayNotHasKey('failed', $page);
	}//end testEveryMemberIsReadAndJoined()

	/**
	 * Members are read with the synchronization's own format, here CSV.
	 *
	 * @return void
	 */
	public function testMembersAreReadWithTheSynchronizationsFormat(): void {
		$page = $this->fetch(
			ZipArchiveMembersTest::archive(['a.csv' => "code;naam\n0363;Amsterdam\n", 'b.csv' => "code;naam\n0599;Rotterdam\n"]),
			[],
			'/gemeenten.zip',
			['format' => 'csv', 'csvDelimiter' => ';']
		);

		$this->assertSame([['code' => '0363', 'naam' => 'Amsterdam'], ['code' => '0599', 'naam' => 'Rotterdam']], $page['objects']);
	}//end testMembersAreReadWithTheSynchronizationsFormat()

	/**
	 * `zipMembers` on the source narrows which members are read.
	 *
	 * @return void
	 */
	public function testTheSourcesMemberPatternNarrowsTheMembers(): void {
		$page = $this->fetch(
			ZipArchiveMembersTest::archive(['notices.json' => '[{"id":1}]', 'readme.txt' => 'not data']),
			['configuration' => ['zipMembers' => '*.json']]
		);

		$this->assertSame([['id' => 1]], $page['objects']);
	}//end testTheSourcesMemberPatternNarrowsTheMembers()

	/**
	 * One member no parser can read fails the whole page.
	 *
	 * @return void
	 */
	public function testAnUnreadableMemberFailsThePage(): void {
		$page = $this->fetch(ZipArchiveMembersTest::archive(['a.json' => '[{"id":1}]', 'b.txt' => 'no parser reads this']));

		$this->assertSame(['objects' => [], 'result' => [], 'failed' => true, 'statusCode' => 200], $page);
	}//end testAnUnreadableMemberFailsThePage()

	/**
	 * A `.zip` endpoint whose body is not an archive fails the page and says why.
	 *
	 * @return void
	 */
	public function testAZipEndpointThatIsNotAnArchiveFailsThePage(): void {
		$page = $this->fetch('[{"id":1}]', [], '/export.zip');

		$this->assertTrue($page['failed']);
		$this->assertSame([], $page['objects']);
		$this->assertCount(1, $this->errors);
		$this->assertStringContainsString('zip archive that could not be read', $this->errors[0]);
	}//end testAZipEndpointThatIsNotAnArchiveFailsThePage()

	/**
	 * A plain JSON body on an ordinary endpoint is not taken for an archive.
	 *
	 * @return void
	 */
	public function testAPlainBodyIsNotTakenForAnArchive(): void {
		$page = $this->fetch('[{"id":7}]');

		$this->assertSame([['id' => 7]], $page['objects']);
		$this->assertSame([], $this->errors);
	}//end testAPlainBodyIsNotTakenForAnArchive()
}//end class
