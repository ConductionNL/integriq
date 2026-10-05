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
class OptOutCategories {

	/**
	 * The app-config key holding extra aliases of floor categories.
	 *
	 * @var string
	 */
	public const CONFIG_PROTECTED = 'outbound.protected_categories';

	/**
	 * A decision, its publication.
	 *
	 * @var string
	 */
	public const BESLUIT = 'besluit';

	/**
	 * Any notice the law requires.
	 *
	 * @var string
	 */
	public const STATUTORY = 'statutory';

	/**
	 * Password reset, account created, data export ready.
	 *
	 * @var string
	 */
	public const ACCOUNT = 'account';

	/**
	 * Login alert, two-factor, a changed address.
	 *
	 * @var string
	 */
	public const SECURITY = 'security';

	/**
	 * Status updates and mail about one case.
	 *
	 * @var string
	 */
	public const CASE_UPDATE = 'case-update';

	/**
	 * Appointments and reminders.
	 *
	 * @var string
	 */
	public const REMINDER = 'reminder';

	/**
	 * Everything transactional that is not above. Also what an unknown category reads as.
	 *
	 * @var string
	 */
	public const SERVICE = 'service';

	/**
	 * Blasts, journeys, list mail. Needs recorded consent.
	 *
	 * @var string
	 */
	public const MARKETING = 'marketing';

	/**
	 * A direct answer to a message the citizen sent. Only with `inReplyTo`.
	 *
	 * @var string
	 */
	public const REPLY = 'reply';

	/**
	 * The exempt floor. Nothing removes or extends it.
	 *
	 * @var array<int,string>
	 */
	public const FLOOR = [self::BESLUIT, self::STATUTORY, self::ACCOUNT, self::SECURITY];

	/**
	 * Every category a sender may name.
	 *
	 * @var array<int,string>
	 */
	public const KNOWN = [
		self::BESLUIT,
		self::STATUTORY,
		self::ACCOUNT,
		self::SECURITY,
		self::CASE_UPDATE,
		self::REMINDER,
		self::SERVICE,
		self::MARKETING,
		self::REPLY,
	];

	/**
	 * The aliases that always hold, so existing config and callers keep working.
	 *
	 * @var array<string,string>
	 */
	public const DEFAULT_ALIASES = [
		'ontvangstbevestiging' => self::STATUTORY,
		'invordering' => self::STATUTORY,
	];

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
			$alias = strtolower(trim((string)$key));
			$target = strtolower(trim((string)$value));
			if (is_int($key) === true) {
				$alias = $target;
			}

			if (in_array($target, self::FLOOR, true) === true && is_int($key) === false && $alias !== '') {
				$aliases[$alias] = $target;
				continue;
			}

			if (in_array($alias, self::FLOOR, true) === true || isset(self::DEFAULT_ALIASES[$alias]) === true) {
				continue;
			}

			$this->logger->warning(
				'[OptOutCategories] ignored a value in ' . self::CONFIG_PROTECTED . ': only aliases of besluit, statutory, account and security are accepted',
				['value' => $value, 'key' => $key]
			);
		}//end foreach

		return $aliases;

	}//end aliases()

}//end class
