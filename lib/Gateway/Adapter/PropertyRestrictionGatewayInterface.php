<?php

/**
 * Contract of the WkpbGateway.
 *
 * @category Contract
 * @package  OCA\Integriq\Gateway\Adapter
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

namespace OCA\Integriq\Gateway\Adapter;

use OCA\Integriq\Gateway\GatewayDelivery;

/**
 * Public operations of {@see WkpbGateway} that other components call.
 */
interface PropertyRestrictionGatewayInterface {
	/**
	 * The gateway id.
	 */
	public const ID = 'wkpb';

	/**
	 * Register one restriction.
	 *
	 * @param string $propertyReference The property the restriction sits on.
	 * @param array<string,mixed> $restriction The restriction.
	 * @param array<string,mixed> $config The gateway's configuration.
	 *
	 * @return GatewayDelivery What happened.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on WkpbGateway::register()
	 */
	public function register(string $propertyReference, array $restriction, array $config = []): GatewayDelivery;
}
