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
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\AppInfo\Application;
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
class InitializeRegister implements IRepairStep {
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

		if (class_exists('\\OCA\\OpenRegister\\Service\\ConfigurationService') === false) {
			$output->warning('Integriq: OpenRegister is not installed or enabled. Skipping register initialization.');
			$this->logger->warning(
				'Integriq: OpenRegister not available, skipping register initialization'
			);
			return;
		}

		try {
			$configurationService = $this->container->get('OCA\\OpenRegister\\Service\\ConfigurationService');
		} catch (\Throwable $e) {
			$output->warning('Integriq: could not resolve OR ConfigurationService: ' . $e->getMessage());
			$this->logger->error(
				'Integriq: ConfigurationService resolution failed',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$descriptorPath = __DIR__ . '/../Settings/integriq_register.json';
		if (file_exists($descriptorPath) === false) {
			$output->warning('Integriq: register descriptor missing at ' . $descriptorPath . ' — nothing to import');
			return;
		}

		try {
			$descriptor = json_decode((string)file_get_contents($descriptorPath), true, flags: JSON_THROW_ON_ERROR);
		} catch (\Throwable $e) {
			$output->warning('Integriq: register descriptor JSON parse failed: ' . $e->getMessage());
			$this->logger->error(
				'Integriq: descriptor JSON parse failed',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$appVersion = $this->appConfig->getValueString('integriq', 'installed_version', '1.0.0');

		// ADR-037: merge modular register fragments from Settings/register.d/*.json.
		// Each OpenSpec change drops its own fragment file instead of editing this
		// monolith, so concurrent builds touch disjoint files (no merge conflicts).
		// OpenAPI `components.schemas` / `paths` are keyed objects, so disjoint
		// fragments union cleanly by key.
		$fragmentDir = __DIR__ . '/../Settings/register.d';
		$fragmentSig = '';
		if (is_dir($fragmentDir) === true) {
			$fragmentFiles = glob($fragmentDir . '/*.json');
			sort($fragmentFiles);
			foreach ($fragmentFiles as $fragmentFile) {
				$fragmentContent = file_get_contents($fragmentFile);
				if ($fragmentContent === false) {
					continue;
				}

				$fragmentData = json_decode($fragmentContent, true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					$output->warning(
						'Integriq: skipping malformed register fragment ' . basename($fragmentFile)
						. ': ' . json_last_error_msg()
					);
					$this->logger->warning(
						'Integriq: skipping malformed register fragment ' . basename($fragmentFile)
						. ': ' . json_last_error_msg()
					);
					continue;
				}

				$descriptor = self::deepMergeConfig(base: $descriptor, overlay: $fragmentData);
				$fragmentSig .= basename($fragmentFile) . ':' . md5($fragmentContent) . ';';
			}//end foreach
		}//end if

		// Fold the fragment signature into the version so OpenRegister's
		// version-gated importFromApp re-imports whenever fragments change.
		if ($fragmentSig !== '') {
			$appVersion .= '+frag.' . substr(md5($fragmentSig), 0, 8);
		}

		try {
			$configurationService->importFromApp(
				appId: Application::APP_ID,
				data: $descriptor,
				version: $appVersion
			);
			$output->info('Integriq: register descriptor imported (existing schemas reused).');
		} catch (\Throwable $e) {
			$output->warning('Integriq: register import failed: ' . $e->getMessage());
			$this->logger->error(
				'Integriq: importFromApp failed',
				['exception' => $e->getMessage()]
			);
		}

	}//end run()

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
			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
			) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
				$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
				if ($baseIsList === true && $overlayIsList === true) {
					$base[$key] = array_merge($base[$key], $value);
				} else {
					$base[$key] = self::deepMergeConfig(base: $base[$key], overlay: $value);
				}
			} else {
				$base[$key] = $value;
			}
		}

		return $base;
	}//end deepMergeConfig()
}//end class
