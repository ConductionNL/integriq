<?php

/**
 * Integriq SWV Hand-off Source Adapter (dormant).
 *
 * Source-pattern facade over {@see \OCA\Integriq\Adapters\Swv\SwvHandoffClient}.
 * Ships dormant: every call routes through the mock subclass and is
 * logged at DEBUG so downstream consumers (learniq's `swv`
 * `DataExchangeJob`) can develop and test against a stable surface
 * without contacting Kindkans or LDOS.
 *
 * Lives under `lib/Sources/Swv/` so it can be discovered by the
 * integriq Source registry. Two Source rows share this one class —
 * `swv-kindkans`, `swv-ldos` — distinguished only by the
 * `$receiverId` passed into each call, per `lib/sources.seed.json`.
 *
 * Pupil-identifying values (`pupilReference`, any BSN-shaped value)
 * are NEVER passed to the structured logger — only a dossier-type
 * summary and the configured `isActive()`/`flavour()` state.
 *
 * @category Source
 * @package  OCA\Integriq\Sources\Swv
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
 * @spec openspec/specs/swv-handoff/spec.md#requirement-source-adapter-maps-an-already-composed-dossier-onto-the-receivers-envelope-req-002
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Swv;

use OCA\Integriq\Adapters\Swv\SwvHandoffClient;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Dormant source adapter for the SWV hand-off to Kindkans-shaped and
 * LDOS-shaped receivers.
 *
 * Until `swv.handoff.feature_flag` is flipped to `1`, every call
 * routes to the canned mock acknowledgement and logs a single debug
 * entry so operators can verify the wiring without contacting a
 * receiver.
 *
 * @spec openspec/specs/swv-handoff/spec.md#requirement-source-adapter-maps-an-already-composed-dossier-onto-the-receivers-envelope-req-002
 */
final class SwvHandoffSourceAdapter {
	/**
	 * App id used for IAppConfig look-ups.
	 */
	public const APP_ID = 'integriq';

	/**
	 * App-config key for the dormant-flag toggle.
	 */
	public const FLAG_KEY = 'swv.handoff.feature_flag';

	/**
	 * App-config key recording who holds the Privacyconvenant
	 * verwerkersovereenkomst centrally for this instance. Purely
	 * informational — see design.md "Privacyconvenant holder —
	 * operational gate, not a code blocker".
	 */
	public const PRIVACYCONVENANT_HOLDER_KEY = 'swv.privacyconvenant.holder';

	/**
	 * Source category — matches the `category` used in the seeded
	 * `lib/sources.seed.json` rows for this family.
	 */
	public const SOURCE_CATEGORY = 'onderwijs';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App-config service (feature-flag +
	 *                           Privacyconvenant-holder look-up).
	 * @param LoggerInterface $logger Structured logger.
	 * @param SwvHandoffClient $swvClient Resolved client (mock or http).
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
		private readonly SwvHandoffClient $swvClient,
	) {
	}//end __construct()

	/**
	 * Whether the live SWV hand-off transport is enabled by the
	 * operator. Deliberately independent of
	 * {@see self::privacyconvenantHolder()} — an unset holder never
	 * blocks this.
	 *
	 * @return bool True when `swv.handoff.feature_flag` is `1` / `true`.
	 *
	 * @spec openspec/specs/swv-handoff/spec.md#requirement-source-adapter-maps-an-already-composed-dossier-onto-the-receivers-envelope-req-002
	 */
	public function isActive(): bool {
		$raw = $this->config->getValueString(self::APP_ID, self::FLAG_KEY, '0');
		return ($raw === '1' || strtolower($raw) === 'true');
	}//end isActive()

	/**
	 * Who holds the Privacyconvenant verwerkersovereenkomst centrally
	 * for this instance, if recorded. Empty string when unset — this
	 * is a governance record, not a gate: no caller of this method
	 * may use its return value to refuse a hand-off.
	 *
	 * @return string The recorded holder, or `''` when unset.
	 *
	 * @spec openspec/specs/swv-handoff/spec.md#requirement-the-privacyconvenant-holder-question-is-recorded-never-enforced-req-004
	 */
	public function privacyconvenantHolder(): string {
		return $this->config->getValueString(self::APP_ID, self::PRIVACYCONVENANT_HOLDER_KEY, '');
	}//end privacyconvenantHolder()

	/**
	 * Hand off an already-composed SWV dossier to one receiver.
	 *
	 * @param string $receiverId One of `swv-kindkans`, `swv-ldos`.
	 * @param array<string,mixed> $dossier The already-composed SWV
	 *                                     dossier (support-request +
	 *                                     TLV fields), as learniq's
	 *                                     `DataExchangePayloadBuilder`
	 *                                     produces it.
	 *
	 * @return array<string,mixed> Receiver acknowledgement.
	 *
	 * @spec openspec/specs/swv-handoff/spec.md#requirement-source-adapter-maps-an-already-composed-dossier-onto-the-receivers-envelope-req-002
	 */
	public function handOffDossier(string $receiverId, array $dossier): array {
		$this->logger->debug(
			'swv-handoff.handOffDossier',
			[
				'source' => $receiverId,
				'category' => self::SOURCE_CATEGORY,
				'dossierType' => (string)($dossier['requestType'] ?? 'unknown'),
				'tlvRequested' => (bool)($dossier['tlvRequested'] ?? false),
				'active' => $this->isActive(),
				'flavour' => $this->swvClient->flavour(),
				'privacyconvenantHolder' => $this->privacyconvenantHolder(),
			]
		);

		return $this->swvClient->handOff(receiverId: $receiverId, dossier: $dossier);
	}//end handOffDossier()
}//end class
