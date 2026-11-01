<?php

/**
 * Integriq AdaptersBindings.
 *
 * Registers the container bindings the Adapters component offers to the
 * rest of the app.
 *
 * @category Adapters
 * @package  OCA\Integriq\Adapters
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

namespace OCA\Integriq\Adapters;

use GuzzleHttp\Client as GuzzleHttpClient;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClient;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientHttp;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientMock;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxLetterBuilder;
use OCA\Integriq\Adapters\Berichtenbox\LetterBuilderInterface;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolverInterface;
use OCA\Integriq\Adapters\Digikoppeling\SignedSoapProfileInterface;
use OCA\Integriq\Adapters\Digikoppeling\WusProfileService;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClient as AdapterPdokGeocodingClient;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClientHttp;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClientMock;
use OCA\Integriq\Adapters\Pdok\PdokWfsClient;
use OCA\Integriq\Adapters\Pdok\PdokWfsClientHttp;
use OCA\Integriq\Adapters\Pdok\PdokWfsClientMock;
use OCA\Integriq\Adapters\Pdok\PdokWmsClient;
use OCA\Integriq\Adapters\Pdok\PdokWmsClientHttp;
use OCA\Integriq\Adapters\Pdok\PdokWmsClientMock;
use OCA\Integriq\Adapters\Roster\RosterImportClient;
use OCA\Integriq\Adapters\Roster\RosterImportClientMock;
use OCA\Integriq\Adapters\Slo\SloCurriculumClient;
use OCA\Integriq\Adapters\Slo\SloCurriculumClientHttp;
use OCA\Integriq\Adapters\Slo\SloCurriculumClientMock;
use OCA\Integriq\Adapters\Slo\SloCurriculumMapper;
use OCA\Integriq\Adapters\Slo\SloCurriculumMapperInterface;
use OCA\Integriq\Adapters\Slo\SloCurriculumPresetRegistry;
use OCA\Integriq\Adapters\Slo\SloCurriculumPresetRegistryInterface;
use OCA\Integriq\Adapters\Slo\SloCurriculumTreeWalker;
use OCA\Integriq\Adapters\Slo\SloCurriculumTreeWalkerInterface;
use OCA\Integriq\Adapters\Swv\SwvHandoffClient;
use OCA\Integriq\Adapters\Swv\SwvHandoffClientMock;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Adapters contract to the class that implements it, so callers
 * outside the Adapters component depend on the contract only.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 *
 * Naming every class it wires is what a registrar is for, so the coupling
 * between objects is high by design.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
final class AdaptersBindings {
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
		$context->registerServiceAlias(LetterBuilderInterface::class, BerichtenboxLetterBuilder::class);
		$context->registerServiceAlias(SignedSoapProfileInterface::class, WusProfileService::class);
		$context->registerServiceAlias(SloCurriculumTreeWalkerInterface::class, SloCurriculumTreeWalker::class);
		$context->registerServiceAlias(SloCurriculumPresetRegistryInterface::class, SloCurriculumPresetRegistry::class);
		$context->registerServiceAlias(SloCurriculumMapperInterface::class, SloCurriculumMapper::class);
		$context->registerServiceAlias(PkiOverheidCredentialResolverInterface::class, PkiOverheidCredentialResolver::class);
		$this->registerPdokClients(context: $context);
		$this->registerDormantSourceClients(context: $context);
		$this->registerSwvHandoffClient(context: $context);
		$this->registerPdokHttpClients(context: $context);
		$this->registerBerichtenboxClient(context: $context);
	}
	/**
	 * Registers the PDOK client bindings, live or mock by feature flag.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerPdokClients(IRegistrationContext $context): void {
		// Dormant + active PDOK source-pattern adapters (lib/Sources/Pdok/).
		//
		// The abstract `PdokWmsClient`, `PdokWfsClient`, and
		// `PdokGeocodingClient` (lib/Adapters/Pdok/) resolve to the `*ClientHttp`
		// implementation when the `pdok.feature_flag` app-config flag is `'1'` or
		// `'true'`, and to the `*ClientMock` implementation (deterministic canned
		// responses, no network access) otherwise.
		$isPdokActive = static function ($c): bool {
			$config = $c->get('OCP\IAppConfig');
			$raw = $config->getValueString('integriq', 'pdok.feature_flag', '0');
			return ($raw === '1' || strtolower($raw) === 'true');
		};

		$bindings = [
			PdokWmsClient::class => [PdokWmsClientHttp::class, PdokWmsClientMock::class],
			PdokWfsClient::class => [PdokWfsClientHttp::class, PdokWfsClientMock::class],
			AdapterPdokGeocodingClient::class => [PdokGeocodingClientHttp::class, PdokGeocodingClientMock::class],
		];
		foreach ($bindings as $abstract => [$http, $mock]) {
			$context->registerService(
				$abstract,
				static function ($c) use ($isPdokActive, $http, $mock) {
					if ($isPdokActive($c) === true) {
						return $c->get($http);
					}

					return $c->get($mock);
				}
			);
		}
	}//end registerPdokClients()

	/**
	 * Registers the dormant SLO curriculum and rostering clients.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerDormantSourceClients(IRegistrationContext $context): void {
		// Dormant SLO curriculum adapter (slo-kerndoelen-import, lib/Sources/Slo/).
		// The abstract `SloCurriculumClient` resolves to the recorded-fixture
		// mock until `slo.curriculum.feature_flag` is '1' or 'true'; then to
		// the live client, which calls SLO through CallService with the
		// seeded `slo-curriculum` source (that source also stays disabled
		// until an operator enters SLO's API key).
		$context->registerService(
			SloCurriculumClient::class,
			static function ($c) {
				$live = ['1' => SloCurriculumClientHttp::class, 'true' => SloCurriculumClientHttp::class];
				$raw = strtolower($c->get('OCP\IAppConfig')->getValueString('integriq', SloCurriculumClient::FLAG_KEY, '0'));

				return $c->get($live[$raw] ?? SloCurriculumClientMock::class);
			}
		);

		// Dormant rostering adapter (rostering-adapter-targets-planninq). The
		// abstract `RosterImportClient` had no binding, so the adapter could
		// not be constructed on an instance at all. No live client exists
		// yet (each rostering system needs its own institution onboarding,
		// D9), so the binding is the mock whatever the feature flag says; a
		// live binding adds the flag branch the way SloCurriculumClient does.
		$context->registerService(
			RosterImportClient::class,
			static function ($c) {
				return $c->get(RosterImportClientMock::class);
			}
		);
	}//end registerDormantSourceClients()

	/**
	 * Binds the SWV hand-off client to its dormant mock, the only implementation.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerSwvHandoffClient(IRegistrationContext $context): void {
		$context->registerServiceAlias(SwvHandoffClient::class, SwvHandoffClientMock::class);
	}//end registerSwvHandoffClient()

	/**
	 * Registers the live PDOK HTTP clients.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerPdokHttpClients(IRegistrationContext $context): void {
		// Explicit factories for the *ClientHttp flavours so the Guzzle
		// ClientInterface is injected via a shared singleton; NC's
		// auto-wiring can't construct GuzzleHttp\Client directly because
		// ClientInterface is an interface.
		$context->registerService(
			PdokGeocodingClientHttp::class,
			static function ($c) {
				return new PdokGeocodingClientHttp(
					httpClient: new GuzzleHttpClient(),
					logger: $c->get('Psr\Log\LoggerInterface')
				);
			}
		);
		$context->registerService(
			PdokWmsClientHttp::class,
			static function ($c) {
				return new PdokWmsClientHttp(
					httpClient: new GuzzleHttpClient(),
					logger: $c->get('Psr\Log\LoggerInterface')
				);
			}
		);
		$context->registerService(
			PdokWfsClientHttp::class,
			static function ($c) {
				return new PdokWfsClientHttp(
					httpClient: new GuzzleHttpClient(),
					logger: $c->get('Psr\Log\LoggerInterface')
				);
			}
		);
	}//end registerPdokHttpClients()

	/**
	 * Registers the Berichtenbox client, live or mock by feature flag.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerBerichtenboxClient(IRegistrationContext $context): void {
		// MijnOverheid Berichtenbox. The client resolves to the mock until
		// `logius.berichtenbox.feature_flag` is set, and to the live binding
		// after (REQ-DPA-005, REQ-DPA-008). The live binding refuses a source
		// that lacks a value it needs and names each one; the mock is never
		// served to a flagged instance, so a simulated delivery cannot pass
		// for a real one.
		$context->registerService(
			BerichtenboxClient::class,
			static function ($c) {
				$config = $c->get('OCP\IAppConfig');
				$raw = $config->getValueString('integriq', 'logius.berichtenbox.feature_flag', '0');
				$live = ($raw === '1' || strtolower($raw) === 'true');

				if ($live === true) {
					return $c->get(BerichtenboxClientHttp::class);
				}

				return $c->get(BerichtenboxClientMock::class);
			}
		);
	}//end registerBerichtenboxClient()
}
