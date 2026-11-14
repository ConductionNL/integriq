<?php

/**
 * Unit tests for the recorded-response Edurep transport.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\EdurepFixtureTransport;
use OCA\Integriq\Adapters\LearningMaterial\SourceUnavailableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A known query answers its recording; anything else is unavailable.
 */
#[CoversClass(EdurepFixtureTransport::class)]
#[UsesClass(SourceUnavailableException::class)]
class EdurepFixtureTransportTest extends TestCase {
	private string $dir = '';

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/edurep-fixture-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((array)glob($this->dir . '/*') as $file) {
			unlink((string)$file);
		}

		rmdir($this->dir);
	}//end tearDown()

	/**
	 * @return void
	 */
	public function testTheShippedRecordingsAnswerTheirQuery(): void {
		$body = (new EdurepFixtureTransport())->fetch(['query' => 'betoog AND lom.general.language=nl']);
		$this->assertSame((string)file_get_contents(__DIR__ . '/../../../fixtures/edurep/betoog-nl-facets.xml'), $body);
	}//end testTheShippedRecordingsAnswerTheirQuery()

	/**
	 * @return void
	 */
	public function testAnUnrecordedQueryIsUnavailable(): void {
		$this->expectException(SourceUnavailableException::class);
		(new EdurepFixtureTransport())->fetch(['query' => 'nothing was recorded for this']);
	}//end testAnUnrecordedQueryIsUnavailable()

	/**
	 * @return void
	 */
	public function testAMissingQueryIsUnavailable(): void {
		$this->expectException(SourceUnavailableException::class);
		(new EdurepFixtureTransport())->fetch([]);
	}//end testAMissingQueryIsUnavailable()

	/**
	 * @return void
	 */
	public function testAnIndexEntryCannotLeaveTheDirectory(): void {
		file_put_contents(sys_get_temp_dir() . '/edurep-outside.xml', 'outside');
		file_put_contents($this->dir . '/index.json', '{"responses":{"q":"../edurep-outside.xml"}}');

		try {
			$this->expectException(SourceUnavailableException::class);
			(new EdurepFixtureTransport(directory: $this->dir))->fetch(['query' => 'q']);
		} finally {
			unlink(sys_get_temp_dir() . '/edurep-outside.xml');
		}
	}//end testAnIndexEntryCannotLeaveTheDirectory()

	/**
	 * @return void
	 */
	public function testARecordedFileThatIsGoneIsUnavailable(): void {
		file_put_contents($this->dir . '/index.json', '{"responses":{"q":"gone.xml"}}');
		$this->expectException(SourceUnavailableException::class);
		(new EdurepFixtureTransport(directory: $this->dir))->fetch(['query' => 'q']);
	}//end testARecordedFileThatIsGoneIsUnavailable()

	/**
	 * @return void
	 */
	public function testAnIndexWithoutResponsesOrNoIndexAnswersNothing(): void {
		file_put_contents($this->dir . '/a.xml', 'a');
		file_put_contents($this->dir . '/index.json', '{"responses":"a.xml"}');
		$transport = new EdurepFixtureTransport(directory: $this->dir);
		try {
			$transport->fetch(['query' => 'q']);
			$this->fail('An index without a responses map answers nothing');
		} catch (SourceUnavailableException) {
			unlink($this->dir . '/index.json');
		}

		$this->expectException(SourceUnavailableException::class);
		$transport->fetch(['query' => 'q']);
	}//end testAnIndexWithoutResponsesOrNoIndexAnswersNothing()

	/**
	 * @return void
	 */
	public function testNonStringEntriesAreIgnoredAndStringOnesAnswer(): void {
		file_put_contents($this->dir . '/a.xml', 'recorded a');
		file_put_contents($this->dir . '/index.json', '{"responses":{"q":"a.xml","n":5}}');
		$transport = new EdurepFixtureTransport(directory: $this->dir);

		$this->assertSame('recorded a', $transport->fetch(['query' => 'q']));
		$this->assertSame('2026-11-05T12:00:00+00:00', $transport->lastSuccess());
		$this->expectException(SourceUnavailableException::class);
		$transport->fetch(['query' => 'n']);
	}//end testNonStringEntriesAreIgnoredAndStringOnesAnswer()
}//end class
