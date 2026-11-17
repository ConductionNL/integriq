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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
use OCA\Integriq\Adapters\LearningMaterial\EdurepHttpTransport;
use OCA\Integriq\Adapters\LearningMaterial\EdurepSourceHttp;
use OCA\Integriq\Adapters\LearningMaterial\EdurepSourceMock;
use OCA\Integriq\Adapters\LearningMaterial\LearningMaterialSource;
use OCA\Integriq\Adapters\LearningMaterial\SruTransport;
use OCA\Integriq\Adapters\Lvs\UwlrResultImportClient;
use OCA\Integriq\Adapters\Lvs\UwlrResultImportClientHttp;
use OCA\Integriq\Adapters\Lvs\UwlrResultImportClientMock;
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
use OCA\Integriq\Adapters\Roster\RosterImportClientZermelo;
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
use OCA\Integriq\Adapters\Swv\SwvHandoffClientHttp;
use OCA\Integriq\Adapters\Swv\SwvHandoffClientMock;
use OCA\Integriq\Sources\Lvs\UwlrResultImportSourceAdapter;
use OCA\Integriq\Sources\Roster\RosterImportSourceAdapter;
use OCA\Integriq\Sources\Swv\SwvHandoffSourceAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;

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
		$this->registerLearningMaterialSource(context: $context);
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
		// Dormant SLO curriculum adapter (slo-kerndoelen-import, lib/Adapters/Slo/).
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

		// Rostering adapter (rostering-adapter-targets-planninq). The abstract
		// `RosterImportClient` resolves to the mock until
		// `roster.import.feature_flag` is on, then to the live Zermelo client
		// (connectors-education-live-clients REQ-007). The live client refuses
		// any other system rather than answering from the mock.
		$context->registerService(
			RosterImportClient::class,
			static fn ($c) => $c->get(
				self::liveOrMock(
					appConfig: $c->get('OCP\IAppConfig'),
					flagKey: RosterImportSourceAdapter::FLAG_KEY,
					live: RosterImportClientZermelo::class,
					mock: RosterImportClientMock::class
				)
			)
		);

		// UWLR result import (connectors-education-live-clients REQ-004):
		// the mock until `lvs.import.feature_flag` is on, the live client after.
		$context->registerService(
			UwlrResultImportClient::class,
			static fn ($c) => $c->get(
				self::liveOrMock(
					appConfig: $c->get('OCP\IAppConfig'),
					flagKey: UwlrResultImportSourceAdapter::FLAG_KEY,
					live: UwlrResultImportClientHttp::class,
					mock: UwlrResultImportClientMock::class
				)
			)
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
		// The mock until `swv.handoff.feature_flag` is on, the live client after
		// (connectors-education-live-clients REQ-005).
		$context->registerService(
			SwvHandoffClient::class,
			static fn ($c) => $c->get(
				self::liveOrMock(
					appConfig: $c->get('OCP\IAppConfig'),
					flagKey: SwvHandoffSourceAdapter::FLAG_KEY,
					live: SwvHandoffClientHttp::class,
					mock: SwvHandoffClientMock::class
				)
			)
		);
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

	/**
	 * Binds the learning material source to Edurep, live unless `edurep.feature_flag` is `0` or `false`.
	 *
	 * Edurep is a free, keyless public search, so live is the default; the
	 * flag switches an instance to the recorded responses.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	private function registerLearningMaterialSource(IRegistrationContext $context): void {
		$context->registerServiceAlias(SruTransport::class, EdurepHttpTransport::class);
		$context->registerService(
			LearningMaterialSource::class,
			static function ($c) {
				$raw = strtolower($c->get('OCP\IAppConfig')->getValueString('integriq', 'edurep.feature_flag', '1'));
				$mock = ['0' => EdurepSourceMock::class, 'false' => EdurepSourceMock::class];

				return $c->get($mock[$raw] ?? EdurepSourceHttp::class);
			}
		);
	}//end registerLearningMaterialSource()

	/**
	 * The live class when a feature flag is `1` or `true`, the mock otherwise.
	 *
	 * @param IAppConfig $appConfig Reads the flag.
	 * @param string     $flagKey   The integriq app-config key of the flag.
	 * @param string     $live      The live client class.
	 * @param string     $mock      The mock client class.
	 *
	 * @return string The class to resolve.
	 *
	 * @spec openspec/changes/connectors-education-live-clients/specs/rostering-planninq-target/spec.md#requirement-a-live-rostering-client-reads-lessons-from-the-schools-scheduling-package-req-007
	 */
	public static function liveOrMock(IAppConfig $appConfig, string $flagKey, string $live, string $mock): string {
		$raw = strtolower($appConfig->getValueString('integriq', $flagKey, '0'));
		if ($raw === '1' || $raw === 'true') {
			return $live;
		}

		return $mock;
	}//end liveOrMock()
}
