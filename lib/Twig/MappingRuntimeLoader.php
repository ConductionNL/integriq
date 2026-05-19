<?php

namespace OCA\OpenConnector\Twig;

use OCA\OpenConnector\Db\MappingMapper;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IRootFolder;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

class MappingRuntimeLoader implements RuntimeLoaderInterface
{
	public function __construct(
		private readonly MappingService $mappingService,
		private readonly MappingMapper  $mappingMapper,
        private readonly CallService $callService,
        private readonly SourceMapper $sourceMapper,
        private readonly FileService $fileService,
        private readonly ObjectService $objectService,
	) {

	}

	public function load(string $class): ?MappingRuntime
	{
		if ($class === MappingRuntime::class) {
			return new MappingRuntime(mappingService: $this->mappingService, mappingMapper: $this->mappingMapper, callService: $this->callService, sourceMapper: $this->sourceMapper, fileService: $this->fileService, objectService: $this->objectService);
		}

		return null;
	}
}
