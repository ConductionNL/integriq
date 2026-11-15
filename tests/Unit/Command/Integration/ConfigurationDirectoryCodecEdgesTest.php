<?php

/**
 * Unit tests for the edge cases of ConfigurationDirectoryCodec.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/platform-integrations-as-code/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command\Integration;

use InvalidArgumentException;
use OCA\Integriq\Command\Integration\ConfigurationDirectoryCodec;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Malformed buckets are skipped, unusable slugs still get a file name, and
 * an index entry that is not a path is refused.
 *
 * @covers \OCA\Integriq\Command\Integration\ConfigurationDirectoryCodec
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
 */
class ConfigurationDirectoryCodecEdgesTest extends TestCase {

	/**
	 * The codec under test.
	 *
	 * @var ConfigurationDirectoryCodec
	 */
	private ConfigurationDirectoryCodec $codec;

	/**
	 * Build the codec.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->codec = new ConfigurationDirectoryCodec(sensitiveFields: new SensitiveFieldRegistry());

	}//end setUp()

	/**
	 * A component that is not a list, and scalar entries inside one, write no files.
	 *
	 * @return void
	 */
	public function testMalformedBucketsAreSkipped(): void {
		$split = $this->codec->split(
			document: [
				'components' => [
					'sources'  => 'not-a-bucket',
					'mappings' => ['loose' => 'scalar', 'kept' => ['slug' => 'kept-mapping']],
				],
			],
			configuration: 'c'
		);

		$this->assertSame([ConfigurationDirectoryCodec::INDEX_FILE, 'mappings/kept-mapping.json'], array_keys($split['files']));
		$this->assertSame([], $split['warnings']);
		$index = json_decode($split['files'][ConfigurationDirectoryCodec::INDEX_FILE], true);
		$this->assertSame([], $index['files']['sources']);
		$this->assertSame(['mappings/kept-mapping.json'], $index['files']['mappings']);

	}//end testMalformedBucketsAreSkipped()

	/**
	 * A slug of only dots and separators still yields a file name, and a leading dot is dropped.
	 *
	 * @return void
	 */
	public function testUnusableSlugsGetASafeFileName(): void {
		$split = $this->codec->split(
			document: ['components' => ['rules' => [['slug' => '...'], ['slug' => '.hidden rule']]]],
			configuration: 'c'
		);

		$this->assertSame(
			[ConfigurationDirectoryCodec::INDEX_FILE, 'rules/-.json', 'rules/hidden-rule.json'],
			array_keys($split['files'])
		);

	}//end testUnusableSlugsGetASafeFileName()

	/**
	 * An index entry that is not a scalar is refused as an empty path.
	 *
	 * @return void
	 */
	public function testANonScalarIndexEntryIsRefused(): void {
		$index = json_encode(['configuration' => 'c', 'files' => ['jobs' => [['nested']]], 'format' => 1]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The index lists "" under jobs, outside jobs/<name>.json.');
		$this->codec->join(files: [ConfigurationDirectoryCodec::INDEX_FILE => $index]);

	}//end testANonScalarIndexEntryIsRefused()

	/**
	 * Writing over a path that is a file where a directory is needed fails loudly.
	 *
	 * @return void
	 */
	public function testAnUncreatableDirectoryIsReported(): void {
		$blocker = tempnam(sys_get_temp_dir(), 'integriq-codec-');
		try {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('Cannot create directory '.$blocker.'/jobs.');
			@$this->codec->writeDirectory(directory: $blocker, files: ['jobs/a.json' => "{}\n"]);
		} finally {
			unlink($blocker);
		}

	}//end testAnUncreatableDirectoryIsReported()

}//end class
