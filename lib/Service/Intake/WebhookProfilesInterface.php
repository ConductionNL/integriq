<?php

/**
 * Contract of the WebhookProfiles.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Intake
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

namespace OCA\Integriq\Service\Intake;

/**
 * Public operations of {@see WebhookProfiles} that other components call.
 */
interface WebhookProfilesInterface {
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
	 * Every fixed profile, keyed by authorization type, for injected callers.
	 *
	 * @return array<string, WebhookProfile>
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public function profiles(): array;

	/**
	 * The profile of an authorization type, for injected callers.
	 *
	 * @param string $authorizationType The consumer `authorizationType`, any case.
	 *
	 * @return WebhookProfile|null
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public function forAuthorizationType(string $authorizationType): ?WebhookProfile;

	/**
	 * The profile of an alert channel, for injected callers.
	 *
	 * @param string $channel The channel.
	 *
	 * @return WebhookProfile|null
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public function forChannel(string $channel): ?WebhookProfile;
}
