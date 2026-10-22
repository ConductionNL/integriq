<?php
/**
 * Integriq DefaultConnectorService.
 *
 * Imports the `$gate: "default-connectors"`-tagged fragments under
 * `lib/Settings/register.d/*.json` — the pre-built demo source connectors
 * (AusTender, BRP HaalCentraal, KVK, TenderNed, Twilio, WhatsApp, and more),
 * each a real `source` (+ paired `mapping`/`synchronization`/`job`) object.
 *
 * 🔴 ON DEMAND ONLY, NEVER ON INSTALL. Same house rule as DemoDataService.php:10-13 —
 * `InitializeRegister` explicitly strips `components.objects` from every
 * `$gate`-tagged fragment before merging it (see the comment at its merge loop),
 * so this service is the only path these connectors' Source/mapping/sync/job
 * objects can reach an instance through. A schema the same fragment also
 * defines still lands via `InitializeRegister` regardless of this opt-in —
 * only the demo DATA is gated. Installing a Source nobody asked for, with
 * `isEnabled: true`, is a defect, not a convenience.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Settings\RegisterFragmentMerger;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Imports the shipped default source connectors on request.
 *
 * @spec exclude Default-connector import; ADR-111 rule 1, no per-app behavioural spec.
 */
class DefaultConnectorService {
	/**
	 * App-relative path to the fragment directory holding `$gate`-tagged connectors.
	 *
	 * @var string
	 */
	private const FRAGMENT_DIR = '/lib/Settings/register.d';

	/**
	 * The `$gate` value a fragment carries to mark it a default-connector fragment.
	 *
	 * @var string
	 */
	private const GATE_VALUE = 'default-connectors';

	/**
	 * Configuration identity for the connector import.
	 *
	 * 🔴 ITS OWN NAMESPACE, not the app id and not DemoDataService's `.demo`
	 * namespace. Sharing an identity would make this import's version gate mask,
	 * or be masked by, the real configuration import or the mock-data import.
	 *
	 * @var string
	 */
	private const CONFIG_APP_ID = Application::APP_ID . '.connectors';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Resolves this app's path and version.
	 * @param ContainerInterface $container  Resolves OpenRegister's importer.
	 * @param LoggerInterface    $logger     Records what was imported.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this app ships any default connectors at all.
	 *
	 * @return boolean True when at least one `$gate`-tagged fragment is present.
	 *
	 * @spec exclude Default-connector availability probe; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function isAvailable(): bool {
		return $this->gatedFragmentFiles() !== [];
	}//end isAvailable()

	/**
	 * The answer that means "install nothing".
	 *
	 * 🔴 NOT THE ABSENCE OF AN ANSWER. Same reasoning as DemoDataService::NONE_DATASET
	 * — an operator who declines has FINISHED the step.
	 *
	 * @var string
	 */
	public const NONE_DATASET = 'none';

	/**
	 * The id of the single "install everything shipped" choice.
	 *
	 * One binary choice, not one toggle per connector: the wizard offers "install
	 * the default connectors" or "none", not 34 individual cards.
	 *
	 * @var string
	 */
	public const ALL_DATASET = 'default-connectors';

	/**
	 * App-config key holding which choice the operator picked.
	 *
	 * Shared with `SyncConnectionDeclarations`, which gates its own
	 * `app_connection` row creation on the same decision — a connection-status
	 * row for a Source that was never installed is noise, not information.
	 *
	 * @var string
	 */
	public const DATASET_KEY = 'default_connectors_dataset';

	/**
	 * Every answer the wizard's choice step may offer, declining included.
	 *
	 * 🔴 THE SERVER OWNS THIS LIST, same reasoning as
	 * DemoDataService::listChoices() — the count comes from the fragments that
	 * will actually be imported, not a number restated in the manifest.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer, icon: string}> The answers.
	 *
	 * @spec exclude Default-connector choice list; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function listChoices(): array {
		$choices = [
			[
				'id'          => self::NONE_DATASET,
				'label'       => 'None, I will add my own sources',
				'description' => 'Nothing is imported. You start with no pre-built source connectors.',
				'objectCount' => 0,
				'icon'        => 'CloseCircleOutline',
			],
		];

		$objects = $this->shippedObjectCount();
		if ($objects > 0) {
			$choices[] = [
				'id'    => self::ALL_DATASET,
				'label' => 'Default source connectors',
				'description' => (
					'Pre-built connectors for public APIs and services — government '
					. 'procurement registers, population/business lookups, SMS and '
					. 'translation providers, and more — plus a starter Local/Acceptance '
					. 'environment pair and a few feature mapping templates. Each ships '
					. 'disabled or with mock/public endpoints; add real credentials before '
					. 'relying on one.'
				),
				'objectCount' => $objects,
				'icon'        => 'ConnectionOutline',
			];
		}

		return $choices;

	}//end listChoices()

	/**
	 * Every `register.d/*.json` fragment carrying the default-connector `$gate`.
	 *
	 * @return array<int, string> Absolute paths, sorted.
	 */
	private function gatedFragmentFiles(): array {
		$fragmentDir = $this->appManager->getAppPath(Application::APP_ID) . self::FRAGMENT_DIR;
		if (is_dir($fragmentDir) === false) {
			return [];
		}

		$files = glob($fragmentDir . '/*.json');
		if ($files === false) {
			return [];
		}

		sort($files);

		$gated = [];
		foreach ($files as $file) {
			$raw = file_get_contents($file);
			if ($raw === false) {
				continue;
			}

			$data = json_decode($raw, true);
			if (is_array($data) === false) {
				continue;
			}

			if (($data['$gate'] ?? null) === self::GATE_VALUE) {
				$gated[] = $file;
			}
		}

		return $gated;

	}//end gatedFragmentFiles()

	/**
	 * How many objects the gated fragments carry in total.
	 *
	 * Counted from the FILES, so the card promises the number that will actually
	 * be imported.
	 *
	 * @return integer The total object count across every gated fragment.
	 */
	private function shippedObjectCount(): int {
		$total = 0;
		foreach ($this->gatedFragmentFiles() as $file) {
			$raw = file_get_contents($file);
			if ($raw === false) {
				continue;
			}

			$data = json_decode($raw, true);
			if (is_array($data) === false) {
				continue;
			}

			$components = ($data['components'] ?? []);
			if (is_array($components) === true && is_array(($components['objects'] ?? null)) === true) {
				$total += count($components['objects']);
			}
		}

		return $total;

	}//end shippedObjectCount()

	/**
	 * Import the default source connectors.
	 *
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. Same reasoning as
	 * DemoDataService::install() — an operator who just asked for this must not
	 * be told it succeeded when it did not.
	 *
	 * @return array{objects: integer, registers: integer, schemas: integer} What was imported.
	 *
	 * @throws RuntimeException When no gated fragment exists, or OpenRegister is absent.
	 *
	 * @spec exclude Default-connector import; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function install(): array {
		$files = $this->gatedFragmentFiles();
		if ($files === []) {
			throw new RuntimeException('No default source connectors ship with this app.');
		}

		$descriptor = [];
		$objects    = 0;
		foreach ($files as $file) {
			$raw = file_get_contents($file);
			if ($raw === false) {
				throw new RuntimeException('A default connector fragment could not be read: ' . $file);
			}

			$data = json_decode($raw, true);
			if (is_array($data) === false) {
				throw new RuntimeException('A default connector fragment is not valid JSON: ' . $file);
			}

			$components = ($data['components'] ?? []);
			if (is_array($components) === true && is_array(($components['objects'] ?? null)) === true) {
				$objects += count($components['objects']);
			}

			$descriptor = RegisterFragmentMerger::merge(base: $descriptor, overlay: $data);
		}

		$result = $this->configurationService()->importFromApp(
			appId: self::CONFIG_APP_ID,
			data: $descriptor,
			version: $this->appManager->getAppVersion(Application::APP_ID),
			force: true
		);

		$imported = [
			'objects'   => $objects,
			'registers' => count((array)($result['registers'] ?? [])),
			'schemas'   => count((array)($result['schemas'] ?? [])),
		];

		$this->logger->info(
			'[DefaultConnectorService] imported default connectors: '
			. $imported['objects'] . ' object(s), '
			. $imported['registers'] . ' register(s), '
			. $imported['schemas'] . ' schema(s).',
			['app' => Application::APP_ID]
		);

		return $imported;
	}//end install()

	/**
	 * OpenRegister's configuration importer.
	 *
	 * 🔴 A CROSS-APP CLASS IS A RUNTIME LOOKUP, same reasoning as
	 * DemoDataService::configurationService().
	 *
	 * @return object The importer — an OCA\OpenRegister\Service\ConfigurationService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\ConfigurationService
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function configurationService(): object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new RuntimeException('Default source connectors need OpenRegister, which is not installed.');
		}

		return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
	}//end configurationService()
}//end class
