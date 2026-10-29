<?php

/**
 * Contract of the ExpressionValueSourceRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Expression
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Expression;

/**
 * Public operations of {@see ExpressionValueSourceRegistry} that other components call.
 */
interface ExpressionValueSourceRegistryInterface {
	/**
	 * Register one source.
	 *
	 * @param ExpressionValueSourceInterface $source The source.
	 *
	 * @return bool True when it was registered, false when a source already held the prefix.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function register(ExpressionValueSourceInterface $source): bool;

	/**
	 * The source for a prefix, or null.
	 *
	 * @param string $prefix The prefix.
	 *
	 * @return ExpressionValueSourceInterface|null The source.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function sourceFor(string $prefix): ?ExpressionValueSourceInterface;

	/**
	 * Every registered source's description.
	 *
	 * @return array<int, array<string, mixed>> The descriptions.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function describeAll(): array;

	/**
	 * Resolve one prefixed reference.
	 *
	 * 🔴 IT SPLITS ON THE FIRST SEPARATOR ONLY. A key may legitimately contain a
	 * colon, and splitting on the last one would turn `env:A:B` into a lookup
	 * for the prefix `env:A` — which no source answers, so the failure is at
	 * least loud; but the author's key would be silently truncated in any
	 * implementation that guessed the other way.
	 *
	 * @param string               $reference The `prefix:key` reference.
	 * @param array<string, mixed> $context   The evaluation context.
	 *
	 * @return mixed The value.
	 *
	 * @throws ExpressionValueRefused When the prefix or the key may not be resolved.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function resolve(string $reference, array $context = []): mixed;

	/**
	 * Whether a resolved reference must be redacted before it is buffered.
	 *
	 * Asked by the recorder BEFORE the write, never after: see
	 * {@see ExpressionValueSourceInterface::isSecret()} and `execution-trace`
	 * REQ-003. A reference whose prefix nobody answers is treated as a secret,
	 * because an unresolvable reference in a trace is more likely to be a typo
	 * for a real one than something worth printing.
	 *
	 * @param string $reference The reference.
	 *
	 * @return bool True when it must be redacted.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function isSecret(string $reference): bool;

	/**
	 * Prefixes more than one source tried to claim.
	 *
	 * @return array<string, int> Prefix to how many were refused.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function collisions(): array;
}
