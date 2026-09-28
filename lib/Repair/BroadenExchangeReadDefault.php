<?php

/**
 * Repair step that moves an installed instance to the D33 default for
 * `exchange.read`, once, and only while the value is still untouched.
 *
 * `InitializeActions` seeds the action matrix only when it is empty, so a new
 * value in `lib/actions.seed.json` never reaches an installed instance. This
 * step carries the one value D33 changed: `exchange.read` moves from
 * `["admin"]` to `["admin", "coordinators", "compliance-officers"]`.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exchange-read-defaults/specs/action-authorization/spec.md#requirement-req-002-an-upgrade-broadens-only-the-untouched-default
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use JsonException;
use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\ActionAuthService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Broadens `exchange.read` from the untouched `["admin"]` default to D33's groups.
 *
 * Decision rule on the stored matrix:
 * - empty: nothing, the seeding step owns an empty matrix;
 * - entry absent: write the new default (an absent entry reads as `["admin"]`);
 * - entry exactly `["admin"]`: write the new default;
 * - anything else: an administrator chose it, leave it.
 *
 * The step runs once. A marker in IAppConfig stops a later upgrade from
 * broadening an `["admin"]` that an administrator set back on purpose.
 *
 * @spec openspec/changes/exchange-read-defaults/specs/action-authorization/spec.md#requirement-req-002-an-upgrade-broadens-only-the-untouched-default
 */
class BroadenExchangeReadDefault implements IRepairStep {

	public const ACTION = 'exchange.read';

	public const OLD_DEFAULT = ['admin'];

	public const NEW_DEFAULT = ['admin', 'coordinators', 'compliance-officers'];

	public const MARKER_KEY = 'exchange_read_default_d33_applied';

	/**
	 * Constructor.
	 *
	 * @param ActionAuthService $actionAuth The action matrix store.
	 * @param IAppConfig        $appConfig  Holds the run-once marker.
	 * @param LoggerInterface   $logger     PSR logger.
	 */
	public function __construct(
		private readonly ActionAuthService $actionAuth,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/exchange-read-defaults/specs/action-authorization/spec.md#requirement-req-002-an-upgrade-broadens-only-the-untouched-default
	 */
	public function getName(): string {
		return 'Give exchange.read its D33 default when it was never changed';
	}//end getName()

	/**
	 * Broaden the untouched default, once.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/exchange-read-defaults/specs/action-authorization/spec.md#requirement-req-002-an-upgrade-broadens-only-the-untouched-default
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool(Application::APP_ID, self::MARKER_KEY, false) === true) {
			return;
		}

		$matrix = $this->actionAuth->getMatrix();
		if (count($matrix) === 0) {
			// The seeding step owns an empty matrix; try again on the next upgrade.
			return;
		}

		$current = ($matrix[self::ACTION] ?? self::OLD_DEFAULT);
		if ($current !== self::OLD_DEFAULT) {
			$this->markDone();
			$output->info('exchange.read was changed by an administrator; left as it is.');
			return;
		}

		$matrix[self::ACTION] = self::NEW_DEFAULT;
		try {
			$this->actionAuth->setMatrix(matrix: $matrix);
		} catch (JsonException $e) {
			$output->warning('Could not write exchange.read default: '.$e->getMessage());
			$this->logger->error('[integriq] exchange.read default not written: '.$e->getMessage());
			return;
		}

		$this->markDone();
		$output->info('exchange.read now defaults to admin, coordinators and compliance-officers (D33).');
		$this->logger->info('[integriq] exchange.read broadened from the untouched admin default (D33).');
	}//end run()

	/**
	 * Record that the step has run, so it never runs again.
	 *
	 * @return void
	 */
	private function markDone(): void {
		$this->appConfig->setValueBool(Application::APP_ID, self::MARKER_KEY, true);
	}//end markDone()
}//end class
