<?php

/**
 * Integriq setup check: the digital post account.
 *
 * Warns when a digital post source is configured and the account digital post
 * is stored as is missing or unusable, because every letter is then refused.
 *
 * @category SetupCheck
 * @package  OCA\Integriq\SetupCheck
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-the-setup-check-names-an-unusable-account
 */

declare(strict_types=1);

namespace OCA\Integriq\SetupCheck;

use OCA\Integriq\Service\DigitalPost\DigitalPostAccount;
use OCA\Integriq\Service\DigitalPost\DigitalPostService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Says whether digital post can be stored.
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-the-setup-check-names-an-unusable-account
 */
class DigitalPostAccountCheck implements ISetupCheck {

	/**
	 * The OpenRegister object service, resolved lazily so the check runs without OpenRegister.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the account and OpenRegister lazily.
	 * @param IL10N              $l10n      The texts.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-the-setup-check-names-an-unusable-account
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * The category on the overview page.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-the-setup-check-names-an-unusable-account
	 */
	public function getCategory(): string {
		return 'system';

	}//end getCategory()

	/**
	 * The name on the overview page.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-the-setup-check-names-an-unusable-account
	 */
	public function getName(): string {
		return $this->l10n->t('Integriq: digital post account');

	}//end getName()

	/**
	 * Check the account.
	 *
	 * @return SetupResult
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-the-setup-check-names-an-unusable-account
	 */
	public function run(): SetupResult {
		try {
			$state = $this->container->get(DigitalPostAccount::class)->describe();
		} catch (Throwable) {
			return SetupResult::success($this->l10n->t('Digital post needs OpenRegister, which is not available.'));
		}

		if ($state['state'] === 'ok') {
			return SetupResult::success($this->l10n->t('Digital post is stored as %s.', [$state['displayName']]));
		}

		if ($state['configured'] === false && $this->hasDigitalPostSource() === false) {
			return SetupResult::success($this->l10n->t('No digital post source is configured.'));
		}

		return SetupResult::warning(
			$this->l10n->t(
				'Digital post is refused and not stored: %s Choose the digital post account under Administration settings, Integriq.',
				[$state['message']]
			)
		);

	}//end run()

	/**
	 * Whether any source sends digital post. A configuration read, so RBAC is off.
	 *
	 * @return bool
	 */
	private function hasDigitalPostSource(): bool {
		try {
			$result = $this->container->get(self::OBJECT_SERVICE)->findAll(
				config: ['filters' => ['register' => DigitalPostService::REGISTER, 'schema' => 'source']],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return false;
		}

		foreach (($result['results'] ?? $result) as $source) {
			if ($source instanceof ObjectEntity && (string)($source->getObject()['type'] ?? '') === 'digital-post') {
				return true;
			}
		}

		return false;

	}//end hasDigitalPostSource()
}//end class
