<?php

/**
 * Unit tests for the SLO curriculum mock client.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Slo;

use OCA\Integriq\Adapters\Slo\SloCurriculumClient;
use OCA\Integriq\Adapters\Slo\SloCurriculumClientMock;
use OCA\Integriq\Exception\SloCurriculumException;
use PHPUnit\Framework\TestCase;

/**
 * The mock serves recordings offline and refuses anything else.
 */
class SloCurriculumClientMockTest extends TestCase {
	/**
	 * @return void
	 */
	public function testServesARecordedTreeOffline(): void {
		$mock = new SloCurriculumClientMock();
		$body = $mock->fetch('tree/612afa33-c49c-4b12-a7d1-7e44f2d69d25', [], 'application/jsontag');

		$this->assertSame('mock', $mock->flavour());
		$this->assertStringStartsWith('<object class="FoSet" id="/uuid/612afa33-c49c-4b12-a7d1-7e44f2d69d25">', $body);
		$this->assertStringContainsString('"FoKernzin":[', $body);
	}//end testServesARecordedTreeOffline()

	/**
	 * @return void
	 */
	public function testQueryOrderAndLeadingSlashDoNotMatter(): void {
		$mock = new SloCurriculumClientMock();

		$this->assertSame(
			$mock->fetch('examenprogramma', ['page' => 0, 'perPage' => 1000]),
			$mock->fetch('/examenprogramma', ['perPage' => 1000, 'page' => 0])
		);
		$this->assertSame('a?b=1&c=2', SloCurriculumClient::requestKey('/a', ['c' => 2, 'b' => 1]));
		$this->assertSame('a', SloCurriculumClient::requestKey('a'));
		$this->assertStringStartsWith('{"data":[', $mock->fetch('examenprogramma', ['page' => 0, 'perPage' => 1000]));
	}//end testQueryOrderAndLeadingSlashDoNotMatter()

	/**
	 * @return void
	 */
	public function testAnUnrecordedRequestFailsLoudly(): void {
		try {
			(new SloCurriculumClientMock())->fetch('tree/00000000-0000-0000-0000-000000000000');
			$this->fail('An unrecorded request must throw.');
		} catch (SloCurriculumException $exception) {
			$this->assertSame(404, $exception->getStatus());
			$this->assertStringContainsString('tree/00000000-0000-0000-0000-000000000000', $exception->getMessage());
		}
	}//end testAnUnrecordedRequestFailsLoudly()

	/**
	 * @return void
	 */
	public function testAMissingFixtureServesNothing(): void {
		$mock = new SloCurriculumClientMock(__DIR__ . '/no-such-fixture.json');

		$this->assertSame([], $mock->recordedKeys());
		$this->expectException(SloCurriculumException::class);
		$mock->fetch('fo_kerndoelen/');
	}//end testAMissingFixtureServesNothing()

	/**
	 * @return void
	 */
	public function testAStringBodyIsServedAsIs(): void {
		$path = tempnam(sys_get_temp_dir(), 'slo');
		file_put_contents($path, json_encode(['responses' => ['x' => ['body' => 'raw text'], 'bad' => 'not a response']]));

		try {
			$mock = new SloCurriculumClientMock($path);
			$this->assertSame('raw text', $mock->fetch('x'));
			$this->assertSame(['x'], $mock->recordedKeys());
		} finally {
			unlink($path);
		}
	}//end testAStringBodyIsServedAsIs()
}//end class
