<?php

/**
 * Which environment variables an expression may read, and who said so.
 *
 * 🔴 WHAT THE ALLOWLIST ADMITS: EXACT KEYS, ONE AT A TIME, AND NOTHING ELSE.
 * No wildcard, no prefix pattern, no empty entry meaning all, no regular
 * expression, no case-insensitive match. Every one of those turns a list of
 * things somebody decided to expose into a rule that also exposes whatever is
 * added to the environment next year — and the environment of a Nextcloud
 * container holds the database password, the object-store credentials and the
 * instance secret. `DB_*` looks like a careful narrowing right up to the moment
 * somebody names a variable `DB_ROOT_PASSWORD`.
 *
 * 🔴 WHO MAY CHANGE IT: AN INSTANCE ADMINISTRATOR, AND NOBODY ELSE. Not a user
 * with a rights grant, not an app: this is the list that decides what an
 * expression language can read out of the process, so widening it is a
 * code-execution-adjacent act and it belongs to the person who administers the
 * instance. The controller enforces that; this class refuses to store a shape
 * that could widen at runtime, so even an administrator cannot write a rule
 * instead of a list.
 *
 * 🔑 AND THE LIST HOLDS KEYS, NEVER VALUES. It records who added each key and
 * when, so an auditor can ask why `BRP_BASE_URL` is readable; it never stores
 * or renders the value, because a surface that shows the value has published
 * the secret to everyone who can see the surface.
 *
 * @category Expression
 * @package  OCA\Integriq\Expression
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
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Expression;

use DateTimeImmutable;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The administered list of readable environment variables.
 *
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
 */
class EnvironmentAllowlist {

	/**
	 * The app the list is stored under.
	 *
	 * @var string
	 */
	public const APP_ID = 'integriq';

	/**
	 * The config key holding the list.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'expression_env_allowlist';

	/**
	 * The shape an environment variable name may take.
	 *
	 * Deliberately narrow: letters, digits and underscores, starting with a
	 * letter or an underscore, which is what a POSIX environment variable is.
	 * A name outside it is either a mistake or an attempt to smuggle a pattern
	 * past the wildcard check.
	 *
	 * @var string
	 */
	public const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig Where the list is stored.
	 * @param LoggerInterface $logger    The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Why a key may not be added, or null when it may.
	 *
	 * @param string $key The key.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function refusalFor(string $key): ?string {
		$candidate = trim($key);

		if ($candidate === '') {
			return 'An empty entry is refused: an empty allowlist entry reads as "everything", which is the opposite of a list.';
		}

		if (preg_match(self::KEY_PATTERN, $candidate) !== 1) {
			// The message names the wildcard case explicitly because that is
			// what somebody actually types when they mean "all the DB ones",
			// and a generic "invalid name" would read as a formatting quibble
			// rather than as a refusal of the idea.
			return sprintf(
				'"%s" is refused: every key must be listed by its exact name. A wildcard or a pattern would also admit '
				. 'whatever is added to the environment later, which is where the database password lives.',
				$candidate
			);
		}

		return null;
	}//end refusalFor()

	/**
	 * The listed keys, each with who added it and when.
	 *
	 * @return array<string, array{addedBy: string, addedAt: string}> The list.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function entries(): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			// 🔴 AN UNREADABLE LIST IS AN EMPTY LIST, never "allow everything".
			// A truncated JSON blob must narrow to nothing, so the failure is
			// an expression that stops resolving rather than one that starts
			// reading the whole environment.
			$this->logger->error(
				'[EnvironmentAllowlist] the stored allowlist could not be read; no environment variable resolves until it is repaired'
			);
			return [];
		}

		$entries = [];
		foreach ($decoded as $key => $entry) {
			$key = (string)$key;
			if ($this->refusalFor(key: $key) !== null || is_array($entry) === false) {
				// A stored entry that would be refused today is dropped on
				// read as well as on write: a list edited around the validator
				// must not become the way to get a wildcard in.
				continue;
			}

			$entries[$key] = [
				'addedBy' => (string)($entry['addedBy'] ?? ''),
				'addedAt' => (string)($entry['addedAt'] ?? ''),
			];
		}

		return $entries;
	}//end entries()

	/**
	 * Whether a key is on the list.
	 *
	 * 🔴 EXACT MATCH, CASE SENSITIVE. An environment variable name is case
	 * sensitive on every system this runs on, so a case-insensitive check would
	 * admit `db_password` for an entry reading `DB_PASSWORD` — a different
	 * variable that an administrator never looked at.
	 *
	 * @param string $key The key.
	 *
	 * @return bool True when it is listed.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function allows(string $key): bool {
		return array_key_exists($key, $this->entries());
	}//end allows()

	/**
	 * Add a key, recording who added it.
	 *
	 * @param string $key       The key.
	 * @param string $principal Who added it.
	 *
	 * @return array{added: bool, reason: string} What happened.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function add(string $key, string $principal): array {
		$refusal = $this->refusalFor(key: $key);
		if ($refusal !== null) {
			return ['added' => false, 'reason' => $refusal];
		}

		if (trim($principal) === '') {
			return [
				'added' => false,
				'reason' => 'A key is added by somebody: without a principal there is nobody to ask about it afterwards.',
			];
		}

		$key = trim($key);
		$entries = $this->entries();
		if (array_key_exists($key, $entries) === true) {
			return ['added' => false, 'reason' => sprintf('"%s" is already on the list.', $key)];
		}

		$entries[$key] = [
			'addedBy' => trim($principal),
			'addedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];

		$this->store(entries: $entries);

		return ['added' => true, 'reason' => ''];
	}//end add()

	/**
	 * Remove a key.
	 *
	 * @param string $key The key.
	 *
	 * @return bool True when it was on the list and is not any more.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function remove(string $key): bool {
		$entries = $this->entries();
		if (array_key_exists($key, $entries) === false) {
			return false;
		}

		unset($entries[$key]);
		$this->store(entries: $entries);

		return true;
	}//end remove()

	/**
	 * Write the list.
	 *
	 * @param array<string, array{addedBy: string, addedAt: string}> $entries The list.
	 *
	 * @return void
	 */
	private function store(array $entries): void {
		ksort($entries);
		$this->appConfig->setValueString(self::APP_ID, self::CONFIG_KEY, (string)json_encode($entries));
	}//end store()
}//end class
