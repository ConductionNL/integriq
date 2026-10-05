<?php

/**
 * Integriq Webhook Profile.
 *
 * What one signed public webhook needs to run on the consumer model: the
 * consumer `authorizationType` that holds its trust and its account, the
 * schema its account writes, the rights it needs there, and the alert
 * channel. {@see WebhookConnection} takes a profile, so every webhook shares
 * the one mechanism the Open Formulieren intake introduced.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Intake
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
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Intake;

/**
 * One webhook's consumer type, written schema, rights and alert channel.
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
 */
final class WebhookProfile {

	/**
	 * The signature header when the consumer's trust names none.
	 *
	 * @var string
	 */
	public const DEFAULT_HEADER = 'X-OpenConnector-Signature';

	/**
	 * The signature scheme when the consumer's trust names none.
	 *
	 * @var string
	 */
	public const DEFAULT_SCHEME = 'openconnector';

	/**
	 * Constructor.
	 *
	 * @param string       $authorizationType The consumer `authorizationType`, lower case.
	 * @param string       $channel           The alert and error-code channel, letters only.
	 * @param string       $label             The partner's name, for messages.
	 * @param string       $schema            The schema the account writes.
	 * @param list<string> $requiredActions   The rights the account needs on that schema.
	 * @param string       $legacySourceType  The `source.type` that held the trust before.
	 * @param string|null  $legacyChannelId   The `source.configuration.channelId` too, for intake channels.
	 * @param string       $defaultHeader     The signature header when the trust names none.
	 * @param string       $defaultScheme     The signature scheme when the trust names none.
	 * @param string|null  $intakeGroup       The group the schema's authorization block grants the account, or null when it grants no group.
	 * @param string|null  $handlerGroup      The group that reads what the webhook stores, or null.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 * @spec openspec/changes/intake-message-and-verdict-access-rules/specs/intake-access/spec.md#requirement-intake-messages-and-verdicts-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-iac-002
	 */
	public function __construct(
		public readonly string $authorizationType,
		public readonly string $channel,
		public readonly string $label,
		public readonly string $schema,
		public readonly array $requiredActions = ['create', 'update'],
		public readonly string $legacySourceType = '',
		public readonly ?string $legacyChannelId = null,
		public readonly string $defaultHeader = self::DEFAULT_HEADER,
		public readonly string $defaultScheme = self::DEFAULT_SCHEME,
		public readonly ?string $intakeGroup = null,
		public readonly ?string $handlerGroup = null,
	) {

	}//end __construct()
}//end class
