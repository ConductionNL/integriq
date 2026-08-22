<?php

/**
 * Integriq — OpenRegister dependency setup check.
 *
 * Surfaces an admin-visible notice in the Nextcloud admin overview when the
 * required OpenRegister app is not installed/enabled. Integriq persists
 * every entity as an OpenRegister object and injects OpenRegister's
 * `ObjectService` as a required, non-nullable controller dependency, so without
 * OpenRegister the app's routes cannot be built and would otherwise return bare
 * HTTP 500s. This check turns that silent failure into a clear, actionable
 * admin signal (REQ-ADM-003).
 *
 * The detection uses only `IAppManager` and MUST NOT reference any
 * `OCA\OpenRegister\*` class, so it is safe to run while OpenRegister is absent.
 *
 * @category SetupCheck
 * @package  OCA\Integriq\SetupCheck
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

namespace OCA\Integriq\SetupCheck;

use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Reports OpenRegister as a required, missing dependency in the admin overview.
 *
 * @spec openspec/specs/app-distribution-metadata/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class OpenRegisterDependencyCheck implements ISetupCheck {

	/**
	 * The required dependency app id.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App-enablement query service.
	 * @param IL10N $l10n Localisation service.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Category under which this check is grouped in the admin overview.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/app-distribution-metadata/spec.md
	 */
	public function getCategory(): string {
		return 'system';
	}//end getCategory()

	/**
	 * Human-readable name of this check.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/app-distribution-metadata/spec.md
	 */
	public function getName(): string {
		return $this->l10n->t('Integriq: OpenRegister dependency');
	}//end getName()

	/**
	 * Run the check.
	 *
	 * Uses `IAppManager` only — never touches an OpenRegister class — so it is
	 * safe while OpenRegister is absent.
	 *
	 * @return SetupResult error when OpenRegister is not enabled, success otherwise.
	 *
	 * @spec openspec/specs/app-distribution-metadata/spec.md
	 */
	public function run(): SetupResult {
		if ($this->appManager->isEnabledForAnyone(self::REQUIRED_APP) === false) {
			return SetupResult::error(
				$this->l10n->t(
					'Integriq requires the OpenRegister app — install and enable it. '
					. 'Every Integriq entity is stored as an OpenRegister object; without it '
					. 'the app cannot function and its API endpoints return errors.'
				)
			);
		}

		return SetupResult::success(
			$this->l10n->t('The required OpenRegister app is installed and enabled.')
		);
	}//end run()
}//end class
