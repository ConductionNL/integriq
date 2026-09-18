<?php

/**
 * Which source answers a prefix — one lookup, first wins, no fall-through.
 *
 * 🔴 AN UNREGISTERED PREFIX MUST NOT FALL THROUGH TO ANOTHER SOURCE. That is
 * the requirement's own sentence, and the reason is the shape of the failure:
 * a fall-through means `secret:DATABASE_PASSWORD` gets answered by whichever
 * source happens to be next, and the author of the expression never learns
 * their prefix does not exist. The refusal names the prefix, so a typo reads as
 * a typo.
 *
 * 🔑 FIRST-WINS ON A COLLISION, the same policy as the integration registry. Two
 * sources claiming `env:` is a packaging mistake, and resolving it by "last
 * registered" would make the answer depend on app load order — a value that
 * changes when an unrelated app is enabled.
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

use Psr\Log\LoggerInterface;

/**
 * The prefix-to-source lookup, and the one resolve entry point.
 *
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
 */
class ExpressionValueSourceRegistry {

	/**
	 * What separates a prefix from its key.
	 *
	 * @var string
	 */
	public const SEPARATOR = ':';

	/**
	 * The registered sources, keyed by prefix.
	 *
	 * @var array<string, ExpressionValueSourceInterface>
	 */
	private array $sources = [];

	/**
	 * Prefixes a second source tried to claim, and was refused.
	 *
	 * Kept rather than dropped: a collision is a packaging mistake somebody has
	 * to be able to see, and a registry that silently ignores the loser reports
	 * a healthy instance while one app's source never runs.
	 *
	 * @var array<string, int>
	 */
	private array $collisions = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register one source.
	 *
	 * @param ExpressionValueSourceInterface $source The source.
	 *
	 * @return bool True when it was registered, false when a source already held the prefix.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function register(ExpressionValueSourceInterface $source): bool {
		$prefix = trim($source->prefix());
		if ($prefix === '') {
			$this->logger->warning('[ExpressionValueSourceRegistry] a source with no prefix was not registered');
			return false;
		}

		if (array_key_exists($prefix, $this->sources) === true) {
			$this->collisions[$prefix] = (($this->collisions[$prefix] ?? 0) + 1);
			$this->logger->warning(
				sprintf('[ExpressionValueSourceRegistry] prefix "%s" is already claimed; the second source is ignored', $prefix)
			);
			return false;
		}

		$this->sources[$prefix] = $source;

		return true;
	}//end register()

	/**
	 * The source for a prefix, or null.
	 *
	 * @param string $prefix The prefix.
	 *
	 * @return ExpressionValueSourceInterface|null The source.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function sourceFor(string $prefix): ?ExpressionValueSourceInterface {
		return ($this->sources[trim($prefix)] ?? null);
	}//end sourceFor()

	/**
	 * Every registered source's description.
	 *
	 * @return array<int, array<string, mixed>> The descriptions.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function describeAll(): array {
		$described = [];
		foreach ($this->sources as $source) {
			$described[] = $source->describe();
		}

		return $described;
	}//end describeAll()

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
	public function resolve(string $reference, array $context = []): mixed {
		if (str_contains($reference, self::SEPARATOR) === false) {
			throw new ExpressionValueRefused(
				prefix: '',
				key: $reference,
				message: sprintf(
					'"%s" names no prefix. A prefixed value is written "prefix%skey".',
					$reference,
					self::SEPARATOR
				)
			);
		}

		[$prefix, $key] = explode(self::SEPARATOR, $reference, 2);
		$prefix = trim($prefix);

		$source = $this->sourceFor(prefix: $prefix);
		if ($source === null) {
			throw new ExpressionValueRefused(
				prefix: $prefix,
				key: $key,
				message: sprintf(
					'No value source answers to the prefix "%s". Known prefixes: %s.',
					$prefix,
					($this->sources === [] ? 'none' : implode(', ', array_keys($this->sources)))
				)
			);
		}

		return $source->resolve(key: $key, context: $context);
	}//end resolve()

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
	public function isSecret(string $reference): bool {
		if (str_contains($reference, self::SEPARATOR) === false) {
			return true;
		}

		[$prefix, $key] = explode(self::SEPARATOR, $reference, 2);

		$source = $this->sourceFor(prefix: trim($prefix));
		if ($source === null) {
			return true;
		}

		return $source->isSecret(key: $key);
	}//end isSecret()

	/**
	 * Prefixes more than one source tried to claim.
	 *
	 * @return array<string, int> Prefix to how many were refused.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function collisions(): array {
		return $this->collisions;
	}//end collisions()
}//end class
