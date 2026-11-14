<?php

/**
 * Integriq Configuration Directory Codec.
 *
 * The directory form of a configuration export, for keeping a configuration
 * in git: one index file `integriq-configuration.json` plus one JSON file per
 * entity at `<type>/<slug>.json`. Keys are sorted, the JSON is pretty-printed
 * with a trailing newline and volatile fields (`id`, `uuid`, `created`,
 * `updated`, `dateCreated`, `dateModified`, `lastRun`, `nextRun`, `@self`)
 * are left out, so exporting an unchanged configuration twice writes the same
 * bytes and an edit to one entity changes one file. Secret-shaped values are
 * redacted through the shared SensitiveFieldRegistry; `credentialRef`
 * placeholders are kept. join() reads a directory back into the document
 * importConfiguration() and the import preview take.
 *
 * @category Command
 * @package  OCA\Integriq\Command\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Command\Integration;

use InvalidArgumentException;
use OCA\Integriq\Service\Security\SecretRedactorInterface;
use RuntimeException;

/**
 * Splits a configuration document into stable files and joins them back.
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
 */
class ConfigurationDirectoryCodec {

	/**
	 * The index file at the root of the directory.
	 */
	public const INDEX_FILE = 'integriq-configuration.json';

	/**
	 * The version of the directory layout.
	 */
	public const FORMAT = 1;

	/**
	 * The components of a configuration, each a subdirectory, in import order.
	 *
	 * @var list<string>
	 */
	public const COMPONENTS = ['sources', 'mappings', 'rules', 'endpoints', 'synchronizations', 'jobs'];

	/**
	 * Fields that differ per instance or per run, left out of the files.
	 *
	 * @var list<string>
	 */
	public const VOLATILE_FIELDS = ['@self', 'id', 'uuid', 'created', 'updated', 'dateCreated', 'dateModified', 'lastRun', 'nextRun'];

	/**
	 * Constructor.
	 *
	 * @param SecretRedactorInterface $sensitiveFields Redacts secret-shaped values.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SecretRedactorInterface $sensitiveFields,
	) {
	}//end __construct()

	/**
	 * Split a configuration document into files.
	 *
	 * Takes the document exportConfiguration() returns, whose components hold
	 * their entities either by slug or grouped in lists.
	 *
	 * @param array<string, mixed> $document      The configuration document, with `components`.
	 * @param string               $configuration The configuration the document was exported from.
	 *
	 * @return array{files: array<string, string>, warnings: list<string>} The file contents by
	 *         relative path, sorted, and a warning per entity that has no slug.
	 *
	 * @throws InvalidArgumentException When two entities of one component map onto one file.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
	 */
	public function split(array $document, string $configuration): array {
		$files    = [];
		$warnings = [];
		$index    = [];
		foreach (self::COMPONENTS as $component) {
			$index[$component] = [];
			foreach ($this->entitiesOf(bucket: ($document['components'][$component] ?? [])) as [$key, $entity]) {
				$slug = (string) ($entity['slug'] ?? '');
				if ($slug === '') {
					$slug = $key;
					$warnings[] = sprintf('%s "%s" has no slug; its file name will differ on another instance.', $component, $key);
				}

				$path = $component.'/'.$this->fileName(slug: $slug).'.json';
				if (isset($files[$path]) === true) {
					throw new InvalidArgumentException(sprintf('Two %s are written to %s; give them distinct slugs.', $component, $path));
				}

				$files[$path] = $this->encode(data: $this->stable(entity: $entity));
				$index[$component][] = $path;
			}

			sort($index[$component], SORT_STRING);
		}

		$files[self::INDEX_FILE] = $this->encode(
			data: ['configuration' => $configuration, 'files' => $index, 'format' => self::FORMAT]
		);
		ksort($files, SORT_STRING);

		return ['files' => $files, 'warnings' => $warnings];
	}//end split()

	/**
	 * Join files back into a configuration document.
	 *
	 * Only the files the index lists are read, and only at `<component>/<name>.json`.
	 * Each entity is keyed by its slug, or else by its file name.
	 *
	 * @param array<string, string> $files The file contents by relative path, the index among them.
	 *
	 * @return array{components: array<string, array<string, array<string, mixed>>>} The document.
	 *
	 * @throws InvalidArgumentException When the index is missing or a listed file is missing, outside the layout or no JSON object.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-directory-imports-with-a-preview-and-only-when-confirmed-req-iac-003
	 */
	public function join(array $files): array {
		if (isset($files[self::INDEX_FILE]) === false) {
			throw new InvalidArgumentException(sprintf('The directory has no %s.', self::INDEX_FILE));
		}

		$index      = $this->decode(json: $files[self::INDEX_FILE], path: self::INDEX_FILE);
		$components = [];
		foreach (self::COMPONENTS as $component) {
			$components[$component] = [];
			foreach ((array) ($index['files'][$component] ?? []) as $path) {
				$path = $this->listedPath(component: $component, path: $path);
				if (isset($files[$path]) === false) {
					throw new InvalidArgumentException(sprintf('The index lists %s, which is not in the directory.', $path));
				}

				$entity = $this->decode(json: $files[$path], path: $path);
				$slug   = (string) ($entity['slug'] ?? basename($path, '.json'));
				$components[$component][$slug] = $entity;
			}
		}

		return ['components' => $components];
	}//end join()

	/**
	 * Write files into a directory, removing the entity files a previous export left that are gone now.
	 *
	 * @param string                $directory The directory; created when missing.
	 * @param array<string, string> $files     The file contents by relative path, as split() returns them.
	 *
	 * @return list<string> The relative paths whose content changed or that were removed, sorted.
	 *
	 * @throws RuntimeException When the directory or a file cannot be written.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
	 */
	public function writeDirectory(string $directory, array $files): array {
		$directory = rtrim($directory, '/');
		$changed   = $this->removeStale(directory: $directory, files: $files);
		foreach ($files as $path => $content) {
			if ($this->writeFile(target: $directory.'/'.$path, content: $content) === true) {
				$changed[] = $path;
			}
		}

		sort($changed, SORT_STRING);

		return $changed;
	}//end writeDirectory()

	/**
	 * Remove the entity files a previous export left that are not among the new files.
	 *
	 * @param string                $directory The directory, without a trailing slash.
	 * @param array<string, string> $files     The new file contents by relative path.
	 *
	 * @return list<string> The relative paths removed.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
	 */
	private function removeStale(string $directory, array $files): array {
		$removed = [];
		foreach (self::COMPONENTS as $component) {
			foreach ($this->jsonFiles(directory: $directory.'/'.$component) as $existing) {
				$path = $component.'/'.basename($existing);
				if (isset($files[$path]) === false) {
					unlink($existing);
					$removed[] = $path;
				}
			}
		}

		return $removed;
	}//end removeStale()

	/**
	 * Write one file unless it already holds this content, creating its directory when missing.
	 *
	 * @param string $target  The absolute file path.
	 * @param string $content The file content.
	 *
	 * @return boolean Whether the file was written.
	 *
	 * @throws RuntimeException When the directory or the file cannot be written.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
	 */
	private function writeFile(string $target, string $content): bool {
		if (is_dir(dirname($target)) === false && mkdir(dirname($target), 0775, true) === false) {
			throw new RuntimeException(sprintf('Cannot create directory %s.', dirname($target)));
		}

		if (is_file($target) === true && file_get_contents($target) === $content) {
			return false;
		}

		if (file_put_contents($target, $content) === false) {
			throw new RuntimeException(sprintf('Cannot write %s.', $target));
		}

		return true;
	}//end writeFile()

	/**
	 * Read the index and the entity files of a directory.
	 *
	 * @param string $directory The directory.
	 *
	 * @return array<string, string> The file contents by relative path.
	 *
	 * @throws InvalidArgumentException When the directory has no index.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-directory-imports-with-a-preview-and-only-when-confirmed-req-iac-003
	 */
	public function readDirectory(string $directory): array {
		$directory = rtrim($directory, '/');
		if (is_file($directory.'/'.self::INDEX_FILE) === false) {
			throw new InvalidArgumentException(sprintf('%s has no %s.', $directory, self::INDEX_FILE));
		}

		$files = [self::INDEX_FILE => (string) file_get_contents($directory.'/'.self::INDEX_FILE)];
		foreach (self::COMPONENTS as $component) {
			foreach ($this->jsonFiles(directory: $directory.'/'.$component) as $file) {
				$files[$component.'/'.basename($file)] = (string) file_get_contents($file);
			}
		}

		ksort($files, SORT_STRING);

		return $files;
	}//end readDirectory()

	/**
	 * The `.json` files directly in a directory, sorted; none when it does not exist.
	 *
	 * @param string $directory The directory.
	 *
	 * @return list<string>
	 */
	private function jsonFiles(string $directory): array {
		$files = glob($directory.'/*.json');
		if ($files === false) {
			return [];
		}

		sort($files, SORT_STRING);

		return $files;
	}//end jsonFiles()

	/**
	 * The entities of a component bucket as `[key, entity]` pairs.
	 *
	 * A bucket holds entities by slug, or lists of entities grouped by kind.
	 *
	 * @param mixed $bucket The component's entry in the document.
	 *
	 * @return list<array{0: string, 1: array<string, mixed>}>
	 */
	private function entitiesOf(mixed $bucket): array {
		if (is_array($bucket) === false) {
			return [];
		}

		$entities = [];
		foreach ($bucket as $key => $value) {
			if (is_array($value) === false) {
				continue;
			}

			if ($value !== [] && array_is_list($value) === true) {
				foreach ($value as $position => $entity) {
					if (is_array($entity) === true) {
						$entities[] = [(string) ($entity['slug'] ?? $key.'-'.$position), $entity];
					}
				}

				continue;
			}

			$entities[] = [(string) $key, $value];
		}

		return $entities;
	}//end entitiesOf()

	/**
	 * An entity without its volatile fields, redacted, with its keys sorted.
	 *
	 * @param array<string, mixed> $entity The exported entity.
	 *
	 * @return array<string, mixed>
	 */
	private function stable(array $entity): array {
		foreach (self::VOLATILE_FIELDS as $field) {
			unset($entity[$field]);
		}

		return $this->sorted(value: $this->sensitiveFields->redactConfiguration(data: $entity));
	}//end stable()

	/**
	 * A value with the keys of every associative array in it sorted; lists keep their order.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function sorted(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$value = array_map(fn (mixed $item): mixed => $this->sorted(value: $item), $value);
		if (array_is_list($value) === false) {
			ksort($value, SORT_STRING);
		}

		return $value;
	}//end sorted()

	/**
	 * A slug as a file name: anything but letters, digits, dot, dash and underscore becomes a dash.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string
	 */
	private function fileName(string $slug): string {
		$name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $slug);
		$name = ltrim($name, '.');
		if ($name === '') {
			return '-';
		}

		return $name;
	}//end fileName()

	/**
	 * Pretty-printed JSON with a trailing newline.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return string
	 */
	private function encode(array $data): string {
		return json_encode($data, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))."\n";
	}//end encode()

	/**
	 * A file's JSON object.
	 *
	 * @param string $json The file content.
	 * @param string $path The file, for the message.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When the content is no JSON object.
	 */
	private function decode(string $json, string $path): array {
		$data = json_decode($json, true);
		if (is_array($data) === false || ($data !== [] && array_is_list($data) === true)) {
			throw new InvalidArgumentException(sprintf('%s is not a JSON object.', $path));
		}

		return $data;
	}//end decode()

	/**
	 * A path the index lists, checked to be `<component>/<name>.json`.
	 *
	 * @param string $component The component the index lists it under.
	 * @param mixed  $path      The listed path.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the path is outside that layout.
	 */
	private function listedPath(string $component, mixed $path): string {
		if (is_scalar($path) === false) {
			$path = '';
		}

		$path = (string) $path;
		if (preg_match('#^'.preg_quote($component, '#').'/[A-Za-z0-9_-][A-Za-z0-9._-]*\.json$#', $path) !== 1) {
			throw new InvalidArgumentException(sprintf('The index lists "%s" under %s, outside %s/<name>.json.', $path, $component, $component));
		}

		return $path;
	}//end listedPath()
}//end class
