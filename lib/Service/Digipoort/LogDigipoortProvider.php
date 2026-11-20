<?php

/**
 * Integriq Log Digipoort Provider.
 *
 * The sandbox binding of {@see DigipoortProviderInterface}: no network call,
 * no certificate, no secret. A delivery answers a reference derived from the
 * berichtsoort, the sender's kenmerk and the filer's identity, so the same
 * filing always gets the same reference. The status service answers the
 * scripted sequence on `configuration.mockStatuses`, or delivered then
 * accepted when the source scripts nothing. That is what lets the whole
 * filing path run before an organisation has its PKIoverheid certificate.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Digipoort
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
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#scenario-a-filing-on-a-new-instance
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Digipoort;

/**
 * Sandbox Digipoort provider: deterministic references, scripted statuses.
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#scenario-a-filing-on-a-new-instance
 */
class LogDigipoortProvider implements DigipoortProviderInterface {
	/**
	 * Prefix of every reference this provider hands out.
	 *
	 * @var string
	 */
	public const REFERENCE_PREFIX = 'LOG-SBR-';

	/**
	 * The statuses answered when the source scripts none.
	 *
	 * @var list<array{status:string,code:string,description:string}>
	 */
	private const DEFAULT_SEQUENCE = [
		['status' => 'delivered', 'code' => '100', 'description' => 'Aanleveren gelukt'],
		['status' => 'accepted', 'code' => '500', 'description' => 'Verwerking gelukt'],
	];

	/**
	 * The statuses a scripted entry may carry.
	 *
	 * @var list<string>
	 */
	private const STATUSES = ['delivered', 'accepted', 'rejected'];

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $configuration The source's `configuration` (not read: no secret, no endpoint).
	 * @param SbrDelivery         $delivery      The instance and its envelope values.
	 *
	 * @return string `LOG-SBR-` and twelve hex characters, the same for the same filing.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#scenario-a-filing-on-a-new-instance
	 */
	public function deliver(array $configuration, SbrDelivery $delivery): string {
		$seed = implode(
			separator: '|',
			array: [$delivery->berichtsoort, $delivery->aanleverkenmerk, $delivery->identityType, $delivery->identityNumber]
		);

		return self::REFERENCE_PREFIX . strtoupper(substr(hash('sha256', $seed), 0, 12));
	}//end deliver()

	/**
	 * {@inheritDoc}
	 *
	 * An entry of `configuration.mockStatuses` that names no known status is
	 * left out rather than guessed at.
	 *
	 * @param array<string,mixed> $configuration     The source's `configuration` (`mockStatuses`).
	 * @param string              $deliveryReference The reference `deliver()` returned.
	 *
	 * @return list<array{status:string,code:string,description:string,at:string,errors:list<array{code:string,text:string}>}>
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-every-status-change-is-reported-back-req-dps-003
	 */
	public function statuses(array $configuration, string $deliveryReference): array {
		$script = ($configuration['mockStatuses'] ?? self::DEFAULT_SEQUENCE);
		if (is_array($script) === false) {
			$script = self::DEFAULT_SEQUENCE;
		}

		$statuses = [];
		foreach ($script as $entry) {
			if (is_array($entry) === false || in_array(($entry['status'] ?? null), self::STATUSES, true) === false) {
				continue;
			}

			$statuses[] = [
				'status' => (string)$entry['status'],
				'code' => (string)($entry['code'] ?? ''),
				'description' => (string)($entry['description'] ?? ''),
				'at' => (string)($entry['at'] ?? ''),
				'errors' => $this->errors(errors: ($entry['errors'] ?? [])),
			];
		}

		return $statuses;
	}//end statuses()

	/**
	 * The scripted error codes and texts, each reduced to `{code, text}`.
	 *
	 * @param mixed $errors The scripted `errors` list.
	 *
	 * @return list<array{code:string,text:string}>
	 */
	private function errors(mixed $errors): array {
		if (is_array($errors) === false) {
			return [];
		}

		$result = [];
		foreach ($errors as $error) {
			if (is_array($error) === false) {
				continue;
			}

			$result[] = ['code' => (string)($error['code'] ?? ''), 'text' => (string)($error['text'] ?? '')];
		}

		return $result;
	}//end errors()
}//end class
