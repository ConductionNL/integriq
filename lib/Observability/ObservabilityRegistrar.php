<?php

/**
 * Integriq ObservabilityRegistrar.
 *
 * Registers the container bindings the Observability component offers to the
 * rest of the app.
 *
 * @category Observability
 * @package  OCA\Integriq\Observability
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

namespace OCA\Integriq\Observability;

use OCA\Integriq\Observability\Otel\SpanMapper;
use OCA\Integriq\Observability\Otel\SpanMapperInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Observability contract to the class that implements it, so callers
 * outside the Observability component depend on the contract only.
 */
final class ObservabilityRegistrar {
	/**
	 * Register the contract aliases on the app's registration context.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it binds each contract to its class and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(SpanMapperInterface::class, SpanMapper::class);
	}
}
