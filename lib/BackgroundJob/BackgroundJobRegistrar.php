<?php

/**
 * Integriq BackgroundJobRegistrar.
 *
 * Registers the container bindings the BackgroundJob component offers to the
 * rest of the app.
 *
 * @category BackgroundJob
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each job scheduling contract to the scheduler that queues its job, so
 * callers outside the BackgroundJob component depend on the contract only.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class BackgroundJobRegistrar {
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
		$context->registerServiceAlias(EventFanOutSchedulerInterface::class, ProcessEventJobScheduler::class);
		$context->registerServiceAlias(MessageRetrySchedulerInterface::class, MessageRetryJobScheduler::class);
		$context->registerServiceAlias(TraceExportSchedulerInterface::class, OtelExportJobScheduler::class);
		$context->registerServiceAlias(AttachmentFetchSchedulerInterface::class, AttachmentFetchJobScheduler::class);
	}
}
