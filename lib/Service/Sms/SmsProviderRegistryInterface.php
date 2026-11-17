<?php

/**
 * Contract of the SmsProviderRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Sms;


/**
 * Public operations of {@see SmsProviderRegistry} that other components call.
 */
interface SmsProviderRegistryInterface {

	/**
	 * Every binding this instance carries, described for the source form.
	 *
	 * @return list<array{providerId: string, name: string, configSchema: array<string, mixed>, statusCallback: array{signed: bool}|null}>
	 *         One entry per binding, log first.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
	 */
	public function describeAll(): array;
}//end interface
