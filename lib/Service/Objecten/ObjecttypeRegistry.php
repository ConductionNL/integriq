<?php

/**
 * Which register and schema an objecttype stands for — declared, never guessed.
 *
 * 🔴 THE MAPPING IS NOT INFERRED FROM A NAME, and the requirement says so in
 * those words for a reason a single instance makes concrete: two registers can
 * each hold a schema called `melding`. A name-based lookup picks one of them,
 * and which one depends on iteration order — so a counterparty writing to a
 * national objecttype uuid lands in whichever register happened to be first,
 * and nothing anywhere says which.
 *
 * 🔴 AND THE UUID IS THE PUBLISHED IDENTITY, so it survives a reseed. A
 * register reseed changes internal ids; a VNG objecttype uuid is what other
 * suppliers in the landscape have written down. Deriving the uuid from the
 * register or schema id would break every counterparty on the day an operator
 * reimported a descriptor — a data-integration outage with no error in it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Objecten;

/**
 * The declared objecttype mappings, keyed by their published uuid.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
class ObjecttypeRegistry {

	/**
	 * The keys a declaration must carry.
	 *
	 * @var array<int, string>
	 */
	public const REQUIRED = ['uuid', 'name', 'register', 'schema'];

	/**
	 * The declarations, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $byUuid = [];

	/**
	 * Declarations refused at load, with the reason.
	 *
	 * Kept rather than dropped: an objecttype that silently failed to register
	 * answers 404 to a counterparty who was told it exists, and the operator
	 * has nothing to look at.
	 *
	 * @var array<int, array{declaration: mixed, reason: string}>
	 */
	private array $refused = [];

	/**
	 * Load a set of declarations.
	 *
	 * @param array<int, mixed> $declarations The declared objecttypes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function load(array $declarations): void {
		foreach ($declarations as $declaration) {
			$reason = $this->refusalFor(declaration: $declaration);
			if ($reason !== null) {
				$this->refused[] = ['declaration' => $declaration, 'reason' => $reason];
				continue;
			}

			$uuid = trim((string)$declaration['uuid']);
			if (array_key_exists($uuid, $this->byUuid) === true) {
				// 🔴 FIRST WINS, and the second is REFUSED rather than
				// overwriting. Two declarations for one published uuid is a
				// configuration mistake, and resolving it by "last loaded"
				// makes which register a counterparty writes to depend on file
				// order.
				$this->refused[] = [
					'declaration' => $declaration,
					'reason' => sprintf('uuid "%s" is already declared; the second declaration is ignored', $uuid),
				];
				continue;
			}

			$this->byUuid[$uuid] = [
				'uuid' => $uuid,
				'name' => trim((string)$declaration['name']),
				'register' => trim((string)$declaration['register']),
				'schema' => trim((string)$declaration['schema']),
				'versions' => $this->versionsOf(declaration: $declaration),
			];
		}
	}//end load()

	/**
	 * Why a declaration may not be loaded, or null.
	 *
	 * @param mixed $declaration The declaration.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function refusalFor(mixed $declaration): ?string {
		if (is_array($declaration) === false) {
			return 'an objecttype declaration must be an object';
		}

		foreach (self::REQUIRED as $key) {
			if (array_key_exists($key, $declaration) === false || trim((string)$declaration[$key]) === '') {
				return sprintf(
					'an objecttype declaration must name its "%s"; the mapping is declared, never inferred from a name',
					$key
				);
			}
		}

		return null;
	}//end refusalFor()

	/**
	 * The declaration for one published uuid, or null when there is none.
	 *
	 * @param string $uuid The objecttype uuid.
	 *
	 * @return array<string, mixed>|null The declaration.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function find(string $uuid): ?array {
		return ($this->byUuid[trim($uuid)] ?? null);
	}//end find()

	/**
	 * Every declared objecttype.
	 *
	 * @return array<int, array<string, mixed>> The declarations.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function all(): array {
		return array_values($this->byUuid);
	}//end all()

	/**
	 * Whether an objecttype allows a schema version.
	 *
	 * 🔑 AN EMPTY VERSION LIST MEANS EVERY VERSION, and that is a declared
	 * default rather than an accident: an objecttype that pins no versions is
	 * the common case, and refusing every read for one would make the list a
	 * required field the requirement does not make required. A list that IS
	 * present is closed — an unlisted version is a 404, never the newest one,
	 * because answering with a newer version is answering a different question
	 * than the consumer asked.
	 *
	 * @param string $uuid    The objecttype.
	 * @param string $version The version asked for.
	 *
	 * @return bool True when it may be served.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function allowsVersion(string $uuid, string $version): bool {
		$declaration = $this->find(uuid: $uuid);
		if ($declaration === null) {
			return false;
		}

		if ($declaration['versions'] === []) {
			return true;
		}

		return in_array(trim($version), $declaration['versions'], true);
	}//end allowsVersion()

	/**
	 * Declarations that were refused, with their reasons.
	 *
	 * @return array<int, array{declaration: mixed, reason: string}> The refusals.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function refused(): array {
		return $this->refused;
	}//end refused()

	/**
	 * The allowed versions of a declaration, as a list of strings.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 *
	 * @return array<int, string> The versions.
	 */
	private function versionsOf(array $declaration): array {
		$versions = ($declaration['versions'] ?? []);
		if (is_array($versions) === false) {
			return [];
		}

		$clean = [];
		foreach ($versions as $version) {
			$version = trim((string)$version);
			if ($version !== '') {
				$clean[] = $version;
			}
		}

		return $clean;
	}//end versionsOf()
}//end class
