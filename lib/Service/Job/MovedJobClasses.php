<?php

/**
 * Integriq Moved Job Classes.
 *
 * The job classes that moved out of `OCA\Integriq\Action` and
 * `OCA\Integriq\BackgroundJob`, mapped from their old name to their current
 * one. Their names are stored data: a job object's `jobClass` holds the action
 * class, and an `oc_jobs` row holds a queued Nextcloud background job.
 * {@see \OCA\Integriq\Repair\MigrateMovedJobClasses} rewrites those values,
 * and the aliases registered here keep a value written under the old name
 * running until it has been rewritten.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Job
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Job;

use OCA\Integriq\Service\Synchronization\FetchFilesJob;

/**
 * Old-to-new names of the moved job classes, and the aliases for the old names.
 *
 * @spec exclude Class-move plumbing: keeps stored class names resolvable and
 *       adds no behaviour of its own.
 */
final class MovedJobClasses {
	/**
	 * Old class name => current class name.
	 *
	 * The keys are plain strings on purpose: those classes no longer exist.
	 * OpenRegister's schedule allow-list and data imported from other apps
	 * still write the old action names, so the aliases stay until they do not.
	 *
	 * @var array<string, class-string>
	 */
	public const NEW_NAMES = [
		'OCA\Integriq\Action\EventAction' => EventAction::class,
		'OCA\Integriq\Action\ExchangeJobAction' => ExchangeJobAction::class,
		'OCA\Integriq\Action\FlowAction' => FlowAction::class,
		'OCA\Integriq\Action\PingAction' => PingAction::class,
		'OCA\Integriq\Action\SynchronizationAction' => SynchronizationAction::class,
		'OCA\Integriq\BackgroundJob\FetchFilesJob' => FetchFilesJob::class,
	];

	/**
	 * Whether the alias autoloader is registered in this process.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Make every old name resolve to its moved class, lazily, on first use.
	 *
	 * Safe to call more than once: the autoloader is registered once per process.
	 *
	 * @return void
	 *
	 * @spec exclude Class-move plumbing: keeps stored old class names
	 *       resolvable and adds no behaviour of its own.
	 */
	public static function registerAliases(): void {
		if (self::$registered === true) {
			return;
		}

		self::$registered = true;
		spl_autoload_register(
			static function (string $class): void {
				if (isset(self::NEW_NAMES[$class]) === true) {
					class_alias(self::NEW_NAMES[$class], $class);
				}
			}
		);
	}//end registerAliases()
}//end class
