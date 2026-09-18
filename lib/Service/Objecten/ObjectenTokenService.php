<?php

/**
 * `Authorization: Token <key>` — and what that token may do, per objecttype.
 *
 * 🔴 FAIL-CLOSED ORDERING IS THE WHOLE OF THIS CLASS. The requirement spells
 * it out: no `Authorization` header means 401 "and no register is read". So the
 * token is resolved FIRST, before an objecttype is looked up, before a register
 * is touched — because a lookup that happens before the check is a lookup an
 * unauthenticated caller caused, and on a slow register that is a timing oracle
 * whether or not the answer is returned.
 *
 * 🔴 THE KEY IS NEVER STORED, LOGGED OR COMPARED LOOSELY. A token is matched by
 * a constant-time comparison against a value the credential broker resolves,
 * and the class holds a REFERENCE to the credential rather than the key itself,
 * so an endpoint-configuration export carries the reference and not the secret.
 *
 * 🔑 AND THE 403/404 SPLIT LEAKS EXISTENCE, WHICH IS THE STANDARD'S CHOICE, NOT
 * OURS. The requirement asks for 403 on an objecttype the token does not name
 * and 404 on one that does not exist, so a valid token can enumerate which
 * objecttypes an instance publishes. That is what a VNG consumer expects and
 * what interoperability needs; it is gated behind a valid token, and it is
 * written down here rather than discovered by whoever wonders why the two
 * answers differ.
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
 * Resolves a token and decides what it may do with one objecttype.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
class ObjectenTokenService {

	/**
	 * The scheme the standard uses.
	 *
	 * @var string
	 */
	public const SCHEME = 'Token';

	/**
	 * Read a list and read one object.
	 *
	 * @var string
	 */
	public const READ = 'read';

	/**
	 * Read and write.
	 *
	 * @var string
	 */
	public const READ_WRITE = 'read_write';

	/**
	 * The permissions a declaration may name.
	 *
	 * @var array<int, string>
	 */
	public const PERMISSIONS = [self::READ, self::READ_WRITE];

	/**
	 * No token at all.
	 *
	 * @var string
	 */
	public const NO_TOKEN = 'no-token';

	/**
	 * A token nobody issued.
	 *
	 * @var string
	 */
	public const UNKNOWN_TOKEN = 'unknown-token';

	/**
	 * An objecttype this instance does not publish.
	 *
	 * @var string
	 */
	public const UNKNOWN_OBJECTTYPE = 'unknown-objecttype';

	/**
	 * An objecttype this token does not name.
	 *
	 * @var string
	 */
	public const REFUSED_OBJECTTYPE = 'refused-objecttype';

	/**
	 * A write with a read-only permission.
	 *
	 * @var string
	 */
	public const READ_ONLY = 'read-only';

	/**
	 * Allowed.
	 *
	 * @var string
	 */
	public const ALLOWED = 'allowed';

	/**
	 * Verdict to HTTP status.
	 *
	 * @var array<string, int>
	 */
	public const STATUS = [
		self::NO_TOKEN => 401,
		self::UNKNOWN_TOKEN => 401,
		self::UNKNOWN_OBJECTTYPE => 404,
		self::REFUSED_OBJECTTYPE => 403,
		self::READ_ONLY => 403,
		self::ALLOWED => 200,
	];

	/**
	 * The declared tokens: reference, principal and per-objecttype permissions.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $tokens = [];

	/**
	 * Constructor.
	 *
	 * @param ObjecttypeRegistry $objecttypes    The published objecttypes.
	 * @param callable|null      $credentialRead Resolves a credential reference to its key.
	 */
	public function __construct(
		private readonly ObjecttypeRegistry $objecttypes,
		private $credentialRead = null,
	) {
	}//end __construct()

	/**
	 * Load the token declarations.
	 *
	 * 🔴 A DECLARATION CARRYING A LITERAL KEY IS REFUSED. The requirement says
	 * the key must be resolved through the credential broker and must not
	 * appear in an endpoint-configuration export — and the way a key ends up in
	 * an export is somebody pasting it into the configuration, so the
	 * configuration must not accept one.
	 *
	 * @param array<int, mixed> $declarations The tokens.
	 *
	 * @return array<int, string> The reasons any declaration was refused.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function load(array $declarations): array {
		$refusals = [];

		foreach ($declarations as $declaration) {
			if (is_array($declaration) === false) {
				$refusals[] = 'a token declaration must be an object';
				continue;
			}

			foreach (['key', 'secret', 'token'] as $forbidden) {
				if (array_key_exists($forbidden, $declaration) === true) {
					$refusals[] = sprintf(
						'a token declaration may not carry "%s": the key is resolved through the credential broker by reference, '
						. 'so it never reaches a configuration export',
						$forbidden
					);
					continue 2;
				}
			}

			$reference = trim((string)($declaration['credential'] ?? ''));
			if ($reference === '') {
				$refusals[] = 'a token declaration must name the credential reference its key is resolved from';
				continue;
			}

			$principal = trim((string)($declaration['principal'] ?? ''));
			if ($principal === '') {
				// Without a principal the audit trail names an anonymous
				// caller, which is the thing REQ-OAF-004's third scenario
				// exists to prevent.
				$refusals[] = 'a token declaration must name the principal its writes are attributed to';
				continue;
			}

			$this->tokens[] = [
				'credential' => $reference,
				'principal' => $principal,
				'permissions' => $this->permissionsOf(declaration: $declaration),
			];
		}//end foreach

		return $refusals;
	}//end load()

	/**
	 * What this request may do, as a verdict.
	 *
	 * @param string|null $authorization The `Authorization` header.
	 * @param string      $objecttype    The objecttype uuid, empty when the route names none.
	 * @param bool        $writing       Whether the request writes.
	 *
	 * @return array{verdict: string, status: int, principal: string} The verdict.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function verdictFor(?string $authorization, string $objecttype, bool $writing = false): array {
		// 1. The token, FIRST. Nothing below this line may touch a register.
		$key = $this->keyFrom(authorization: $authorization);
		if ($key === null) {
			return $this->verdict(verdict: self::NO_TOKEN, principal: '');
		}

		$token = $this->tokenFor(key: $key);
		if ($token === null) {
			return $this->verdict(verdict: self::UNKNOWN_TOKEN, principal: '');
		}

		// 2. Does this instance publish the objecttype at all.
		if ($objecttype !== '' && $this->objecttypes->find(uuid: $objecttype) === null) {
			return $this->verdict(verdict: self::UNKNOWN_OBJECTTYPE, principal: $token['principal']);
		}

		// 3. Does this token name it.
		$permission = ($token['permissions'][$objecttype] ?? null);
		if ($objecttype !== '' && $permission === null) {
			return $this->verdict(verdict: self::REFUSED_OBJECTTYPE, principal: $token['principal']);
		}

		// 4. And may it write.
		if ($writing === true && $permission !== self::READ_WRITE) {
			return $this->verdict(verdict: self::READ_ONLY, principal: $token['principal']);
		}

		return $this->verdict(verdict: self::ALLOWED, principal: $token['principal']);
	}//end verdictFor()

	/**
	 * The key from an `Authorization` header, or null.
	 *
	 * 🔑 THE SCHEME IS MATCHED CASE-INSENSITIVELY AND THE KEY IS NOT. HTTP
	 * auth-scheme names are case-insensitive by RFC 7235, and a client sending
	 * `token abc` is conformant; the key after it is opaque and must be
	 * compared exactly.
	 *
	 * @param string|null $authorization The header.
	 *
	 * @return string|null The key.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function keyFrom(?string $authorization): ?string {
		$header = trim((string)$authorization);
		if ($header === '') {
			return null;
		}

		$parts = preg_split('/\s+/', $header, 2);
		if ($parts === false || count($parts) !== 2) {
			return null;
		}

		if (strcasecmp($parts[0], self::SCHEME) !== 0) {
			return null;
		}

		$key = trim($parts[1]);

		if ($key === '') {
			return null;
		}

		return $key;
	}//end keyFrom()

	/**
	 * The token whose resolved key matches, or null.
	 *
	 * @param string $key The presented key.
	 *
	 * @return array<string, mixed>|null The token.
	 */
	private function tokenFor(string $key): ?array {
		foreach ($this->tokens as $token) {
			$resolved = $this->resolveKey(reference: (string)$token['credential']);
			if ($resolved === null) {
				continue;
			}

			// 🔴 CONSTANT TIME. A token check that returns early on the first
			// differing byte tells an attacker how much of a guess was right,
			// one request at a time.
			if (hash_equals($resolved, $key) === true) {
				return $token;
			}
		}

		return null;
	}//end tokenFor()

	/**
	 * Resolve a credential reference to its key.
	 *
	 * @param string $reference The reference.
	 *
	 * @return string|null The key, or null when it cannot be resolved.
	 */
	private function resolveKey(string $reference): ?string {
		if ($this->credentialRead === null) {
			return null;
		}

		$resolved = ($this->credentialRead)($reference);

		if (is_string($resolved) === false || $resolved === '') {
			return null;
		}

		return $resolved;
	}//end resolveKey()

	/**
	 * The per-objecttype permissions of one declaration.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 *
	 * @return array<string, string> Objecttype uuid to permission.
	 */
	private function permissionsOf(array $declaration): array {
		$permissions = [];
		foreach ((array)($declaration['permissions'] ?? []) as $uuid => $permission) {
			$uuid = trim((string)$uuid);
			$permission = trim((string)$permission);

			// An unknown permission word is DROPPED rather than downgraded to
			// read: a typo that silently becomes read-only is a counterparty
			// whose writes stop working with a 403 nobody can explain, and a
			// typo that silently becomes read-write is worse.
			if ($uuid === '' || in_array($permission, self::PERMISSIONS, true) === false) {
				continue;
			}

			$permissions[$uuid] = $permission;
		}

		return $permissions;
	}//end permissionsOf()

	/**
	 * Shape one verdict.
	 *
	 * @param string $verdict   The verdict.
	 * @param string $principal Whose it is.
	 *
	 * @return array{verdict: string, status: int, principal: string} The verdict.
	 */
	private function verdict(string $verdict, string $principal): array {
		return [
			'verdict' => $verdict,
			'status' => (self::STATUS[$verdict] ?? 403),
			'principal' => $principal,
		];
	}//end verdict()
}//end class
