<?php

namespace OCA\OpenConnector\Service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DoesNotExistException;
use OCA\OpenRegister\Service\GuzzleHttp;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Service for handling file and JSON imports.
 *
 * This service processes imported JSON data, either directly via a POST body,
 * from a provided URL, or from an uploaded file. It supports multiple data
 * formats (e.g., JSON, YAML) and integrates with consumers, endpoints, jobs,
 * mappings, sources and synchronizations for database updates.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.EmptyCatchBlock)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class ImportService
{
	public function __construct(
		private Client $client,
		private readonly IURLGenerator $urlGenerator,
		private readonly ObjectService $objectService
	) {
		$this->client = new Client([]);
	}

	/**
	 * Handles an import api-call to create a new object or update an existing one.
	 * In case of multiple uploaded files this will create/update multiple objects.
	 * @todo: (To refine) We should create NextCloud files for imports through OpenCatalogi (If url is posted we should just be able to download and copy the file)
	 *
	 * @param array $data The data from the request body to use in creating/updating a single object.
	 * @param array|null $uploadedFiles The uploaded files or null.
	 *
	 * @return JSONResponse The JSONResponse response with a message and the created or updated object(s) or an error message.
	 * @throws GuzzleException
	 */
	public function import(array $data, ?array $uploadedFiles): JSONResponse
	{
		// Define the allowed keys
		$allowedKeys = ['url', 'json'];

		// Find which of the allowed keys are in the array
		$matchingKeys = array_intersect_key($data, array_flip($allowedKeys));

		// Check if there is no matching key / no input.
		if (count($matchingKeys) === 0 && empty($uploadedFiles) === true) {
			return new JSONResponse(data: ['error' => 'Missing one of these keys in your POST body: url or json. Or the key file or files[] in form-data.'], statusCode: 400);
		}

		// If type=x has been added to post body.
		$allowedType = null;
		if (empty($data['type']) === false) {
			$allowedType = $data['type'];
		}

		// [if] Check if we need to create or update object(s) using uploaded file(s).
		if (empty($uploadedFiles) === false) {
			if (count($uploadedFiles) === 1) {
				return $this->getJSONfromFile(uploadedFile: $uploadedFiles[array_key_first($uploadedFiles)], type: $allowedType);
			}

			$responses = [];
			foreach ($uploadedFiles as $i => $uploadedFile) {
				$response = $this->getJSONfromFile(uploadedFile: $uploadedFile, type: $allowedType);
				$responses[] = [
					'filename' => "($i) {$uploadedFile['name']}",
					'statusCode' => $response->getStatus(),
					'response' => $response->getData()
				];
			}
			return new JSONResponse(data: ['message' => 'Files processed', 'details' => $responses], statusCode: 200);
		}

		// [elseif] Check if we need to create or update object using given url from the post body.
		if (empty($data['url']) === false) {
			return $this->getJSONfromURL(url: $data['url'], type: $allowedType);
		}

		// [else] Create or update object using given json blob from the post body.
		return $this->getJSONfromBody($data['json'], type: $allowedType);
	}

	/**
	 * A function used to decode file content or the response of an url get call.
	 * Before the data can be used to create or update an object.
	 *
	 * @param string $data The file content or the response body content.
	 * @param string|null $type The file MIME type or the response Content-Type header.
	 *
	 * @return array|null The decoded data or null.
	 */
	private function decode(string $data, ?string $type): ?array
	{
		switch ($type) {
			case 'application/json':
				$phpArray = json_decode(json: $data, associative: true);
				break;
			case 'application/yaml':
				$phpArray = Yaml::parse(input: $data);
				break;
			default:
				// If Content-Type is not specified or not recognized, try to parse as JSON first, then YAML
				$phpArray = json_decode(json: $data, associative: true);
				if ($phpArray === null || $phpArray === false) {
					try {
						$phpArray = Yaml::parse(input: $data);
					} catch (Exception $exception) {
						$phpArray = null;
					}
				}
				break;
		}

		if ($phpArray === null || $phpArray === false) {
			return null;
		}

		return $phpArray;
	}

	/**
	 * Gets uploaded file content from a file in the api request as PHP array and use it for creating/updating an object.
	 *
	 * @param array $uploadedFile The uploaded file.
	 * @param string|null $type If the uploaded file should be a specific type of object.
	 *
	 * @return JSONResponse A JSON response with a message and the created or updated object or an error message.
	 */
	private function getJSONfromFile(array $uploadedFile, ?string $type = null): JSONResponse
	{
		// Check for upload errors
		if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
			return new JSONResponse(data: ['error' => 'File upload error: '.$uploadedFile['error']], statusCode: 400);
		}

		$fileExtension = pathinfo(path: $uploadedFile['name'], flags: PATHINFO_EXTENSION);
		$fileContent = file_get_contents(filename: $uploadedFile['tmp_name']);

		$phpArray = $this->decode(data: $fileContent, type: $fileExtension);
		if ($phpArray === null) {
			return new JSONResponse(
				data: ['error' => 'Failed to decode file content as JSON or YAML', 'MIME-type' => $fileExtension],
				statusCode: 400
			);
		}

		return $this->saveObject(objectArray: $phpArray, type: $type);
	}

	/**
	 * Uses Guzzle to call the given URL and use the response data as PHP array for creating/updating an object.
	 *
	 * @param string $url The URL to call.
	 * @param string|null $type If the object should be a specific type of object.
	 *
	 * @return JSONResponse A JSON response with a message and the created or updated object or an error message.
	 * @throws GuzzleException
	 */
	private function getJSONfromURL(string $url, ?string $type = null): JSONResponse
	{
		try {
			$response = $this->client->request('GET', $url);
		} catch (GuzzleHttp\Exception\BadResponseException $e) {
			return new JSONResponse(data: ['error' => 'Failed to do a GET api-call on url: '.$url.' '.$e->getMessage()], statusCode: 400);
		}

		$responseBody = $response->getBody()->getContents();

		// Use Content-Type header to determine the format
		$contentType = $response->getHeaderLine('Content-Type');
		$phpArray = $this->decode(data: $responseBody, type: $contentType);

		if ($phpArray === null) {
			return new JSONResponse(
				data: ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => $contentType],
				statusCode: 400
			);
		}

		// Set reference, might be overwritten if $phpArray has @id set.
		$phpArray['reference'] = $url;

		return $this->saveObject(objectArray: $phpArray, type: $type);
	}

	/**
	 * Uses the given string or array as PHP array for creating/updating an object.
	 *
	 * @param array|string $phpArray An array or string containing a json blob of data.
	 * @param string|null $type If the object should be a specific type of object.
	 *
	 * @return JSONResponse A JSON response with a message and the created or updated object or an error message.
	 */
	private function getJSONfromBody(array|string $phpArray, ?string $type = null): JSONResponse
	{
		if (is_string($phpArray) === true) {
			$phpArray = json_decode($phpArray, associative: true);
		}

		if ($phpArray === null || $phpArray === false) {
			return new JSONResponse(
				data: ['error' => 'Failed to decode JSON input'],
				statusCode: 400
			);
		}

		return $this->saveObject(objectArray: $phpArray, type: $type);
	}

	/**
	 * Creates or updates an object using the given array as input.
	 *
	 * @param array $objectArray The input php array we use to create or update an object.
	 * @param string|null $type If the object should be a specific type of object.
	 *
	 * @return JSONResponse A JSON response with a message and the created or updated object or an error message.
	 */
	private function saveObject(array $objectArray, ?string $type): JSONResponse
	{
		if (empty($type) === false && $objectArray['@type'] !== strtolower($type)) {
			return new JSONResponse(
				data: ['error' => "The object you are trying to import is not a $type object", '@type' => $objectArray['@type']],
				statusCode: 400
			);
		}

		try {
			$mapper = $this->objectService->getMapper(objectType: $objectArray['@type']);
		} catch (InvalidArgumentException|NotFoundExceptionInterface|ContainerExceptionInterface $e) {
			return new JSONResponse(data: ['error' => "Could not find a mapper for this @type: ".$objectArray['@type']], statusCode: 400);
		}

		if (in_array(strtolower($objectArray['@type']), ['calllog','consumer','event','eventmessage',
				'joblog','synchronizationcontract','synchronizationcontractlog']) === true
		) {
			return new JSONResponse(data: ['error' => "It is not allowed to import objects of {type}: " . $objectArray['@type']], statusCode: 400);
		}

		// Check if object already exists.
		if (isset($objectArray['@id']) === true) {
			$objectArray['reference'] = $objectArray['@id'];
			if (method_exists($mapper, 'findByRef') === false) {
				return new JSONResponse(data: ['error' => ucfirst($objectArray['@type'])."Mapper does not have a findByRef function"], statusCode: 501);
			}
			$object = $this->checkIfExists(mapper: $mapper, objectArray: $objectArray);
		}

		if (isset($object) === true && $object instanceof Entity) {
			return $this->updateObject(mapper: $mapper, objectArray: $objectArray, object: $object);
		}

		return $this->createObject(mapper: $mapper, objectArray: $objectArray);
	}

	/**
	 * Check if the object already exists by using the @id from the phpArray data.
	 *
	 * @param mixed $mapper The mapper of the correct object type to look for.
	 * @param array $objectArray The array data we want to use to create/update an object.
	 *
	 * @return Entity|null The already existing object or null.
	 */
	private function checkIfExists(mixed $mapper, array $objectArray): ?Entity
	{
		// Check reference
		try {
			return $mapper->findByRef($objectArray['@id'])[0];
		} catch (Exception $exception) {}

		// Check if @id matches an object of that type in OpenConnector. 'failsafe' / backup
		try {
			$explodedId = explode(separator: '/', string: $objectArray['@id']);
			$id = end(array: $explodedId);
			$url = $this->urlGenerator->getAbsoluteURL(url: $this->urlGenerator->linkToRoute(
				routeName: 'openconnector.'.ucfirst($objectArray['@type']).'s.show',
				arguments: ['id' => $id])
			);
			if ($objectArray['@id'] === $url) {
				return $mapper->find($id);
			}
		} catch (Exception $exception) {}

		return null;
	}

	/**
	 * Prepare the input array before creating or updating an object.
	 * Will prepare and return some default values for the object to save.
	 * Will also unset some fields from the array used to create or update the object.
	 *
	 * @param array $objectArray The array data we want to use to create/update an object.
	 *
	 * @return array The Json-LD default properties for any object created or updated.
	 */
	private function prepareObject(array &$objectArray): array
	{
		// Prepare Json-LD default properties before unsetting fields from the $objectArray.
		$jsonLdDefault = [
			'@context' => [
				"schema" => "http://schema.org",
				"register" => "Not Implemented"
			],
			'@type' => $objectArray['@type'],
			'@id' => $objectArray['reference'] ?? null
		];

		// Remove all fields from ObjectArray that we should not copy or update when importing an object.
		unset(
			$objectArray['@context'],
			$objectArray['@type'],
			$objectArray['@id'],
			$objectArray['id'],
			$objectArray['uuid'],
			$objectArray['created'],
			$objectArray['updated'],
			$objectArray['dateCreated'],
			$objectArray['dateModified'],
			$objectArray['lastRun'],
			$objectArray['nextRun']
		);

		return $jsonLdDefault;
	}

	/**
	 * Updates an object using the given array as input.
	 *
	 * @param mixed $mapper The mapper of the object type we want to update an object for.
	 * @param array $objectArray The array data we want to use to update an object.
	 * @param Entity $object
	 *
	 * @return JSONResponse A JSON response with a message and the updated object or an error message.
	 */
	private function updateObject(mixed $mapper, array $objectArray, Entity $object): JSONResponse
	{
		$jsonLdDefault = $this->prepareObject($objectArray);

		// @todo: maybe we should do some kind of hash comparison here as well?
		if ($object->getVersion() !== null && empty($objectArray['version']) === false
			&& version_compare(version1: $object->getVersion(), version2: $objectArray['version'], operator: '>=')
		) {
			return new JSONResponse(
				data: [
					'message' => "Import ok, but nothing changed (currentVersion >= importVersion)",
					'currentVersion' => $object->getVersion(),
					'importVersion' => $objectArray['version'],
					'object' => array_merge($jsonLdDefault, $object->jsonSerialize())
				]
			);
		}

		$updatedObject = $mapper->updateFromArray(id: $object->getId(), object: $objectArray);

		return new JSONResponse(
			data: [
				'message' => "Import successful, updated",
				'object' => array_merge($jsonLdDefault, $updatedObject->jsonSerialize())
			]
		);
	}

	/**
	 * Creates an object using the given array as input.
	 *
	 * @param mixed $mapper The mapper of the object type we want to create an object for.
	 * @param array $objectArray The array data we want to use to create an object.
	 *
	 * @return JSONResponse A JSON response with a message and the created object or an error message.
	 */
	private function createObject(mixed $mapper, array $objectArray): JSONResponse
	{
		$jsonLdDefault = $this->prepareObject($objectArray);

		$newObject = $mapper->createFromArray(object: $objectArray);

		// Make sure we set the reference when creating an imported object.
		if (empty($objectArray['reference']) === true) {
			$objectArray['reference'] = $this->urlGenerator->getAbsoluteURL(url: $this->urlGenerator->linkToRoute(
				routeName: 'openconnector.'.ucfirst($objectArray['@type']).'s.show',
				arguments: ['id' => $newObject->getId()])
			);
			$newObject = $mapper->updateFromArray(id: $newObject->getId(), object: $objectArray);
		}

		return new JSONResponse(
			data: [
				'message' => "Import successful, created",
				'object' => array_merge($jsonLdDefault, ['@id' => $objectArray['reference']], $newObject->jsonSerialize())
			],
			statusCode: 201
		);
	}
}
