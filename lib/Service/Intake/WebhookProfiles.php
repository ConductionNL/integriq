<?php

/**
 * Integriq Webhook Profiles.
 *
 * The signed public webhooks that run on the consumer model, one profile each.
 * Before this change each of them read its trust from an admin-only `source`
 * with RBAC on. Without a session that read returned nothing, so every
 * correctly signed delivery was answered 401 "invalid signature" (proven live
 * on 2026-10-04, see the change's proposal).
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
 * The profile of every webhook on the consumer model.
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) a catalogue: one named factory per webhook, plus the lookups.
 */
final class WebhookProfiles {

	/**
	 * The consumer type prefix of an intake channel; the channel id follows.
	 *
	 * @var string
	 */
	public const INTAKE_CHANNEL_PREFIX = 'intake-channel-';

	/**
	 * The consumer types the controllers name. A string, so a controller needs no static call.
	 *
	 * @var string
	 */
	public const PEPPOL = 'peppol-webhook';

	/**
	 * NotifyNL status callbacks.
	 *
	 * @var string
	 */
	public const NOTIFYNL = 'notifynl-webhook';

	/**
	 * ROD retours.
	 *
	 * @var string
	 */
	public const ROD = 'rod-webhook';

	/**
	 * OSO imports and retours.
	 *
	 * @var string
	 */
	public const OSO = 'oso-webhook';

	/**
	 * UWLR and Edu-V retours.
	 *
	 * @var string
	 */
	public const UWLR_EDUV = 'uwlr-eduv-webhook';

	/**
	 * Verzuimloket retours.
	 *
	 * @var string
	 */
	public const VERZUIMLOKET = 'verzuimloket-webhook';

	/**
	 * iWMO and iJW retours.
	 *
	 * @var string
	 */
	public const IWMO_IJW = 'iwmo-ijw-webhook';

	/**
	 * StUF-ZKN kennisgevingen.
	 *
	 * @var string
	 */
	public const STUF_ZKN = 'stuf-zkn-webhook';

	/**
	 * The intake channels the controller accepts, plus the verdicts channel.
	 *
	 * @var list<string>
	 */
	public const INTAKE_CHANNELS = ['form-submission', 'teams', 'public-space-report', 'messaging', 'verdicts'];

	/**
	 * Every fixed profile, keyed by authorization type.
	 *
	 * @return array<string, WebhookProfile>
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public static function all(): array {
		$profiles = [
			self::peppol(),
			self::notifyNl(),
			self::rod(),
			self::oso(),
			self::uwlrEduV(),
			self::verzuimloket(),
			self::iwmoIjw(),
			self::stufZkn(),
		];
		foreach (self::INTAKE_CHANNELS as $channelId) {
			$profiles[] = self::intakeChannel(channelId: $channelId);
		}

		$keyed = [];
		foreach ($profiles as $profile) {
			$keyed[$profile->authorizationType] = $profile;
		}

		return $keyed;

	}//end all()

	/**
	 * The profile of an authorization type, or null when none runs on it.
	 *
	 * @param string $authorizationType The consumer `authorizationType`, any case.
	 *
	 * @return WebhookProfile|null
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public static function byAuthorizationType(string $authorizationType): ?WebhookProfile {
		$type = strtolower($authorizationType);
		if (str_starts_with($type, self::INTAKE_CHANNEL_PREFIX) === true && strlen($type) > strlen(self::INTAKE_CHANNEL_PREFIX)) {
			return self::intakeChannel(channelId: substr($type, strlen(self::INTAKE_CHANNEL_PREFIX)));
		}

		return (self::all()[$type] ?? null);

	}//end byAuthorizationType()

	/**
	 * The profile of an alert channel, or null when none uses it.
	 *
	 * @param string $channel The channel.
	 *
	 * @return WebhookProfile|null
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public static function byChannel(string $channel): ?WebhookProfile {
		foreach (self::all() as $profile) {
			if ($profile->channel === $channel) {
				return $profile;
			}
		}

		return null;

	}//end byChannel()

	/**
	 * Peppol access point callbacks.
	 *
	 * @return WebhookProfile
	 *
	 * @spec openspec/changes/peppol-inbound-on-the-consumer-model/specs/peppol-access-point-connector/spec.md#requirement-the-inbound-webhook-acts-as-the-peppol-connections-account-req-020
	 */
	public static function peppol(): WebhookProfile {
		return new WebhookProfile(
			authorizationType: self::PEPPOL,
			channel: 'peppol',
			label: 'Peppol',
			schema: 'peppol_transmission',
			legacySourceType: 'peppol'
		);

	}//end peppol()

	/**
	 * NotifyNL delivery status callbacks.
	 *
	 * @return WebhookProfile
	 *
	 * @spec openspec/changes/notifynl-inbound-on-the-consumer-model/specs/notifynl-sms-channel/spec.md#requirement-the-status-callback-acts-as-the-notifynl-connections-account-req-020
	 */
	public static function notifyNl(): WebhookProfile {
		return new WebhookProfile(
			authorizationType: self::NOTIFYNL,
			channel: 'notifynl',
			label: 'NotifyNL',
			schema: 'sms_message',
			legacySourceType: 'sms'
		);

	}//end notifyNl()

	/**
	 * ROD retour berichten.
	 *
	 * @return WebhookProfile
	 *
	 * @spec openspec/changes/rod-retour-on-the-consumer-model/specs/rod-adapter/spec.md#requirement-the-retour-acts-as-the-rod-connections-account-req-020
	 */
	public static function rod(): WebhookProfile {
		return new WebhookProfile(
			authorizationType: self::ROD,
			channel: 'rod',
			label: 'ROD',
			schema: 'rod_message',
			legacySourceType: 'rod'
		);

	}//end rod()

	/**
	 * OSO overstapdossiers and retours.
	 *
	 * @return WebhookProfile
	 *
	 * @spec openspec/changes/oso-inbound-on-the-consumer-model/specs/oso-adapter/spec.md#requirement-the-import-and-the-retour-act-as-the-oso-connections-account-req-020
	 */
	public static function oso(): WebhookProfile {
		return new WebhookProfile(
			authorizationType: self::OSO,
			channel: 'oso',
			label: 'OSO',
			schema: 'oso_message',
			legacySourceType: 'oso'
		);

	}//end oso()

	/**
	 * UWLR and Edu-V retours.
	 *
	 * @return WebhookProfile
	 *
	 * @spec openspec/changes/uwlr-eduv-retour-on-the-consumer-model/specs/uwlr-eduv-adapter/spec.md#requirement-the-retour-acts-as-the-uwlr-and-edu-v-connections-account-req-020
	 */
	public static function uwlrEduV(): WebhookProfile {
		return new WebhookProfile(
			authorizationType: self::UWLR_EDUV,
			channel: 'uwlreduv',
			label: 'UWLR/Edu-V',
			schema: 'uwlr_eduv_message',
			legacySourceType: 'uwlr-eduv'
		);

	}//end uwlrEduV()

	/**
	 * Verzuimloket retours.
	 *
	 * @return WebhookProfile
	 *
	 * @spec openspec/changes/verzuimloket-retour-on-the-consumer-model/specs/verzuimloket-adapter/spec.md#requirement-the-retour-acts-as-the-verzuimloket-connections-account-req-020
	 */
	public static function verzuimloket(): WebhookProfile {
		return new WebhookProfile(
			authorizationType: self::VERZUIMLOKET,
			channel: 'verzuimloket',
			label: 'Verzuimloket',
			schema: 'verzuim_message',
			legacySourceType: 'verzuimloket'
		);

	}//end verzuimloket()

	/**
	 * iWMO and iJW retourberichten.
	 *
	 * @return WebhookProfile
	 *
	 * @spec openspec/changes/iwmo-ijw-retour-on-the-consumer-model/specs/iwmo-ijw-adapter/spec.md#requirement-the-retour-acts-as-the-iwmo-and-ijw-connections-account-req-020
	 */
	public static function iwmoIjw(): WebhookProfile {
		return new WebhookProfile(
			authorizationType: self::IWMO_IJW,
			channel: 'iwmoijw',
			label: 'iWMO/iJW',
			schema: 'iwmo_ijw_message',
			legacySourceType: 'iwmo-ijw'
		);

	}//end iwmoIjw()

	/**
	 * StUF-ZKN kennisgevingen.
	 *
	 * @return WebhookProfile
	 *
	 * @spec openspec/changes/stuf-zkn-inbound-on-the-consumer-model/specs/stuf-zkn-bridge/spec.md#requirement-the-inbound-endpoint-acts-as-the-stuf-zkn-connections-account-req-020
	 */
	public static function stufZkn(): WebhookProfile {
		return new WebhookProfile(
			authorizationType: self::STUF_ZKN,
			channel: 'stufzkn',
			label: 'StUF-ZKN',
			schema: 'stuf_message',
			legacySourceType: 'stuf-zkn'
		);

	}//end stufZkn()

	/**
	 * One intake channel, or the verdicts channel.
	 *
	 * @param string $channelId The channel id, as in the route and in `source.configuration.channelId`.
	 *
	 * @return WebhookProfile
	 *
	 * @spec openspec/changes/intake-channels-on-the-consumer-model/specs/intake-channels/spec.md#requirement-a-channel-acts-as-its-connections-account-req-ic-020
	 * @spec openspec/changes/intake-message-and-verdict-access-rules/specs/intake-access/spec.md#requirement-intake-messages-and-verdicts-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-iac-002
	 */
	public static function intakeChannel(string $channelId): WebhookProfile {
		$schema = 'intake_message';
		$label = 'Intake channel ' . $channelId;
		$intakeGroup = IntakeGroups::INTAKE_CHANNELS_INTAKE;
		$handlerGroup = IntakeGroups::INTAKE_CHANNELS_HANDLERS;
		if ($channelId === 'verdicts') {
			$schema = 'verdict';
			$label = 'Verdicts';
			$intakeGroup = IntakeGroups::VERDICTS_INTAKE;
			$handlerGroup = IntakeGroups::VERDICTS_HANDLERS;
		}

		return new WebhookProfile(
			authorizationType: self::INTAKE_CHANNEL_PREFIX . $channelId,
			channel: 'intake' . (string)preg_replace('/[^a-z]/', '', strtolower($channelId)),
			label: $label,
			schema: $schema,
			legacySourceType: 'intake-channel',
			legacyChannelId: $channelId,
			intakeGroup: $intakeGroup,
			handlerGroup: $handlerGroup
		);

	}//end intakeChannel()
}//end class
