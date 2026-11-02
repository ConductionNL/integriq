<?php

/**
 * Integriq OptOutCategories.
 *
 * What kind of message a send is decides whether an opt-out can stop it. The
 * channel never does: a besluit by Berichtenbox is a besluit, a case update by
 * Berichtenbox is a case update.
 *
 * Four categories are a fixed floor no opt-out can stop: besluit, statutory,
 * account and security. Configuration cannot remove one and cannot add one,
 * because an instance that made reminders exempt would message people who
 * asked it not to. The config key `outbound.protected_categories` only holds
 * aliases of floor categories (ADR-102: a malformed value falls back to the
 * floor and logs a warning).
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-exempt-categories-are-a-fixed-floor-req-ooa-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The category list, the exempt floor and the aliases.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-exempt-categories-are-a-fixed-floor-req-ooa-004
 */
class OptOutCategories implements OptOutCategoriesInterface {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the extra aliases.
	 * @param LoggerInterface $logger Warns about values that are ignored.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The category a send is decided as.
	 *
	 * An alias reads as its floor category. `reply` without `inReplyTo` reads
	 * as `service`. An empty or unknown category reads as `service`, with a
	 * warning naming the app that sent it.
	 *
	 * @param string $category What the sender called it.
	 * @param string|null $inReplyTo The inbound message a reply answers.
	 * @param string $sourceApp The asking app, for the warning.
	 *
	 * @return string One of KNOWN.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-exempt-categories-are-a-fixed-floor-req-ooa-004
	 */
	public function canonical(string $category, ?string $inReplyTo = null, string $sourceApp = ''): string {
		$category = strtolower(trim($category));
		$aliases = $this->aliases();
		if (isset($aliases[$category]) === true) {
			return $aliases[$category];
		}

		if ($category === self::REPLY) {
			if ($inReplyTo !== null && trim($inReplyTo) !== '') {
				return self::REPLY;
			}

			return self::SERVICE;
		}

		if (in_array($category, self::KNOWN, true) === true) {
			return $category;
		}

		$this->logger->warning(
			'[OptOutCategories] unknown message category, decided as service',
			['category' => $category, 'sourceApp' => $sourceApp]
		);

		return self::SERVICE;

	}//end canonical()

	/**
	 * The purpose a message of this category belongs to.
	 *
	 * Empty for an exempt category and for `reply`: no opt-out stops those,
	 * so their links and checks have no purpose to name.
	 *
	 * @param string $category The canonical category.
	 *
	 * @return string `marketing`, `service` or empty.
	 *
	 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
	 */
	public function purposeOf(string $category): string {
		return (self::PURPOSE_OF[strtolower(trim($category))] ?? self::PURPOSE_ALL);

	}//end purposeOf()

	/**
	 * The stored purpose for what a caller sent.
	 *
	 * `marketing` and `service` stay. A category name becomes its group, so
	 * `reminder` is `service`. `all` and empty mean everything. Anything else
	 * also means everything, with a warning: an unknown purpose stops more,
	 * never less.
	 *
	 * @param string $purpose What the caller sent.
	 *
	 * @return string `marketing`, `service` or empty.
	 *
	 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
	 */
	public function normalisePurpose(string $purpose): string {
		$purpose = strtolower(trim($purpose));
		if ($purpose === '' || $purpose === 'all') {
			return self::PURPOSE_ALL;
		}

		if (isset(self::PURPOSE_OF[$purpose]) === true) {
			return self::PURPOSE_OF[$purpose];
		}

		$this->logger->warning(
			'[OptOutCategories] unknown opt-out purpose, stored as everything',
			['purpose' => $purpose]
		);

		return self::PURPOSE_ALL;

	}//end normalisePurpose()

	/**
	 * Whether an opt-out can never stop this category.
	 *
	 * @param string $category The category, canonical or not.
	 *
	 * @return bool True for the floor and its aliases.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-exempt-categories-are-a-fixed-floor-req-ooa-004
	 */
	public function isExempt(string $category): bool {
		$category = strtolower(trim($category));
		$aliases = $this->aliases();
		if (isset($aliases[$category]) === true) {
			return true;
		}

		return in_array($category, self::FLOOR, true);

	}//end isExempt()

	/**
	 * The aliases on this instance: the defaults plus what config adds.
	 *
	 * Config may hold a list (each entry a floor category or a known alias)
	 * or a map of alias to floor category. Anything else is ignored with a
	 * warning: config can neither take a category off the floor nor put one on.
	 *
	 * @return array<string,string> Alias to floor category.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-exempt-categories-are-a-fixed-floor-req-ooa-004
	 */
	public function aliases(): array {
		$aliases = self::DEFAULT_ALIASES;
		$raw = trim($this->appConfig->getValueString('integriq', self::CONFIG_PROTECTED, ''));
		if ($raw === '') {
			return $aliases;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			$this->logger->warning(
				'[OptOutCategories] ' . self::CONFIG_PROTECTED . ' is not JSON; the fixed floor applies',
				['value' => $raw]
			);
			return $aliases;
		}

		foreach ($decoded as $key => $value) {
			$accepted = $this->acceptedAlias(key: $key, value: $value);
			if ($accepted === true) {
				continue;
			}

			if (is_array($accepted) === true) {
				$aliases[$accepted[0]] = $accepted[1];
				continue;
			}

			$this->logger->warning(
				'[OptOutCategories] ignored a value in ' . self::CONFIG_PROTECTED . ': only aliases of besluit, statutory, account and security are accepted',
				['value' => $value, 'key' => $key]
			);
		}//end foreach

		return $aliases;

	}//end aliases()

	/**
	 * What one config entry means.
	 *
	 * A map entry `alias => floor category` adds an alias. A list entry that
	 * names a floor category or a default alias changes nothing. Anything
	 * else is not accepted.
	 *
	 * @param int|string $key The entry key.
	 * @param mixed $value The entry value.
	 *
	 * @return array{0:string,1:string}|bool The alias and its target, true when the entry is a
	 *         harmless no-op, false when it is not accepted.
	 */
	private function acceptedAlias(int|string $key, mixed $value): array|bool {
		$target = strtolower(trim((string)$value));
		if (is_int($key) === true) {
			return (in_array($target, self::FLOOR, true) === true || isset(self::DEFAULT_ALIASES[$target]) === true);
		}

		$alias = strtolower(trim($key));
		if ($alias !== '' && in_array($target, self::FLOOR, true) === true) {
			return [$alias, $target];
		}

		return false;

	}//end acceptedAlias()

}//end class
