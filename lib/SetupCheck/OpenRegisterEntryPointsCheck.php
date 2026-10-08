<?php
/**
 * Setup check: the running OpenRegister offers the public credential checks integriq delegates to.
 *
 * Since gate 23 every credentialed inbound call (endpoint dispatch, SCIM,
 * Notificaties callbacks, the EUDI token endpoint, LTI launch timing) is
 * checked by OpenRegister's AuthorizationService. An OpenRegister that
 * predates those public entry points (stable 2.1.0 and older) makes the
 * bridge refuse every such call with 401 "Inbound authentication is
 * unavailable". info.xml cannot express that version requirement, so this
 * check tells the administrator before the first caller does.
 *
 * @category  SetupCheck
 * @package   OCA\Integriq\SetupCheck
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/integriq
 *
 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\SetupCheck;

use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Warns when OpenRegister is too old for integriq's inbound authentication.
 *
 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 */
class OpenRegisterEntryPointsCheck implements ISetupCheck {

	/**
	 * The class the credential checks live in; named as a string so this check loads without OpenRegister.
	 */
	public const AUTHORIZATION_SERVICE = 'OCA\\OpenRegister\\Service\\AuthorizationService';

	/**
	 * The first OpenRegister that carries the public entry points (OR#4361).
	 */
	public const MINIMUM_OPENREGISTER = '2.1.35';


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's AuthorizationService lazily.
	 * @param IL10N              $l10n      Translations.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
	) {
	}//end __construct()


	/**
	 * The category the check is listed under.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function getCategory(): string {
		return 'system';
	}//end getCategory()


	/**
	 * The name shown in the admin overview.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function getName(): string {
		return $this->l10n->t('Integriq: OpenRegister credential checks');
	}//end getName()


	/**
	 * Error when the running OpenRegister lacks the public credential checks.
	 *
	 * @return SetupResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SetupResult's named constructors are the only way Nextcloud offers to build one; the bridge's probe is static so the check and the bridge share one verdict.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function run(): SetupResult {
		if (class_exists(self::AUTHORIZATION_SERVICE) === false) {
			// The dependency check reports a missing OpenRegister; nothing to add here.
			return SetupResult::warning(
				$this->l10n->t('OpenRegister is not loaded, so its credential checks could not be probed.')
			);
		}

		try {
			$authorization = $this->container->get(self::AUTHORIZATION_SERVICE);
		} catch (Throwable $e) {
			return SetupResult::warning(
				$this->l10n->t('OpenRegister\'s AuthorizationService could not be resolved: %s', [$e->getMessage()])
			);
		}

		if (is_object($authorization) === false || OpenRegisterCredentialBridge::entryPointsAvailable(authorization: $authorization) === false) {
			return SetupResult::error(
				$this->l10n->t(
					'Integriq needs OpenRegister %s or newer. The installed OpenRegister does not offer the '
					. 'public credential checks Integriq delegates to, so every inbound call with a credential '
					. '(endpoints, SCIM, Notificaties callbacks, EUDI, LTI) is refused with 401 until OpenRegister is updated.',
					[self::MINIMUM_OPENREGISTER]
				)
			);
		}

		return SetupResult::success(
			$this->l10n->t('The installed OpenRegister offers the credential checks Integriq delegates to.')
		);
	}//end run()
}//end class
