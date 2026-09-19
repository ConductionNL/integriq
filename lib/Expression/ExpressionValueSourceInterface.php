<?php

/**
 * A prefixed value an expression may reach for, behind one contract.
 *
 * 🔴 AN EXPRESSION LANGUAGE THAT CAN READ THE ENVIRONMENT IS A DATA
 * EXFILTRATION PATH. Not a metaphor: an expression is configuration, a case
 * type or a rule somebody edits in a form, and `env:DATABASE_PASSWORD` in a
 * template that renders into an email is a credential leaving the building
 * with a 200 beside it. The allowlist is what makes the prefix safe, and this
 * contract is where it is enforced rather than remembered.
 *
 * 🔑 INTEGRIQ RESOLVES, OPENREGISTER EVALUATES. This contract parses nothing
 * and evaluates nothing: it is handed a key that a prefix already selected and
 * answers with a value or a refusal. Extending expression SYNTAX here would put
 * a second evaluator in the fleet, which is the thing decision D3 exists to
 * prevent.
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

/**
 * One prefixed value source.
 *
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
 */
interface ExpressionValueSourceInterface {

	/**
	 * The prefix this source answers to, without the colon.
	 *
	 * @return string The prefix.
	 */
	public function prefix(): string;

	/**
	 * Resolve one key.
	 *
	 * @param string               $key     The key after the prefix.
	 * @param array<string, mixed> $context The evaluation context.
	 *
	 * @return mixed The value.
	 *
	 * @throws ExpressionValueRefused When the key may not be resolved.
	 */
	public function resolve(string $key, array $context = []): mixed;

	/**
	 * What this source can answer, and how.
	 *
	 * 🔑 A CALLER CAN ASK BEFORE IT TRIES. `describe()` states whether a write
	 * is possible, so a caller learns it without attempting one — an attempt
	 * that fails is a refusal somebody has to read, and this is the same answer
	 * without the noise.
	 *
	 * @return array{prefix: string, writable: bool, allowlisted: bool, description: string} The description.
	 */
	public function describe(): array;

	/**
	 * Whether a value this source resolves must be treated as a secret.
	 *
	 * Per key, not per source: an `env:` allowlist holds `SMTP_HOST` beside
	 * `SMTP_PASSWORD`, and redacting both would make a trace useless while
	 * redacting neither would put the second in it.
	 *
	 * @param string $key The key.
	 *
	 * @return bool True when the resolved value is a secret.
	 */
	public function isSecret(string $key): bool;
}//end interface
