<?php

/**
 * Contract of the WebhookTrustMigrator.
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
 * Public operations of {@see WebhookTrustMigrator} that other components call.
 */
interface WebhookTrustMigratorInterface {
	/**
	 * Create the webhook's consumer from the legacy source trust, once.
	 *
	 * @param WebhookProfile $profile     The webhook.
	 * @param string|null    $name        The consumer name; the profile label plus "webhook" by default.
	 * @param string|null    $description The consumer description; a generic one by default.
	 *
	 * @return bool True when a consumer was created.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-upgrade-moves-the-source-trust-into-the-consumer
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemWrite exposes only a static
	 * entry point, as in MigrateDsoStamConnection.
	 */
	public function migrate(WebhookProfile $profile, ?string $name = null, ?string $description = null): bool;
}
