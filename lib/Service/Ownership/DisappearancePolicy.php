<?php

/**
 * What happens to a record when the source stops carrying it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Ownership
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

namespace OCA\Integriq\Service\Ownership;

use InvalidArgumentException;

/**
 * Declared on the synchronisation, never hardcoded in the engine. A value the
 * engine does not know is refused, and is never quietly read as the default.
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-what-happens-when-a-record-disappears-is-declared-not-hardcoded-req-sor-002
 */
final class DisappearancePolicy {
	/**
	 * Delete the object, which is what the engine did before this change.
	 */
	public const DELETE = 'delete';

	/**
	 * Keep the object and write an end date on it.
	 */
	public const MARK_ENDED = 'markEnded';

	/**
	 * Keep the object untouched and mark it absent at the source.
	 */
	public const KEEP_AND_FLAG = 'keepAndFlag';

	/**
	 * The key a synchronisation declares the policy under.
	 */
	public const CONFIG_KEY = 'disappearancePolicy';

	/**
	 * Every value the engine knows.
	 *
	 * @var array<int,string>
	 */
	public const ACCEPTED = [self::DELETE, self::MARK_ENDED, self::KEEP_AND_FLAG];

	/**
	 * Read the declared policy, refusing a value the engine does not know.
	 *
	 * @param array<string,mixed> $sourceConfig The synchronisation's sourceConfig.
	 *
	 * @return string One of the accepted values.
	 *
	 * @throws InvalidArgumentException When the declared value is not one the engine knows.
	 */
	public static function fromSourceConfig(array $sourceConfig): string {
		$declared = ($sourceConfig[self::CONFIG_KEY] ?? null);
		if ($declared === null || $declared === '') {
			return self::DELETE;
		}

		if (is_string($declared) === false || in_array($declared, self::ACCEPTED, true) === false) {
			throw new InvalidArgumentException(self::refusalMessage($declared));
		}

		return $declared;
	}//end fromSourceConfig()

	/**
	 * Whether a sourceConfig carries a policy the engine knows.
	 *
	 * @param array<string,mixed> $sourceConfig The synchronisation's sourceConfig.
	 *
	 * @return bool True when the declaration is absent or accepted.
	 */
	public static function isValid(array $sourceConfig): bool {
		try {
			self::fromSourceConfig($sourceConfig);
			return true;
		} catch (InvalidArgumentException $e) {
			return false;
		}
	}//end isValid()

	/**
	 * The refusal, naming the key and the values it accepts.
	 *
	 * @param mixed $declared What was declared.
	 *
	 * @return string The message.
	 */
	public static function refusalMessage(mixed $declared): string {
		$declaredText = gettype($declared);
		if (is_scalar($declared) === true) {
			$declaredText = (string)$declared;
		}

		return sprintf(
			'sourceConfig.%s must be one of %s. "%s" is not a policy this engine knows, and it is not treated as the default.',
			self::CONFIG_KEY,
			implode(', ', self::ACCEPTED),
			$declaredText
		);
	}//end refusalMessage()
}//end class
