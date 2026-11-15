<?php

/**
 * Integriq PerUserAccountNotConnectedException.
 *
 * A per-user source was called by a signed-in user who has not connected their
 * own account at the provider yet. Carries the provider so the caller can send
 * the user to the credential broker's connect flow.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-without-a-connected-account-is-sent-to-connect-it-req-puoa-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * The calling user has no connected account at the source's provider.
 *
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-without-a-connected-account-is-sent-to-connect-it-req-puoa-002
 */
class PerUserAccountNotConnectedException extends BrokeredCallConfigurationException {

	/**
	 * Constructor.
	 *
	 * @param string $provider The provider slug the user has no account at.
	 */
	public function __construct(
		private readonly string $provider,
	) {
		parent::__construct(
			message: 'This source calls with each user\'s own account at "' . $provider . '", and you have not connected '
				. 'yours yet. Connect it through the credential broker and try again.'
		);
	}//end __construct()

	/**
	 * The provider slug the user has no account at.
	 *
	 * @return string The provider.
	 *
	 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-without-a-connected-account-is-sent-to-connect-it-req-puoa-002
	 */
	public function getProvider(): string {
		return $this->provider;
	}//end getProvider()
}//end class
