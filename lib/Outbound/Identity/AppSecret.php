<?php

/**
 * Integriq AppSecret.
 *
 * An instance secret kept in app config: read once per instance, because a
 * sensitive app-config value is decrypted on every read, and minted the first
 * time it is needed (64 alphanumeric characters, stored sensitive and lazy).
 * The recipient-key hash and the unsubscribe-token signature each keep their
 * own secret under their own key.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-digital-post-recipient-is-never-stored-as-a-plain-bsn-req-ooa-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * One app-config secret, minted once and kept.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-digital-post-recipient-is-never-stored-as-a-plain-bsn-req-ooa-008
 */
final class AppSecret {

	/**
	 * The secret, once read.
	 *
	 * @var string|null
	 */
	private ?string $cache = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig Holds the secret.
	 * @param ISecureRandom $random    Mints the secret the first time one is needed.
	 * @param string        $configKey The app-config key the secret lives under.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ISecureRandom $random,
		private readonly string $configKey,
	) {
	}//end __construct()

	/**
	 * The secret, minted once and kept.
	 *
	 * @return string The secret.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-digital-post-recipient-is-never-stored-as-a-plain-bsn-req-ooa-008
	 */
	public function value(): string {
		if ($this->cache !== null) {
			return $this->cache;
		}

		$secret = $this->appConfig->getValueString('integriq', $this->configKey, '');
		if (trim($secret) !== '') {
			$this->cache = $secret;
			return $secret;
		}

		$secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->appConfig->setValueString('integriq', $this->configKey, $secret, false, true);
		$this->cache = $secret;

		return $secret;

	}//end value()
}//end class
