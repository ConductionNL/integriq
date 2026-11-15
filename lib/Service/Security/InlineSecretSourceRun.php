<?php

/**
 * Integriq Inline Secret Source Run.
 *
 * One object while {@see InlineSecretMigrationExecutor} migrates its inline
 * secret fields: the broker that mints for it, its uuid and name, the
 * organisation and owner read from the raw entity, and the in-progress data a
 * successful field migration writes into. Built by the executor per object and
 * never handed out.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

/**
 * The object an inline secret migration is working on.
 *
 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
 */
final class InlineSecretSourceRun {

	/**
	 * The object's human-readable name (a label, never a secret).
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * The object's organisation UUID, trimmed; empty when it has none.
	 *
	 * @var string
	 */
	public string $organisation = '';

	/**
	 * The object's owner UID, trimmed.
	 *
	 * @var string
	 */
	public string $owner = '';

	/**
	 * The object's in-progress data; a field is written here only after its save persisted.
	 *
	 * @var array<string, mixed>
	 */
	public array $working = [];

	/**
	 * Constructor.
	 *
	 * @param object $broker The resolved credential broker.
	 * @param string $uuid The object UUID.
	 */
	public function __construct(
		public readonly object $broker,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
