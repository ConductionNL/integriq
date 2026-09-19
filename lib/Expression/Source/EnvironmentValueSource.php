<?php

/**
 * `env:` — the process environment, and only what an administrator named.
 *
 * 🔴 IT RESOLVES NOTHING THAT IS NOT ON THE LIST, and refuses by throwing
 * rather than by answering empty. A source that returns `''` for a key nobody
 * allowed hands its caller a value that renders as nothing: an email with a
 * blank host, a URL with a blank base, a signature over a blank secret. The
 * refusal has to be loud or it is not a refusal.
 *
 * 🔴 AND A REFUSAL NAMES THE KEY, NEVER THE VALUE. The key name is what an
 * administrator needs in order to decide whether to allow it; the value is the
 * thing the allowlist exists to withhold. A message reading "could not resolve
 * DATABASE_PASSWORD" is useful and safe; one that helpfully appends what it
 * found is the leak with an apology attached.
 *
 * 🔑 EVERY RESOLVED VALUE IS TREATED AS A SECRET. Not a guess per key: this
 * source reads the process environment of a Nextcloud container, and the
 * distinction between `SMTP_HOST` and `SMTP_PASSWORD` is one nobody can draw
 * from the name reliably. Redacting all of them in traces costs a hostname in
 * a log; redacting by guess costs a password.
 *
 * @category Expression
 * @package  OCA\Integriq\Expression\Source
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

namespace OCA\Integriq\Expression\Source;

use OCA\Integriq\Expression\EnvironmentAllowlist;
use OCA\Integriq\Expression\ExpressionValueRefused;
use OCA\Integriq\Expression\ExpressionValueSourceInterface;

/**
 * The `env:` value source.
 *
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
 */
class EnvironmentValueSource implements ExpressionValueSourceInterface {

	/**
	 * The prefix this source answers to.
	 *
	 * @var string
	 */
	public const PREFIX = 'env';

	/**
	 * Constructor.
	 *
	 * @param EnvironmentAllowlist $allowlist       What may be read.
	 * @param callable|null        $environmentRead How to read the environment; injectable so a test needs no putenv.
	 */
	public function __construct(
		private readonly EnvironmentAllowlist $allowlist,
		private $environmentRead = null,
	) {
	}//end __construct()

	/**
	 * The prefix.
	 *
	 * @return string The prefix.
	 */
	public function prefix(): string {
		return self::PREFIX;
	}//end prefix()

	/**
	 * Resolve one allowlisted environment variable.
	 *
	 * @param string               $key     The variable name.
	 * @param array<string, mixed> $context The evaluation context, unused here.
	 *
	 * @return mixed The value.
	 *
	 * @throws ExpressionValueRefused When the key is not allowlisted or is not set.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function resolve(string $key, array $context = []): mixed {
		$key = trim($key);

		if ($this->allowlist->allows(key: $key) === false) {
			throw new ExpressionValueRefused(
				prefix: self::PREFIX,
				key: $key,
				message: sprintf(
					'"%s" is not on the environment allowlist, so it is not readable from an expression. '
					. 'An instance administrator can add it by exact name.',
					$key
				)
			);
		}

		$value = getenv($key);
		if ($this->environmentRead !== null) {
			$value = ($this->environmentRead)($key);
		}

		if ($value === false || $value === null) {
			// 🔑 ALLOWED BUT UNSET IS ITS OWN ANSWER. Returning null here would
			// be indistinguishable from a variable whose value is empty, and an
			// administrator debugging a blank base URL needs to know which of
			// the two they have.
			throw new ExpressionValueRefused(
				prefix: self::PREFIX,
				key: $key,
				message: sprintf('"%s" is allowlisted but is not set in this instance\'s environment.', $key)
			);
		}

		return $value;
	}//end resolve()

	/**
	 * What this source can answer.
	 *
	 * @return array{prefix: string, writable: bool, allowlisted: bool, description: string} The description.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function describe(): array {
		return [
			'prefix' => self::PREFIX,
			'writable' => false,
			'allowlisted' => true,
			'description' => 'Reads an environment variable an instance administrator has listed by exact name. '
				. 'Read only: an expression cannot change the environment.',
		];
	}//end describe()

	/**
	 * Whether a resolved value must be redacted.
	 *
	 * Always. See the class docblock: the distinction between a hostname and a
	 * password is not one this source can draw from a key name.
	 *
	 * @param string $key The key.
	 *
	 * @return bool Always true.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function isSecret(string $key): bool {
		return true;
	}//end isSecret()

	/**
	 * Refuse a write, naming the prefix.
	 *
	 * 🔴 NEVER A SILENT SUCCESS. The Valtimo interface this mirrors has a
	 * `store()` half, so a caller written against it will try. Answering
	 * "done" and changing nothing is how a configuration screen reports a
	 * saved value that was never saved.
	 *
	 * @param string $key   The key.
	 * @param mixed  $value The value.
	 *
	 * @return void
	 *
	 * @throws ExpressionValueRefused Always.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function store(string $key, mixed $value): void {
		throw new ExpressionValueRefused(
			prefix: self::PREFIX,
			key: $key,
			message: 'The "env" source is read only: an expression cannot change this instance\'s environment.'
		);
	}//end store()
}//end class
