<?php

/**
 * One watched folder: a synchronization on a `nextcloud-folder` source, with that source.
 *
 * @category Service
 * @package  OCA\Integriq\Service\WatchedFolder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\WatchedFolder;

/**
 * The pickup settings of a watched folder, read from the synchronization's
 * `sourceConfig` under the names design D6 gives them, with their defaults.
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */
final class WatchedFolder {

	/**
	 * Mark a handled file by moving it.
	 *
	 * @var string
	 */
	public const AFTER_MOVE = 'move';

	/**
	 * Mark a handled file with a system tag.
	 *
	 * @var string
	 */
	public const AFTER_TAG = 'tag';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $synchronization The synchronization.
	 * @param array<string, mixed> $source          Its `nextcloud-folder` source.
	 */
	public function __construct(
		public readonly array $synchronization,
		public readonly array $source,
	) {
	}//end __construct()

	/**
	 * The synchronization's id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function synchronizationId(): string {
		return (string)($this->synchronization['id'] ?? $this->synchronization['uuid'] ?? '');
	}//end synchronizationId()

	/**
	 * The shell pattern of the names it picks up.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function pattern(): string {
		return $this->text(key: 'pattern', default: '*');
	}//end pattern()

	/**
	 * How long a file must be left alone before it is picked up, in seconds.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function stableSeconds(): int {
		return max(0, (int)($this->config()['stableSeconds'] ?? 60));
	}//end stableSeconds()

	/**
	 * How a handled file is marked: `move` (the default) or `tag`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function after(): string {
		if ($this->text(key: 'after', default: self::AFTER_MOVE) === self::AFTER_TAG) {
			return self::AFTER_TAG;
		}

		return self::AFTER_MOVE;
	}//end after()

	/**
	 * Where a file goes after a safe write, relative to the watched folder.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function processedPath(): string {
		return $this->text(key: 'processedPath', default: 'processed');
	}//end processedPath()

	/**
	 * Where a file goes after a failed write, relative to the watched folder.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function failedPath(): string {
		return $this->text(key: 'failedPath', default: 'failed');
	}//end failedPath()

	/**
	 * Whether a dropped file goes to an intake app (`target: intake`) rather than through the mapping.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function isIntake(): bool {
		return $this->text(key: 'target', default: 'mapping') === 'intake';
	}//end isIntake()

	/**
	 * The app id that may accept a dropped file, for `target: intake`; '' when none is named.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function intakeApp(): string {
		return $this->text(key: 'intakeApp', default: '');
	}//end intakeApp()

	/**
	 * The id of the `nextcloud-folder` source.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function sourceId(): string {
		return (string)($this->source['id'] ?? $this->source['uuid'] ?? $this->synchronization['sourceId'] ?? '');
	}//end sourceId()

	/**
	 * The owner of the watched folder.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function ownerUid(): string {
		$configuration = ($this->source['configuration'] ?? []);
		if (is_array($configuration) === false) {
			return '';
		}

		return trim((string)($configuration['ownerUid'] ?? ''));
	}//end ownerUid()

	/**
	 * The mapping that turns a file into the target object, or '' for none.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function mappingId(): string {
		return trim((string)($this->synchronization['sourceTargetMapping'] ?? ''));
	}//end mappingId()

	/**
	 * The target register and schema, or null when `targetId` names no `register/schema`.
	 *
	 * @return array{register: string, schema: string}|null
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function target(): ?array {
		$parts = explode('/', trim((string)($this->synchronization['targetId'] ?? '')));
		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
			return null;
		}

		return ['register' => $parts[0], 'schema' => $parts[1]];
	}//end target()

	/**
	 * A text setting of the pickup configuration, or its default when empty.
	 *
	 * @param string $key     The setting.
	 * @param string $default The default.
	 *
	 * @return string
	 */
	private function text(string $key, string $default): string {
		$value = trim((string)($this->config()[$key] ?? ''));
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end text()

	/**
	 * The synchronization's pickup configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		$config = ($this->synchronization['sourceConfig'] ?? []);
		if (is_array($config) === false) {
			return [];
		}

		return $config;
	}//end config()
}//end class
