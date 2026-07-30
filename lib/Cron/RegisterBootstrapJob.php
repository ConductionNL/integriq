<?php

/**
 * Ensure this app's OpenRegister register is bootstrapped, from cron.
 *
 * The register/schema configuration is normally installed by the
 * `InitializeRegister` repair step, which Nextcloud runs on `occ upgrade`. That
 * is the correct home for it. But a repair step can legitimately not have run —
 * a bind-mounted development checkout whose version never changes, a restore
 * from a backup taken mid-upgrade, an app enabled without an upgrade cycle — and
 * the app then looks broken.
 *
 * The tempting fix is to call the repair step from `Application::boot()`, which
 * definitely runs. That is what this app used to do. It was gated on
 * `register_bootstrapped_version` and the gate worked, so it cost nothing in
 * practice — but it left a repair step one unset config key away from running on
 * every single request to the instance. The sibling case proves the risk is not
 * theoretical: docudesk shipped the same shape with a gate whose key was never
 * written, and imported its whole configuration on every request for ~28 % of
 * every request's time (docudesk#351).
 *
 * ADR-076 rule 4: when a repair step "might not have run", the fallback belongs
 * in a cron job, where the cost is bounded and visible, not in `boot()`, where
 * it is unbounded and invisible.
 *
 * Hourly. The condition this corrects — "the register was never installed" —
 * changes only when someone installs or upgrades the app, so an hour of
 * eventual consistency after an unusual install path is an appropriate trade
 * against per-request cost.
 *
 * @category Cron
 * @package  OCA\OpenConnector\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Repair\InitializeRegister;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the register bootstrap repair step when it has not run for this version.
 */
class RegisterBootstrapJob extends TimedJob
{
    /**
     * The app whose config keys gate this job.
     *
     * @var string
     */
    private const APP_ID = 'openconnector';

    /**
     * Hourly. See the class docblock for why an hour is the right number.
     *
     * @var integer
     */
    private const INTERVAL_SECONDS = 3600;

    /**
     * Constructor.
     *
     * @param ITimeFactory      $time      Clock for the job base class.
     * @param IAppConfig        $appConfig Reads and persists the bootstrap marker.
     * @param InitializeRegister $repair   The repair step this job stands in for.
     * @param LoggerInterface   $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly IAppConfig $appConfig,
        private readonly InitializeRegister $repair,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL_SECONDS);

        // Installing a register is not latency-critical; let the scheduler pick
        // the window.
        $this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

        // The repair step is idempotent, but two concurrent installs of the same
        // register is a pointless race — one instance only.
        $this->setAllowParallelRuns(allow: false);

    }//end __construct()

    /**
     * Run the bootstrap when this version has not been bootstrapped yet.
     *
     * @param mixed $argument Unused.
     *
     * @return void
     */
    protected function run($argument): void
    {
        $installedVersion    = $this->appConfig->getValueString(self::APP_ID, 'installed_version', '');
        $bootstrappedVersion = $this->appConfig->getValueString(self::APP_ID, 'register_bootstrapped_version', '');

        if ($installedVersion === '' || $bootstrappedVersion === $installedVersion) {
            return;
        }

        try {
            $this->repair->run($this->silentOutput());

            // Persist the marker in the same path that did the work, so the two
            // cannot be separated by a later edit. A gate whose key is only ever
            // read is not a gate — that is exactly how docudesk ended up
            // importing its configuration on every request.
            $this->appConfig->setValueString(
                self::APP_ID,
                'register_bootstrapped_version',
                $installedVersion
            );

            $this->logger->info(
                '[RegisterBootstrapJob] Bootstrapped the openconnector register for version '.$installedVersion
            );
        } catch (Throwable $e) {
            // Leave the marker unset so the next pass retries. Warn rather than
            // info: a register that never installed means the app's own surfaces
            // do not work, and that must be visible at the default log level.
            $this->logger->warning(
                '[RegisterBootstrapJob] Could not bootstrap the register: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try

    }//end run()

    /**
     * An IOutput that discards everything.
     *
     * The repair step's contract requires one; a cron run has nowhere to write
     * migration chatter, and the outcome is already logged above.
     *
     * @return IOutput A no-op output sink.
     */
    private function silentOutput(): IOutput
    {
        return new class implements IOutput {

            /**
             * Discard a debug message.
             *
             * @param string $message Ignored.
             *
             * @return void
             */
            public function debug(string $message): void
            {
            }//end debug()

            /**
             * Discard an info message.
             *
             * @param string $message Ignored.
             *
             * @return void
             */
            public function info($message): void
            {
            }//end info()

            /**
             * Discard a warning.
             *
             * @param string $message Ignored.
             *
             * @return void
             */
            public function warning($message): void
            {
            }//end warning()

            /**
             * Ignore the step count.
             *
             * @param integer $max Ignored.
             *
             * @return void
             */
            public function startProgress($max=0): void
            {
            }//end startProgress()

            /**
             * Ignore progress advances.
             *
             * @param integer $step        Ignored.
             * @param string  $description Ignored.
             *
             * @return void
             */
            public function advance($step=1, $description=''): void
            {
            }//end advance()

            /**
             * Ignore progress completion.
             *
             * @return void
             */
            public function finishProgress(): void
            {
            }//end finishProgress()
        };

    }//end silentOutput()
}//end class
