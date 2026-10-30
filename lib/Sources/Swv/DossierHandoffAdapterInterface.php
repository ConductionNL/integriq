<?php

/**
 * Contract of the SwvHandoffSourceAdapter.
 *
 * @category Contract
 * @package  OCA\Integriq\Sources\Swv
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

namespace OCA\Integriq\Sources\Swv;

/**
 * Public operations of {@see SwvHandoffSourceAdapter} that other components call.
 */
interface DossierHandoffAdapterInterface {
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
	public function isActive(): bool;

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
	public function privacyconvenantHolder(): string;

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
	public function handOffDossier(string $receiverId, array $dossier): array;
}
