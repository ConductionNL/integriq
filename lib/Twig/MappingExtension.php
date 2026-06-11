<?php

namespace OCA\OpenConnector\Twig;

use CommonGateway\ZGWToZDSBundle\Twig\CallRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class MappingExtension extends AbstractExtension
{

    public function getFilters(): array
    {
        return [
            new TwigFilter('b64enc', [MappingRuntime::class, 'b64enc']),
            new TwigFilter('b64dec', [MappingRuntime::class, 'b64dec']),
            new TwigFilter('json_decode', [MappingRuntime::class, 'json_decode']),
        ];

    }//end getFilters()

	public function getFunctions(): array
	{
		return [
			new TwigFunction(name: 'executeMapping', callable: [MappingRuntime::class, 'executeMapping']),
			new TwigFunction(name: 'generateUuid', callable: [MappingRuntime::class, 'generateUuid']),
            new TwigFunction(name: 'callSource', callable: [MappingRuntime::class, 'callSource']),
            new TwigFunction(name: 'getFileContents', callable: [MappingRuntime::class, 'getFileContents']),
            new TwigFunction(name: 'getFiles', callable: [MappingRuntime::class, 'getFiles']),
            new TwigFunction(name: 'getTargetIdByOriginId', callable: [MappingRuntime::class, 'getTargetIdByOriginId']),
        ];

    }//end getFunctions()
}//end class
