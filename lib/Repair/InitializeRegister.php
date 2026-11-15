<?php

/**
 * Integriq InitializeRegister Repair Step
 *
 * Imports the openconnector register + 15 schemas into OpenRegister on
 * install / upgrade. Repair-step counterpart to the fleet pattern
 * (decidesk, procest, pipelinq, scholiq, …): IRepairStep runs with all
 * enabled apps' autoloaders registered, so calling
 * `ConfigurationService::importFromApp()` works in fresh `occ app:enable`
 * flows where a Migration::postSchemaChange would silently skip (NC
 * doesn't fully bootstrap peer apps' PSR-4 paths before migrations
 * run).
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that imports the openconnector register descriptor into
 * OpenRegister via `ConfigurationService::importFromApp()`.
 *
 * Wired via `appinfo/info.xml` `<repair-steps>` under both `<install>`
 * (first enable) and `<post-migration>` (every subsequent `occ upgrade`).
 *
 * Idempotent — OR's importFromApp uses the descriptor's `version`
 * field to short-circuit when the existing register is up to date,
 * and we additionally guard the matching legacy → OR row migration
 * via the `storage_migrated` app-config flag.
 */
class InitializeRegister implements IRepairStep, InitializeRegisterInterface {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server DI container (used to
	 *                                      resolve OR's ConfigurationService
	 *                                      lazily so the class_exists guard
	 *                                      can short-circuit when OR isn't
	 *                                      enabled).
	 * @param IAppConfig $appConfig Used to gate the legacy-table
	 *                              migration on the
	 *                              `storage_migrated` flag.
	 * @param LoggerInterface $logger For non-fatal errors that
	 *                                shouldn't trip the IRepairStep
	 *                                contract.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Human-readable name surfaced by `occ` during install / upgrade.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Initialize Integriq register and 15 schemas via ConfigurationService';
	}//end getName()

	/**
	 * Import integriq_register.json into OpenRegister.
	 *
	 * @param IOutput $output progress/info channel piped to occ stdout
	 *
	 * @return void
	 *
	 * @spec openspec/specs/repair-and-app-boot/spec.md
	 */
	public function run(IOutput $output): void {
		$output->info('Integriq: initializing register + schemas via OR ConfigurationService…');

		$configurationService = $this->resolveConfigurationService(output: $output);
		if ($configurationService === null) {
			return;
		}

		$run = new RegisterImportRun(output: $output);
		if ($this->loadDescriptor(run: $run) === false) {
			return;
		}

		$run->version = $this->appConfig->getValueString('integriq', 'installed_version', '1.0.0');

		$this->mergeFragments(run: $run);
		$this->tagVersionWithFragments(run: $run);
		$this->importDescriptor(configurationService: $configurationService, run: $run);

	}//end run()

	/**
	 * Resolve OpenRegister's ConfigurationService, warning when it is unavailable.
	 *
	 * @param IOutput $output Progress/info channel.
	 *
	 * @return object|null The service, or null when the import must be skipped.
	 */
	private function resolveConfigurationService(IOutput $output): ?object {
		if ($this->isOpenRegisterAvailable(output: $output) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ConfigurationService');
		} catch (\Throwable $e) {
			$output->warning('Integriq: could not resolve OR ConfigurationService: ' . $e->getMessage());
			$this->logger->error(
				'Integriq: ConfigurationService resolution failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

	}//end resolveConfigurationService()

	/**
	 * Whether OpenRegister's ConfigurationService class is loadable; warns when not.
	 *
	 * @param IOutput $output Progress/info channel.
	 *
	 * @return bool
	 */
	private function isOpenRegisterAvailable(IOutput $output): bool {
		if (class_exists('\\OCA\\OpenRegister\\Service\\ConfigurationService') === true) {
			return true;
		}

		$output->warning('Integriq: OpenRegister is not installed or enabled. Skipping register initialization.');
		$this->logger->warning(
			'Integriq: OpenRegister not available, skipping register initialization'
		);
		return false;

	}//end isOpenRegisterAvailable()

	/**
	 * Read and decode the register descriptor into the run.
	 *
	 * @param RegisterImportRun $run The import run.
	 *
	 * @return bool False when the descriptor is missing or malformed.
	 */
	private function loadDescriptor(RegisterImportRun $run): bool {
		$descriptorPath = __DIR__ . '/../Settings/integriq_register.json';
		if (file_exists($descriptorPath) === false) {
			$run->output->warning('Integriq: register descriptor missing at ' . $descriptorPath . ' — nothing to import');
			return false;
		}

		return $this->decodeDescriptor(run: $run, descriptorPath: $descriptorPath);

	}//end loadDescriptor()

	/**
	 * Decode the register descriptor file into the run.
	 *
	 * @param RegisterImportRun $run            The import run.
	 * @param string            $descriptorPath Absolute path of the descriptor.
	 *
	 * @return bool False when the JSON does not parse.
	 */
	private function decodeDescriptor(RegisterImportRun $run, string $descriptorPath): bool {
		try {
			$run->descriptor = json_decode((string)file_get_contents($descriptorPath), true, flags: JSON_THROW_ON_ERROR);
		} catch (\Throwable $e) {
			$run->output->warning('Integriq: register descriptor JSON parse failed: ' . $e->getMessage());
			$this->logger->error(
				'Integriq: descriptor JSON parse failed',
				['exception' => $e->getMessage()]
			);
			return false;
		}

		return true;

	}//end decodeDescriptor()

	/**
	 * Merge every modular register fragment from Settings/register.d/*.json (ADR-037).
	 *
	 * Each OpenSpec change drops its own fragment file instead of editing the
	 * monolith, so concurrent builds touch disjoint files (no merge conflicts).
	 * OpenAPI `components.schemas` / `paths` are keyed objects, so disjoint
	 * fragments union cleanly by key.
	 *
	 * @param RegisterImportRun $run The import run.
	 *
	 * @return void
	 */
	private function mergeFragments(RegisterImportRun $run): void {
		$fragmentDir = __DIR__ . '/../Settings/register.d';
		if (is_dir($fragmentDir) === false) {
			return;
		}

		$fragmentFiles = glob($fragmentDir . '/*.json');
		sort($fragmentFiles);
		foreach ($fragmentFiles as $fragmentFile) {
			$this->mergeFragment(run: $run, fragmentFile: $fragmentFile);
		}

	}//end mergeFragments()

	/**
	 * Merge one fragment file into the run's descriptor and signature.
	 *
	 * @param RegisterImportRun $run          The import run.
	 * @param string            $fragmentFile Absolute path of the fragment.
	 *
	 * @return void
	 */
	private function mergeFragment(RegisterImportRun $run, string $fragmentFile): void {
		$fragmentContent = file_get_contents($fragmentFile);
		if ($fragmentContent === false) {
			return;
		}

		$fragmentData = json_decode($fragmentContent, true);
		if ($this->isMalformedFragment(output: $run->output, fragmentFile: $fragmentFile) === true) {
			return;
		}

		$run->descriptor         = self::deepMergeConfig(base: $run->descriptor, overlay: $fragmentData);
		$run->fragmentSignature .= basename($fragmentFile) . ':' . md5($fragmentContent) . ';';

	}//end mergeFragment()

	/**
	 * Whether the last json_decode() failed; warns and logs the skipped fragment when so.
	 *
	 * @param IOutput $output       Progress/info channel.
	 * @param string  $fragmentFile Absolute path of the fragment just decoded.
	 *
	 * @return bool
	 */
	private function isMalformedFragment(IOutput $output, string $fragmentFile): bool {
		if (json_last_error() === JSON_ERROR_NONE) {
			return false;
		}

		$message = 'Integriq: skipping malformed register fragment ' . basename($fragmentFile)
			. ': ' . json_last_error_msg();
		$output->warning($message);
		$this->logger->warning($message);
		return true;

	}//end isMalformedFragment()

	/**
	 * Fold the fragment signature into the version.
	 *
	 * OpenRegister's version-gated importFromApp then re-imports whenever
	 * fragments change.
	 *
	 * @param RegisterImportRun $run The import run.
	 *
	 * @return void
	 */
	private function tagVersionWithFragments(RegisterImportRun $run): void {
		if ($run->fragmentSignature !== '') {
			$run->version .= '+frag.' . substr(md5($run->fragmentSignature), 0, 8);
		}

	}//end tagVersionWithFragments()

	/**
	 * Hand the merged descriptor to OpenRegister, warning when the import fails.
	 *
	 * @param object            $configurationService OpenRegister's ConfigurationService.
	 * @param RegisterImportRun $run                  The import run.
	 *
	 * @return void
	 */
	private function importDescriptor(object $configurationService, RegisterImportRun $run): void {
		try {
			$this->callImportFromApp(configurationService: $configurationService, run: $run);
		} catch (\Throwable $e) {
			$run->output->warning('Integriq: register import failed: ' . $e->getMessage());
			$this->logger->error(
				'Integriq: importFromApp failed',
				['exception' => $e->getMessage()]
			);
		}

	}//end importDescriptor()

	/**
	 * Call importFromApp with the run's descriptor and version, then report success.
	 *
	 * @param object            $configurationService OpenRegister's ConfigurationService.
	 * @param RegisterImportRun $run                  The import run.
	 *
	 * @return void
	 */
	private function callImportFromApp(object $configurationService, RegisterImportRun $run): void {
		$configurationService->importFromApp(
			appId: AppIdentity::APP_ID,
			data: $run->descriptor,
			version: $run->version
		);
		$run->output->info('Integriq: register descriptor imported (existing schemas reused).');

	}//end callImportFromApp()

	/**
	 * Deep-merge a register fragment onto the base config (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`, `paths`) are
	 * merged by key union (recursing on shared keys); list arrays are concatenated;
	 * scalars in the fragment overwrite the base. Disjoint fragments never collide.
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge in.
	 *
	 * @return array<mixed> The merged config.
	 */
	private static function deepMergeConfig(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			$base[$key] = self::mergeConfigValue(base: ($base[$key] ?? null), overlay: $value);
		}

		return $base;
	}//end deepMergeConfig()

	/**
	 * Merge one fragment value onto the base value at the same key.
	 *
	 * Two lists concatenate, two other arrays merge recursively, anything else
	 * takes the fragment's value.
	 *
	 * @param mixed $base    The accumulated value, null when the key is absent.
	 * @param mixed $overlay The fragment's value.
	 *
	 * @return mixed The merged value.
	 */
	private static function mergeConfigValue(mixed $base, mixed $overlay): mixed {
		if (is_array($overlay) === false || is_array($base) === false) {
			return $overlay;
		}

		if (self::isList(value: $base) === true && self::isList(value: $overlay) === true) {
			return array_merge($base, $overlay);
		}

		return self::deepMergeConfig(base: $base, overlay: $overlay);
	}//end mergeConfigValue()

	/**
	 * Whether an array is a list (empty, or keyed 0..n-1 in order).
	 *
	 * @param array<mixed> $value The array to inspect.
	 *
	 * @return bool
	 */
	private static function isList(array $value): bool {
		return ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
	}//end isList()
}//end class
