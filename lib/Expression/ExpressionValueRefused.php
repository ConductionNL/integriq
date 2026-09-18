<?php

/**
 * A prefixed value that may not be resolved, and says which and why.
 *
 * 🔴 IT IS AN EXCEPTION, NOT AN EMPTY STRING. A source that answers `''` for a
 * key nobody allowed hands the caller a value that renders as nothing — an
 * email with a blank host, a URL with a blank base — and the refusal is
 * invisible at exactly the moment it mattered. The requirement says it in those
 * words: no value AND no empty string.
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

use RuntimeException;
use Throwable;

/**
 * Raised when a prefixed value may not be resolved.
 *
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
 */
class ExpressionValueRefused extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string         $prefix   The prefix asked for.
	 * @param string         $key      The key asked for.
	 * @param string         $message  Why.
	 * @param Throwable|null $previous Previous exception.
	 */
	public function __construct(
		private readonly string $prefix,
		private readonly string $key,
		string $message,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 403, previous: $previous);
	}//end __construct()

	/**
	 * The prefix that was asked for.
	 *
	 * @return string The prefix.
	 */
	public function getPrefix(): string {
		return $this->prefix;
	}//end getPrefix()

	/**
	 * The key that was asked for.
	 *
	 * 🔑 THE KEY IS SAFE TO NAME; ITS VALUE IS NOT. A refusal that will not say
	 * which key it refused is one an administrator cannot act on, and the key
	 * name is what they need to decide whether to allow it.
	 *
	 * @return string The key.
	 */
	public function getKey(): string {
		return $this->key;
	}//end getKey()
}//end class
