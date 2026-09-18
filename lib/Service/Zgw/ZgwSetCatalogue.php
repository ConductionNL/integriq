<?php

/**
 * The six ZGW consumer sets integriq ships, and what each one binds to.
 *
 * 🔴 NO SET NAMES A FLEET APP, AND THAT IS ENFORCED RATHER THAN INTENDED.
 * A packaged set that says `dossiq` is coupled to an app id, and app ids in this
 * fleet MOVE: procest became dossiq, docudesk became filinq, hrmq became
 * humaniq, and each one moved on its own schedule. Worse, the lookups these
 * names feed are duck-typed — `isInstalled('docudesk')` against a name nothing
 * answers to does not error, it silently returns false, and the integration
 * becomes a no-op that every screen reports as configured.
 *
 * So a set names a ZGW COMPONENT, which is a national standard and does not get
 * renamed by a marketing decision, and the operator binds it to whatever
 * register and schema they actually have.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Integriq\Service\Zgw
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://Integriq.app
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Zgw;

/**
 * The packaged set slugs and the rules every set obeys.
 */
final class ZgwSetCatalogue {

	/**
	 * The six sets, by slug, each naming its ZGW component.
	 *
	 * @var array<string, string>
	 */
	public const SETS = [
		'zgw-zaken' => 'Zaken API',
		'zgw-documenten' => 'Documenten API',
		'zgw-catalogi' => 'Catalogi API',
		'zgw-besluiten' => 'Besluiten API',
		'zgw-objecten' => 'Objecten API',
		'zgw-notificaties' => 'Notificaties API',
	];

	/**
	 * The sets whose objects are written back to the store on a local change.
	 *
	 * Catalogi is a definition store an operator does not edit from here, and
	 * notificaties carries subscriptions rather than records, so neither has a
	 * write-back. Saying which four DO is what stops a fifth being added later
	 * with nobody noticing it has no push.
	 *
	 * @var string[]
	 */
	public const WRITE_BACK_SETS = ['zgw-zaken', 'zgw-documenten', 'zgw-besluiten', 'zgw-objecten'];

	/**
	 * The auth scheme every set's source template declares.
	 *
	 * @var string
	 */
	public const AUTH = 'jwt-zgw';

	/**
	 * The storage strategy a bound schema is written with.
	 *
	 * `external` means the store is the truth and the local object is a
	 * projection of it. Writing these locally would give an operator two
	 * divergent copies with nothing saying which one a screen is showing.
	 *
	 * @var string
	 */
	public const STORAGE_STRATEGY = 'external';

	/**
	 * The status a record carries when the store refused its write-back.
	 *
	 * @var string
	 */
	public const STATUS_CONFLICT = 'conflict';

	/**
	 * Fleet app ids a set must never name.
	 *
	 * Both the current and the retired spellings, because a set written before
	 * a rename carries the old one and a set written after carries the new, and
	 * both are equally wrong here.
	 *
	 * @var string[]
	 */
	public const FLEET_APPS = [
		'dossiq', 'procest',
		'filinq', 'docudesk',
		'humaniq', 'hrmq',
		'keepiq', 'doriath',
		'learniq', 'scholiq',
		'decidiq', 'decidesk',
		'thematiq', 'nldesign',
		'stackiq', 'softwarecatalog',
		'larpinq', 'larpingapp',
		'buildiq', 'openbuild',
		'versioniq', 'app-versions',
		'planninq', 'planix',
		'zaakafhandelapp', 'opencatalogi',
		'portaliq', 'shillinq', 'pipelinq', 'hermiq', 'launchpad', 'integriq',
	];

	/**
	 * Whether this slug is one of the six.
	 *
	 * @param string $slug The set slug.
	 *
	 * @return bool True when it is packaged.
	 */
	public static function isPackaged(string $slug): bool {
		return array_key_exists($slug, self::SETS);
	}//end isPackaged()

	/**
	 * Whether this set pushes local changes back to the store.
	 *
	 * @param string $slug The set slug.
	 *
	 * @return bool True when it has a write-back.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md
	 */
	public static function writesBack(string $slug): bool {
		return in_array($slug, self::WRITE_BACK_SETS, true);
	}//end writesBack()
}//end class
