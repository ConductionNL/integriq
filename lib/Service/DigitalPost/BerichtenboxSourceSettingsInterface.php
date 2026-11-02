<?php

/**
 * Contract of the BerichtenboxSourceSettings.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\DigitalPost
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

namespace OCA\Integriq\Service\DigitalPost;

/**
 * Public operations of {@see BerichtenboxSourceSettings} that other components call.
 */
interface BerichtenboxSourceSettingsInterface {
	/**
	 * The plain settings an administrator may set, and nothing else.
	 */
	public const FIELDS = ['senderOin', 'adapterUrl', 'cpaId', 'fromPartyId', 'toPartyId', 'service', 'wusEndpoint'];

	/**
	 * Apply the administrator's settings to a raw source.
	 *
	 * @param array<string,mixed> $data The raw source.
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return array{data:array<string,mixed>,warnings:array<int,string>} The source to save, and warnings.
	 *
	 * @throws BerichtenboxSettingsRefusal When a setting is wrong; nothing is applied then.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
	 */
	public function apply(array $data, array $params): array;

	/**
	 * Whether the live binding is on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-live-binding-speaks-the-interface-logius-publishes-req-dpa-008
	 */
	public function live(): bool;

	/**
	 * The source as the settings page shows it: no PEM, no key, no token.
	 *
	 * @param array<string,mixed> $data The raw source.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#scenario-a-send-names-a-certificate-reference-not-a-key
	 */
	public function describe(array $data): array;
}
