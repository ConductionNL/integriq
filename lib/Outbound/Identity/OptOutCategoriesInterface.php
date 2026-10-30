<?php

/**
 * Contract of the OptOutCategories.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound\Identity
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

namespace OCA\Integriq\Outbound\Identity;

/**
 * Public operations of {@see OptOutCategories} that other components call.
 */
interface OptOutCategoriesInterface {
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
	 * The purpose of an opt-out that stops marketing only.
	 *
	 * @var string
	 */
	public const PURPOSE_MARKETING = 'marketing';

	/**
	 * The purpose of an opt-out that stops case updates, reminders and service messages.
	 *
	 * @var string
	 */
	public const PURPOSE_SERVICE = 'service';

	/**
	 * The purpose of an opt-out that stops every category that is not exempt.
	 * Every row written before purposes were read has it.
	 *
	 * @var string
	 */
	public const PURPOSE_ALL = '';

	/**
	 * Which purpose each non-exempt category belongs to.
	 *
	 * @var array<string,string>
	 */
	public const PURPOSE_OF = [
		self::MARKETING => self::PURPOSE_MARKETING,
		self::CASE_UPDATE => self::PURPOSE_SERVICE,
		self::REMINDER => self::PURPOSE_SERVICE,
		self::SERVICE => self::PURPOSE_SERVICE,
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
	public function canonical(string $category, ?string $inReplyTo = null, string $sourceApp = ''): string;

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
	public function purposeOf(string $category): string;

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
	public function normalisePurpose(string $purpose): string;

	/**
	 * Whether an opt-out can never stop this category.
	 *
	 * @param string $category The category, canonical or not.
	 *
	 * @return bool True for the floor and its aliases.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-exempt-categories-are-a-fixed-floor-req-ooa-004
	 */
	public function isExempt(string $category): bool;

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
	public function aliases(): array;
}
