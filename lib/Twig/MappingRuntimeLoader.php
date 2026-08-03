<?php
/**
 * OpenConnector Mapping Twig Runtime Loader.
 *
 * Loads the MappingRuntime for Twig when one of the mapping helper filters
 * or functions is invoked from a template.
 *
 * @category Twig
 * @package  OCA\OpenConnector\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @deprecated Mapping evaluation consolidated into OpenRegister (2026-08-03).
 * Every pure transformation function moved to
 * `OCA\OpenRegister\Twig\MappingRuntime`; the three that need OpenConnector's
 * own services — callSource, getTargetIdByOriginId, getOriginIdByTargetId — are
 * contributed to that engine via `RegisterMappingFunctionsEvent`
 * (see Listener\MappingFunctionRegistrationListener).
 *
 * This copy is retained only while OpenConnector's own MappingService still
 * exists. Do NOT add functions here: a function registered on this environment
 * is invisible to every mapping evaluated through OpenRegister, which is now
 * the engine everything else uses.
 *
 * NOTE: Twig\AuthenticationExtension is NOT deprecated. It templates outbound
 * request authentication in CallService and is genuinely this app's concern.
 */

namespace OCA\OpenConnector\Twig;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\SourceMappingService;
use OCA\OpenConnector\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\FileService;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

/**
 * Runtime loader that wires the mapping services into the Twig runtime.
 */
class MappingRuntimeLoader implements RuntimeLoaderInterface
{
    /**
     * Constructor.
     *
     * @param MappingService                 $mappingService                 Service that executes mappings.
     * @param CallService                    $callService                    Service that performs outbound calls.
     * @param FileService                    $fileService                    Service that resolves file metadata.
     * @param SourceMappingService           $objectService                  Service that resolves OR objects.
     * @param SynchronizationContractService $synchronizationContractService Service for contract lookups.
     */
    public function __construct(
        private readonly MappingService $mappingService,
        private readonly CallService $callService,
        private readonly FileService $fileService,
        private readonly SourceMappingService $objectService,
        private readonly SynchronizationContractService $synchronizationContractService,
    ) {

    }//end __construct()

    /**
     * Instantiate the requested runtime extension class.
     *
     * @param string $class Fully qualified class name to load.
     *
     * @return MappingRuntime|null
     */
    public function load(string $class): ?MappingRuntime
    {
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
