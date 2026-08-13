<?php

/**
 * OpenConnector — Digikoppeling adapter catalogue descriptor.
 *
 * ADR-017 Rule 1: a new adapter family ships as a CARD in the *Adapters*
 * catalogue plus a configuration schema — never as a new top-level menu item or
 * a `/beheer` route. This descriptor is that catalogue entry for Digikoppeling
 * (Logius M2M transport): it declares the adapter's identity, category, the
 * transport profiles it offers, and the JSON configuration schema a
 * Verbinding (source/endpoint) fills in to select the adapter.
 *
 * The adapter provides TRANSPORT only. StUF/ZGW message bodies remain owned by
 * the content services ({@see \OCA\OpenConnector\Service\StUFXMLBuilder} etc.);
 * this adapter signs and delivers the envelope (D1 — composition, not merge).
 *
 * @category Adapter
 * @package  OCA\OpenConnector\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Adapters\Digikoppeling;

/**
 * Catalogue descriptor for the Digikoppeling transport adapter (ADR-017 Rule 1).
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.ShortMethodName)
 */
final class DigikoppelingAdapter {

	/**
	 * Stable adapter id.
	 *
	 * @var string
	 */
	public const ID = 'digikoppeling';

	/**
	 * WUS synchronous (bevragingen) transport profile.
	 *
	 * @var string
	 */
	public const PROFILE_WUS = 'wus';

	/**
	 * The ebMS2 asynchronous reliable-messaging (meldingen) transport profile.
	 *
	 * @var string
	 */
	public const PROFILE_EBMS2 = 'ebms2';

	/**
	 * Catalogue id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md
	 */
	public function id(): string {
		return self::ID;
	}//end id()

	/**
	 * Human-readable catalogue label.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md
	 */
	public function label(): string {
		return 'Digikoppeling';
	}//end label()

	/**
	 * Adapters catalogue category (Dutch government M2M transport).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md
	 */
	public function category(): string {
		return 'government';
	}//end category()

	/**
	 * ADR-017 Rule 1: an adapter family adds NO top-level menu.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md
	 */
	public function addsTopLevelMenu(): bool {
		return false;
	}//end addsTopLevelMenu()

	/**
	 * ADR-017 Rule 1: an adapter family adds NO per-adapter /beheer route.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md
	 */
	public function addsBeheerRoute(): bool {
		return false;
	}//end addsBeheerRoute()

	/**
	 * The transport profiles this adapter offers.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md
	 */
	public function profiles(): array {
		return [self::PROFILE_WUS, self::PROFILE_EBMS2];
	}//end profiles()

	/**
	 * The configuration schema a Verbinding fills in to use this adapter.
	 *
	 * Captures the fields REQ-DK-001 mandates: transport profile, partner OIN,
	 * service + action, endpoint URL, the PKIoverheid `certificateRef`, and the
	 * ebMS2 reliable-messaging parameters. The `certificateRef` names a broker
	 * credential — the private key is NEVER stored here (REQ-DK-005 / ADR-007).
	 *
	 * @return array<string, mixed> A JSON-schema fragment.
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: Digikoppeling adapter is a catalogue entry, not a menu (REQ-DK-001)
	 */
	public function configSchema(): array {
		return [
			'type' => 'object',
			'title' => 'Digikoppeling',
			'required' => ['profile', 'oin', 'endpoint', 'certificateRef'],
			'properties' => [
				'profile' => [
					'type' => 'string',
					'enum' => [self::PROFILE_WUS, self::PROFILE_EBMS2],
					'title' => 'Transport profile',
					'description' => 'WUS (synchronous bevragingen) or ebMS2 (asynchronous reliable meldingen).',
				],
				'oin' => [
					'type' => 'string',
					'title' => 'Partner OIN',
					'description' => 'Partner organisation identifier (OIN).',
				],
				'service' => [
					'type' => 'string',
					'title' => 'Service',
					'description' => 'Digikoppeling service name.',
				],
				'action' => [
					'type' => 'string',
					'title' => 'Action',
					'description' => 'Digikoppeling action name.',
				],
				'endpoint' => [
					'type' => 'string',
					'format' => 'uri',
					'title' => 'Endpoint URL',
					'description' => 'Partner endpoint URL (two-way TLS).',
				],
				'certificateRef' => [
					'type' => 'string',
					'title' => 'PKIoverheid certificate reference',
					'description' => 'Broker credentialRef for the PKIoverheid certificate + key. Never stored here (ADR-007).',
				],
				'reliableMessaging' => [
					'type' => 'object',
					'title' => 'Reliable messaging (ebMS2)',
					'description' => 'ebMS2 reliable-messaging parameters.',
					'properties' => [
						'retryBudget' => [
							'type' => 'integer',
							'minimum' => 0,
							'title' => 'Retry budget',
							'description' => 'Maximum retransmissions before the message is dead-lettered.',
						],
						'retryIntervalSeconds' => [
							'type' => 'integer',
							'minimum' => 1,
							'title' => 'Retry interval (seconds)',
							'description' => 'Seconds between retransmission attempts.',
						],
					],
				],
				'groteBerichtenThresholdBytes' => [
					'type' => 'integer',
					'minimum' => 1,
					'title' => 'Grote Berichten threshold (bytes)',
					'description' => 'Payloads larger than this are transferred out-of-band by reference (URL + checksum).',
				],
			],
		];

	}//end configSchema()
}//end class
