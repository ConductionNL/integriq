<?php

namespace OCA\OpenConnector\Twig;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

class MappingRuntimeLoader implements RuntimeLoaderInterface
{
    public function __construct(
        private readonly MappingService $mappingService,
        private readonly CallService $callService,
        private readonly FileService $fileService,
        private readonly ObjectService $objectService,
    ) {

    }//end __construct()

    public function load(string $class): ?MappingRuntime
    {
        if ($class === MappingRuntime::class) {
            return new MappingRuntime(mappingService: $this->mappingService, callService: $this->callService, fileService: $this->fileService, objectService: $this->objectService);
        }

        return null;
    }//end load()
}//end class
