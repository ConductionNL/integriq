<?php

/**
 * Unit tests for ConfigurationDirectoryCodec.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
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

namespace OCA\Integriq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Integriq\Service\ConfigurationDirectoryCodec;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Files are stable, redacted, one per entity, and join back into the import document.
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
 */
class ConfigurationDirectoryCodecTest extends TestCase {

	/**
	 * The codec under test.
	 *
	 * @var ConfigurationDirectoryCodec
	 */
	private ConfigurationDirectoryCodec $codec;

	/**
	 * A temporary directory, removed after each test.
	 *
	 * @var string
	 */
	private string $directory = '';

	/**
	 * Set up the codec.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->codec = new ConfigurationDirectoryCodec(sensitiveFields: new SensitiveFieldRegistry());

	}//end setUp()

	/**
	 * Remove the temporary directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->directory !== '' && is_dir($this->directory) === true) {
			foreach (array_reverse($this->tree(directory: $this->directory)) as $path) {
				is_dir($path) === true ? rmdir($path) : unlink($path);
			}

			rmdir($this->directory);
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * Every path below a directory, parents first.
	 *
	 * @param string $directory The directory.
	 *
	 * @return list<string>
	 */
	private function tree(string $directory): array {
		$paths = [];
		foreach ((scandir($directory) ?: []) as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}

			$paths[] = $directory.'/'.$name;
			if (is_dir($directory.'/'.$name) === true) {
				$paths = array_merge($paths, $this->tree(directory: $directory.'/'.$name));
			}
		}

		return $paths;

	}//end tree()

	/**
	 * A fresh temporary directory.
	 *
	 * @return string
	 */
	private function temporaryDirectory(): string {
		$this->directory = sys_get_temp_dir().'/integriq-codec-'.bin2hex(random_bytes(6));
		mkdir($this->directory);

		return $this->directory;

	}//end temporaryDirectory()

	/**
	 * A configuration document in the export's grouped shape.
	 *
	 * @param string $rule The rule of the publication mapping.
	 *
	 * @return array<string, mixed>
	 */
	private static function document(string $rule='title'): array {
		return [
			'components' => [
				'sources' => [
					'api' => [
						[
							'slug' => 'brp',
							'name' => 'BRP',
							'uuid' => '9f1e',
							'location' => 'https://brp.example',
							'password' => 'hunter2',
							'updated' => '2026-11-14T10:00:00+00:00',
						],
						[
							'slug' => 'kvk',
							'name' => 'KVK',
							'configuration' => ['authentication' => ['credentialRef' => ['credentialId' => '550e8400-e29b-41d4-a716-446655440000']]],
							'lastRun' => '2026-11-14T09:00:00+00:00',
						],
					],
				],
				'mappings' => [
					'mapping' => [
						[
							'slug' => 'woo-index-publication',
							'name' => 'Woo index',
							'mapping' => ['title' => '{{ '.$rule.' }}', 'author' => '{{ owner }}'],
							'created' => '2026-11-01T00:00:00+00:00',
						],
						['slug' => 'zaak', 'name' => 'Zaak', 'mapping' => ['id' => '{{ id }}']],
					],
				],
			],
		];

	}//end document()

	/**
	 * Decode one file of a split.
	 *
	 * @param array  $split The split.
	 * @param string $path  The path.
	 *
	 * @return array<string, mixed>
	 */
	private static function file(array $split, string $path): array {
		return json_decode($split['files'][$path], true);

	}//end file()

	/**
	 * One file per entity at `<type>/<slug>.json` and an index listing them.
	 *
	 * @return void
	 */
	public function testSplitWritesOneFilePerEntityAndAnIndex(): void {
		$split = $this->codec->split(document: self::document(), configuration: 'woo');

		$this->assertSame(
			[
				'integriq-configuration.json',
				'mappings/woo-index-publication.json',
				'mappings/zaak.json',
				'sources/brp.json',
				'sources/kvk.json',
			],
			array_keys($split['files'])
		);
		$this->assertSame(
			[
				'configuration' => 'woo',
				'files' => [
					'sources' => ['sources/brp.json', 'sources/kvk.json'],
					'mappings' => ['mappings/woo-index-publication.json', 'mappings/zaak.json'],
					'rules' => [],
					'endpoints' => [],
					'synchronizations' => [],
					'jobs' => [],
				],
				'format' => 1,
			],
			self::file(split: $split, path: 'integriq-configuration.json')
		);
		$this->assertSame([], $split['warnings']);

	}//end testSplitWritesOneFilePerEntityAndAnIndex()

	/**
	 * Keys are sorted at every depth, volatile fields left out, and the JSON ends with a newline.
	 *
	 * @return void
	 */
	public function testFilesAreSortedWithoutVolatileFields(): void {
		$split = $this->codec->split(document: self::document(), configuration: 'woo');

		$mapping = $split['files']['mappings/woo-index-publication.json'];
		$this->assertSame(
			"{\n    \"mapping\": {\n        \"author\": \"{{ owner }}\",\n        \"title\": \"{{ title }}\"\n    },\n"
				."    \"name\": \"Woo index\",\n    \"slug\": \"woo-index-publication\"\n}\n",
			$mapping
		);
		$this->assertSame(['location', 'name', 'password', 'slug'], array_keys(self::file(split: $split, path: 'sources/brp.json')));

	}//end testFilesAreSortedWithoutVolatileFields()

	/**
	 * An inline password is redacted, a credentialRef is kept, and no secret value is written.
	 *
	 * @return void
	 */
	public function testAnInlinePasswordIsRedactedAndACredentialRefKept(): void {
		$split = $this->codec->split(document: self::document(), configuration: 'woo');

		$this->assertSame(SensitiveFieldRegistry::PLACEHOLDER, self::file(split: $split, path: 'sources/brp.json')['password']);
		$this->assertSame(
			['credentialId' => '550e8400-e29b-41d4-a716-446655440000'],
			self::file(split: $split, path: 'sources/kvk.json')['configuration']['authentication']['credentialRef']
		);
		$this->assertStringNotContainsString('hunter2', implode('', $split['files']));

	}//end testAnInlinePasswordIsRedactedAndACredentialRefKept()

	/**
	 * Splitting twice gives the same bytes, and an edited mapping changes only its own file.
	 *
	 * @return void
	 */
	public function testAnEditChangesOnlyTheEditedFile(): void {
		$first  = $this->codec->split(document: self::document(), configuration: 'woo')['files'];
		$again  = $this->codec->split(document: self::document(), configuration: 'woo')['files'];
		$edited = $this->codec->split(document: self::document(rule: 'subject'), configuration: 'woo')['files'];

		$this->assertSame($first, $again);
		$this->assertSame(['mappings/woo-index-publication.json'], array_keys(array_diff_assoc($edited, $first)));

	}//end testAnEditChangesOnlyTheEditedFile()

	/**
	 * Slug-keyed buckets split too; an entity without a slug is written under its key with a warning.
	 *
	 * @return void
	 */
	public function testAnEntityWithoutASlugIsWrittenUnderItsKeyWithAWarning(): void {
		$split = $this->codec->split(
			document: ['components' => ['rules' => ['3c2f-uuid' => ['name' => 'Rule'], 'ok' => ['slug' => 'ok']]]],
			configuration: 'c'
		);

		$this->assertArrayHasKey('rules/3c2f-uuid.json', $split['files']);
		$this->assertArrayHasKey('rules/ok.json', $split['files']);
		$this->assertSame(['rules "3c2f-uuid" has no slug; its file name will differ on another instance.'], $split['warnings']);

	}//end testAnEntityWithoutASlugIsWrittenUnderItsKeyWithAWarning()

	/**
	 * A slug that is not a file name is made one, and two entities on one file are refused.
	 *
	 * @return void
	 */
	public function testSlugsBecomeSafeFileNamesAndCollisionsAreRefused(): void {
		$split = $this->codec->split(document: ['components' => ['jobs' => ['x' => ['slug' => '../etc/pass wd']]]], configuration: 'c');
		$this->assertArrayHasKey('jobs/-etc-pass-wd.json', $split['files']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Two jobs are written to jobs/a-b.json; give them distinct slugs.');
		$this->codec->split(document: ['components' => ['jobs' => ['x' => ['slug' => 'a b'], 'y' => ['slug' => 'a/b']]]], configuration: 'c');

	}//end testSlugsBecomeSafeFileNamesAndCollisionsAreRefused()

	/**
	 * Join gives the slug-keyed document the import and its preview take.
	 *
	 * @return void
	 */
	public function testJoinGivesTheImportDocument(): void {
		$split    = $this->codec->split(document: self::document(), configuration: 'woo');
		$document = $this->codec->join(files: $split['files']);

		$this->assertSame(['sources', 'mappings', 'rules', 'endpoints', 'synchronizations', 'jobs'], array_keys($document['components']));
		$this->assertSame(['brp', 'kvk'], array_keys($document['components']['sources']));
		$this->assertSame(['woo-index-publication', 'zaak'], array_keys($document['components']['mappings']));
		$this->assertSame(['mapping' => ['id' => '{{ id }}'], 'name' => 'Zaak', 'slug' => 'zaak'], $document['components']['mappings']['zaak']);

		// Splitting what was joined gives the same files: the round trip has no drift.
		$this->assertSame($split['files'], $this->codec->split(document: $document, configuration: 'woo')['files']);

	}//end testJoinGivesTheImportDocument()

	/**
	 * Join refuses a missing index, a missing listed file, a path outside the layout and a non-object.
	 *
	 * @return void
	 */
	public function testJoinRefusesWhatIsNotTheLayout(): void {
		$index = fn (array $files): string => json_encode(['files' => $files]);
		$cases = [
			[[], 'The directory has no integriq-configuration.json.'],
			[['integriq-configuration.json' => $index(['sources' => ['sources/a.json']])], 'The index lists sources/a.json, which is not in the directory.'],
			[
				['integriq-configuration.json' => $index(['sources' => ['../secrets.json']]), '../secrets.json' => '{}'],
				'The index lists "../secrets.json" under sources, outside sources/<name>.json.',
			],
			[
				['integriq-configuration.json' => $index(['jobs' => ['sources/a.json']]), 'sources/a.json' => '{}'],
				'The index lists "sources/a.json" under jobs, outside jobs/<name>.json.',
			],
			[['integriq-configuration.json' => $index(['jobs' => ['jobs/a.json']]), 'jobs/a.json' => '[1]'], 'jobs/a.json is not a JSON object.'],
		];

		foreach ($cases as [$files, $message]) {
			try {
				$this->codec->join(files: $files);
				$this->fail('Expected a refusal: '.$message);
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

	}//end testJoinRefusesWhatIsNotTheLayout()

	/**
	 * Writing a directory twice changes nothing; an edit changes one file and a removed entity's file goes.
	 *
	 * @return void
	 */
	public function testWriteDirectoryReportsOnlyChangedFiles(): void {
		$directory = $this->temporaryDirectory();
		$first     = $this->codec->split(document: self::document(), configuration: 'woo')['files'];

		$this->assertSame(array_keys($first), $this->codec->writeDirectory(directory: $directory, files: $first));
		$this->assertSame([], $this->codec->writeDirectory(directory: $directory, files: $first));
		$this->assertSame($first['sources/brp.json'], file_get_contents($directory.'/sources/brp.json'));

		$edited = $this->codec->split(document: self::document(rule: 'subject'), configuration: 'woo')['files'];
		$this->assertSame(['mappings/woo-index-publication.json'], $this->codec->writeDirectory(directory: $directory, files: $edited));

		unset($edited['mappings/zaak.json']);
		$this->assertSame(['mappings/zaak.json'], $this->codec->writeDirectory(directory: $directory, files: $edited));
		$this->assertFileDoesNotExist($directory.'/mappings/zaak.json');

	}//end testWriteDirectoryReportsOnlyChangedFiles()

	/**
	 * Reading a written directory gives the files back; without an index it is refused.
	 *
	 * @return void
	 */
	public function testReadDirectoryGivesTheWrittenFilesBack(): void {
		$directory = $this->temporaryDirectory();
		$files     = $this->codec->split(document: self::document(), configuration: 'woo')['files'];
		$this->codec->writeDirectory(directory: $directory, files: $files);
		file_put_contents($directory.'/README.md', 'not read');

		$this->assertSame($files, $this->codec->readDirectory(directory: $directory.'/'));

		unlink($directory.'/integriq-configuration.json');
		$this->expectException(InvalidArgumentException::class);
		$this->codec->readDirectory(directory: $directory);

	}//end testReadDirectoryGivesTheWrittenFilesBack()

}//end class
