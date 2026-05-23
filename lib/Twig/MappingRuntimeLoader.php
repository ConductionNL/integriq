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
 */

namespace OCA\OpenConnector\Twig;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
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
     * @param MappingService $mappingService Service that executes mappings.
     * @param CallService    $callService    Service that performs outbound calls.
     * @param FileService    $fileService    Service that resolves file metadata.
     * @param ObjectService  $objectService  Service that resolves OR objects.
     */
    public function __construct(
        private readonly MappingService $mappingService,
        private readonly CallService $callService,
        private readonly FileService $fileService,
        private readonly ObjectService $objectService,
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
                mappingService: $this->mappingService,
                callService: $this->callService,
                fileService: $this->fileService,
                objectService: $this->objectService
            );
        }

        return null;

    }//end load()
}//end class
