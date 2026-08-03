<?php
/**
 * OpenConnector Mapping Twig Runtime.
 *
 * Runtime class invoked by the MappingExtension Twig filters/functions to
 * perform encoding, mapping execution and source/file lookups.
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

use GuzzleHttp\Exception\GuzzleException;
use OC\Files\Node\File;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\SourceMappingService;
use OCA\OpenConnector\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Runtime that backs the mapping Twig filters and functions.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class MappingRuntime implements RuntimeExtensionInterface
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
     * Encodes a string to base64.
     *
     * @param string $input The unencoded input.
     *
     * @return string The encoded output.
     *
     * @spec openspec/specs/authentication-twig/spec.md
     */
    public function b64enc(string $input): string
    {
        return base64_encode(string: $input);

    }//end b64enc()

    /**
     * Decodes a base64 encoded string to an unencoded string.
     *
     * @param string $input The encoded input.
     *
     * @return string The decoded output.
     *
     * @spec openspec/specs/authentication-twig/spec.md
     */
    public function b64dec(string $input): string
    {
        return base64_decode(string: $input);

    }//end b64dec()

    /**
     * Decodes a JSON encoded string to an associative array.
     *
     * @param string $input The encoded input.
     *
     * @return array The decoded output.
     *
     * @spec openspec/specs/authentication-twig/spec.md
     */
    public function json_decode(string $input): array
    {
        return json_decode(json: $input, associative: true);

    }//end json_decode()

    /**
     * Call source of given id or reference and return the result.
     *
     * @param string $sourceId      The source to call.
     * @param string $endpoint      The endpoint to call.
     * @param string $method        The method to use.
     * @param array  $configuration The configuration to use.
     * @param bool   $decode        Whether the output should be decoded (default true).
     *
     * @return array|string The resulting response.
     *
     * @throws GuzzleException
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws Exception
     * @throws LoaderError
     * @throws SyntaxError
     *
     * @spec openspec/specs/authentication-twig/spec.md
     */
    public function callSource(string $sourceId, string $endpoint, string $method='GET', array $configuration=[], bool $decode=true): array|string
    {
        // System context (ocon#147): the `source` schema is admin-only now. A Twig mapping
        // runs inside the engine on behalf of a configured source, so the ENGINE needs the
        // source — the template never exposes it, and the triggering user must not be able
        // to read it.
        $orObjectService = $this->objectService->getOpenRegisters();
        $source          = $orObjectService->find(
            id: $sourceId,
            register: 'openconnector',
            schema: 'source',
            _rbac: false,
            _multitenancy: false
        );
        $sourceData      = $source->getObject();

        if (str_contains(haystack: $endpoint, needle: ($sourceData['location'] ?? '')) === true) {
            $endpoint = substr(string: $endpoint, offset: strlen(string: ($sourceData['location'] ?? '')));
        }

        $response     = $this->callService->call(source: $source, endpoint: $endpoint, method: $method, config: $configuration);
        $responseData = $response->getObject();

        return $responseData['response']['body'] ?? '';

    }//end callSource()

    /**
     * Execute a mapping with given parameters.
     *
     * @param \OCA\OpenRegister\Db\Mapping|array|string|int $mapping The mapping to execute.
     * @param array                                         $input   The input to run the mapping on.
     * @param bool                                          $list    Whether the mapping runs on multiple instances of the object.
     *
     * @return array
     *
     * @spec openspec/specs/authentication-twig/spec.md
     */
    public function executeMapping(\OCA\OpenRegister\Db\Mapping|array|string|int $mapping, array $input, bool $list=false): array
    {
        return $this->mappingService->executeMapping(
            mapping: $mapping,
            input: $input,
            list: $list
        );

    }//end executeMapping()

    /**
     * Generate a UUID v4.
     *
     * @return UuidV4
     *
     * @spec openspec/specs/authentication-twig/spec.md
     */
    public function generateUuid(): UuidV4
    {
        return Uuid::v4();

    }//end generateUuid()

    /**
     * Fetch the content of a specific file for an object.
     *
     * @param string|int $fileId   The file node ID to fetch.
     * @param string     $objectId The object ID that owns the file.
     *
     * @return string|null The file contents when found, otherwise null.
     *
     * @spec openspec/specs/authentication-twig/spec.md
     */
    public function getFileContents(string|int $fileId, string $objectId): ?string
    {
        $object = $this->objectService->getMapper('objectEntity')->find($objectId);
        $files  = $this->fileService->getFilesForEntity($object);

        $files = array_filter($files, fn ($file) => $file instanceof File === true && $file->getId() === (int) $fileId);

        if (count($files) === 1) {
            return $files[0]->getContent();
        }

        return null;

    }//end getFileContents()

    /**
     * Fetch and format all files for an object.
     *
     * @param string $objectId The object ID to fetch files for.
     *
     * @return array The formatted file metadata list.
     *
     * @spec openspec/specs/authentication-twig/spec.md
     */
    public function getFiles(string $objectId): array
    {
        $files = $this->fileService->getFiles(object: $objectId);

        $formattedFiles = [];
        foreach ($files as $file) {
            $formattedFiles[] = $this->fileService->formatFile($file);
        }

        return $formattedFiles;

    }//end getFiles()

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
     *
     * @spec openspec/specs/authentication-twig/spec.md
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

    /**
     * Look up the targetId of the synchronization contract for a given originId.
     *
     * Use this in a mapping when a related object has already been pushed to an external
     * system and you need its external ID (targetId) as a reference. For example, after
     * uploading a file to zaaksysteem /case/prepare_file the returned UUID is stored as
     * targetId; this function lets a subsequent mapping retrieve that UUID by the
     * OpenRegister object's own id (@self.id / originId).
     *
     * @param string      $originId          UUID of the OpenRegister object (@self.id).
     * @param string|null $synchronizationId Optional: scope the lookup to a specific
     *                                       synchronization; when omitted the first matching
     *                                       contract across all synchronizations is returned.
     *
     * @return string|null The targetId stored on the contract, or null when not found.
     *
     * @throws \OCP\DB\Exception
     */
    public function getTargetIdByOriginId(string $originId, ?string $synchronizationId=null): ?string
    {
        if ($synchronizationId !== null) {
            $contract = $this->synchronizationContractService->findBySyncAndOrigin(
                synchronizationId: $synchronizationId,
                originId: $originId
            );
            if ($contract !== null) {
                return ($contract['targetId'] ?? null);
            }

            return null;
        }

        return $this->synchronizationContractService->findTargetIdByOriginId($originId);

    }//end getTargetIdByOriginId()

    /**
     * Look up the originId of the synchronization contract for a given targetId.
     *
     * Symmetric counterpart of getTargetIdByOriginId(): given the external ID that
     * was stored as targetId on a contract, returns the local OpenRegister object UUID
     * (originId).  Useful when an inbound response or webhook contains an external
     * reference and you need to find the corresponding local object.
     *
     * @param string      $targetId          The external target ID stored on the contract.
     * @param string|null $synchronizationId Optional: scope the lookup to a specific
     *                                       synchronization; when omitted the first matching
     *                                       contract across all synchronizations is returned.
     *
     * @return string|null The originId stored on the contract, or null when not found.
     *
     * @throws \OCP\DB\Exception
     */
    public function getOriginIdByTargetId(string $targetId, ?string $synchronizationId=null): ?string
    {
        $filters = ['targetId' => $targetId];
        if ($synchronizationId !== null) {
            $filters['synchronizationId'] = $synchronizationId;
        }

        $contracts = $this->synchronizationContractService->findAllObjects(filters: $filters);
        if (empty($contracts) === true) {
            return null;
        }

        $contract = $contracts[0]->jsonSerialize();
        $originId = ($contract['originId'] ?? null);
        if ($originId !== null && $originId !== '') {
            return $originId;
        }

        return null;

    }//end getOriginIdByTargetId()
}//end class
