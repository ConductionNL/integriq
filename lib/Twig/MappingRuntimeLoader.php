<?php

/**
 * Integriq Mapping Twig Runtime Loader.
 *
 * Loads the MappingRuntime for Twig when one of the mapping helper filters
 * or functions is invoked from a template.
 *
 * @category Twig
 * @package  OCA\Integriq\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @deprecated Mapping evaluation consolidated into OpenRegister (2026-08-03).
 * Every pure transformation function moved to
 * `OCA\OpenRegister\Twig\MappingRuntime`; the three that need Integriq's
 * own services — callSource, getTargetIdByOriginId, getOriginIdByTargetId — are
 * contributed to that engine via `RegisterMappingFunctionsEvent`
 * (see Listener\MappingFunctionRegistrationListener).
 *
 * This copy is retained only while Integriq's own MappingService still
 * exists. Do NOT add functions here: a function registered on this environment
 * is invisible to every mapping evaluated through OpenRegister, which is now
 * the engine everything else uses.
 *
 * NOTE: Twig\AuthenticationExtension is NOT deprecated. It templates outbound
 * request authentication in CallService and is genuinely this app's concern.
 */

namespace OCA\Integriq\Twig;

use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\SourceMappingServiceInterface;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\OpenRegister\Service\FileService;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

/**
 * Runtime loader that wires the mapping services into the Twig runtime.
 */
class MappingRuntimeLoader implements RuntimeLoaderInterface {
	/**
	 * Constructor.
	 *
	 * @param MappingServiceInterface $mappingService Service that executes mappings.
	 * @param CallServiceInterface $callService Service that performs outbound calls.
	 * @param FileService $fileService Service that resolves file metadata.
	 * @param SourceMappingServiceInterface $objectService Service that resolves OR objects.
	 * @param SynchronizationContractServiceInterface $synchronizationContractService Service for contract lookups.
	 */
	public function __construct(
		private readonly MappingServiceInterface $mappingService,
		private readonly CallServiceInterface $callService,
		private readonly FileService $fileService,
		private readonly SourceMappingServiceInterface $objectService,
		private readonly SynchronizationContractServiceInterface $synchronizationContractService,
	) {

	}//end __construct()

	/**
	 * Instantiate the requested runtime extension class.
	 *
	 * @param string $class Fully qualified class name to load.
	 *
	 * @return MappingRuntime|null
	 */
	public function load(string $class): ?MappingRuntime {
		if ($class === MappingRuntime::class) {
			return new MappingRuntime(
				mappingService:                 $this->mappingService,
				callService:                    $this->callService,
				fileService:                    $this->fileService,
				objectService:                  $this->objectService,
				synchronizationContractService: $this->synchronizationContractService,
			);
		}

		return null;
	}//end load()
}//end class
