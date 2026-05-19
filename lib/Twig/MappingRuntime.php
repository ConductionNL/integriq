<?php

namespace OCA\OpenConnector\Twig;

use GuzzleHttp\Exception\GuzzleException;
use OC\Files\Node\File;
use OCA\OpenConnector\Db\Mapping;
use OCA\OpenConnector\Db\MappingMapper;
use OCA\OpenConnector\Db\Source;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Service\AuthenticationService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Files\IRootFolder;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Extension\RuntimeExtensionInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class MappingRuntime implements RuntimeExtensionInterface
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

    /**
     * Encodes a string to base64.
     *
     * @param string $input The unencoded input.
     * @return string The encoded output.
     */
    public function b64enc(string $input): string
    {
        return base64_encode(string: $input);

    }//end b64enc()

    /**
     * Decodes a base64 encoded string to an unencoded string.
     *
     * @param string $input The encoded input.
     * @return string The decoded output.
     */
    public function b64dec(string $input): string
    {
        return base64_decode(string: $input);

    }//end b64dec()

    /**
     * Decodes a json encoded string to an unencoded array.
     *
     * @param string $input The encoded input.
     * @return array The decoded output.
     */
    public function json_decode(string $input): array
    {
        return json_decode(json: $input, associative: true);
    }

    /**
     * Call source of given id or reference and return the result.
     *
     * @param string $sourceId The source to call
     * @param string $endpoint The endpoint to call
     * @param string $method The method to use
     * @param array $configuration The configuration to use
     * @param bool $decode Whether or not the output should be decoded (default true)
     * @return array|string The resulting response.
     *
     * @throws GuzzleException
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws Exception
     * @throws LoaderError
     * @throws SyntaxError
     */
    public function callSource(string $sourceId, string $endpoint, string $method='GET', array $configuration=[], bool $decode=true): array|string
    {
        $source = $this->sourceMapper->find(id: $sourceId);

        if (str_contains(haystack: $endpoint, needle: $source->getLocation()) === true) {
            $endpoint = substr(string: $endpoint, offset: strlen(string: $source->getLocation()));
        }

        $response = $this->callService->call(source: $source, endpoint: $endpoint, method: $method, config: $configuration);

        return $response->getResponse()['body'];

    }//end call()

	/**
	 * Execute a mapping with given parameters.
	 *
	 * @param Mapping|array|string|int $mapping The mapping to execute
	 * @param array $input The input to run the mapping on
	 * @param bool $list Whether the mapping runs on multiple instances of the object.
	 *
	 * @return array
	 */
	public function executeMapping(Mapping|array|string|int $mapping, array $input, bool $list = false): array
	{
		if (is_array($mapping) === true) {
			$mappingObject = new Mapping();
			$mappingObject->hydrate($mapping);

			$mapping = $mappingObject;
		}

		if ((is_string($mapping) === true || is_int($mapping) === true)
			&& is_string($mapping) === true && str_starts_with($mapping, 'http')
		) {
			$mapping = $this->mappingMapper->findByRef($mapping)[0];
		}

		if ((is_string($mapping) === true || is_int($mapping) === true)
			&& !(is_string($mapping) === true && str_starts_with($mapping, 'http'))
		) {
			// If the mapping is an int, we assume it's an ID and try to find the mapping by ID.
			// In the future we should be able to find the mapping by uuid (string) as well.
			$mapping = $this->mappingMapper->find($mapping);
		}

		return $this->mappingService->executeMapping(
			mapping: $mapping, input: $input, list: $list
		);
	}

	/**
	 * Generate a uuid.
	 *
	 * @return array
	 */
    public function generateUuid(): UuidV4
    {
        return Uuid::v4();
    }

    /**
     * Fetch the content of a specific file for an object.
     *
     * @param string|int $fileId The file node ID to fetch.
     * @param string $objectId The object ID that owns the file.
     * 
     * @return string|null The file contents when found, otherwise null.
     */
    public function getFileContents(string|int $fileId, string $objectId): ?string
    {
        $object = $this->objectService->getMapper('objectEntity')->find($objectId);
        $files = $this->fileService->getFilesForEntity($object);

        $files = array_filter($files, fn ($file) => $file instanceof File === true && $file->getId() === (int) $fileId);

        if (count($files) === 1) {
            return $files[0]->getContent();
        }

        return get_class($file);
    }

    /**
     * Fetch and format all files for an object.
     *
     * @param string $objectId The object ID to fetch files for.
     * 
     * @return array The formatted file metadata list.
     */
    public function getFiles(string $objectId): array
    {
        $files = $this->fileService->getFiles(object: $objectId);

        $formattedFiles = [];
        foreach ($files as $file) {
            $formattedFiles[] = $this->fileService->formatFile($file);
        }

        return $formattedFiles;
    }

    /**
     * Creates a URL-friendly slug from text.
     *
     * Conversion steps:
     * 1. Convert to lowercase
     * 2. Replace spaces and underscores with hyphens
     * 3. Remove special characters
     * 4. Remove multiple consecutive hyphens
     * 5. Trim hyphens from start and end
     *
     * @param string $text The text to convert to a slug.
     *
     * @return string The generated slug.
     */
    public function createSlug(string $text): string
    {
        // Convert to lowercase.
        $slug = strtolower($text);

        // Replace spaces and underscores with hyphens.
        $slug = str_replace([' ', '_'], '-', $slug);

        // Remove all characters that are not a-z, 0-9, or hyphen.
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);

        // Replace multiple consecutive hyphens with single hyphen.
        $slug = preg_replace('/-+/', '-', $slug);

        // Trim hyphens from start and end.
        $slug = trim($slug, '-');

        return $slug;
    }//end createSlug()
}
