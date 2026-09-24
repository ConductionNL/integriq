<?php

/**
 * The contract every migration source binds to.
 *
 * @category Contract
 * @package  OCA\Integriq\Migration
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

namespace OCA\Integriq\Migration;

/**
 * An adapter reads. It never writes to the system it reads, and it never
 * writes to the target: OpenRegister's import engine does that.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-migration-source-is-an-adapter-behind-one-contract-req-msa-001
 */
interface MigrationSourceAdapterInterface {
	/**
	 * The source id a migration names.
	 *
	 * @return string Source id.
	 */
	public function id(): string;

	/**
	 * What this adapter can yield, before any read is attempted.
	 *
	 * @return array{id:string,label:string,kinds:array<int,array{kind:string,label:string,identifier:?string,stableIdentifier:bool}>}
	 */
	public function describe(): array;

	/**
	 * How many records of one kind the source holds, and whether the count is
	 * a complete answer.
	 *
	 * @param string $kind The record kind.
	 * @param array<string,mixed> $config The migration's configuration.
	 *
	 * @return array{count:int,complete:bool} The count and its completeness.
	 */
	public function count(string $kind, array $config = []): array;

	/**
	 * Every record of one kind.
	 *
	 * @param string $kind The record kind.
	 * @param array<string,mixed> $config The migration's configuration.
	 *
	 * @return iterable<MigrationRecord> The records.
	 */
	public function read(string $kind, array $config = []): iterable;

	/**
	 * A bounded sample of one kind, for a preview.
	 *
	 * @param string $kind The record kind.
	 * @param array<string,mixed> $config The migration's configuration.
	 * @param int $limit How many records at most.
	 *
	 * @return array<int,MigrationRecord> The sample.
	 */
	public function sample(string $kind, array $config = [], int $limit = 5): array;
}//end interface
