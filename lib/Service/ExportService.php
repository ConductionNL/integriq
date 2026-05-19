<?php

namespace OCA\OpenConnector\Service;

use Exception;
use GuzzleHttp\Client;
use InvalidArgumentException;
use JetBrains\PhpStorm\NoReturn;
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
 * Service for handling export requests for database entities.
 *
 * This service enables exporting database entities as files in various formats,
 * determined by the `Accept` header of the request. It retrieves the appropriate
 * data from mappers and generates responses or downloadable files.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.ExitExpression)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class ExportService
{
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly ObjectService $objectService
	) {}

	/**
	 * Handles an upload api-call to create a new object or update an existing one.
	 *
	 * @param string $objectType The type of object to export.
	 * @param string $id The id of the object to export.
	 * @param string $accept The Accept-header from the export request.
	 *
	 * @return JSONResponse The JSONResponse response.
	 */
	public function export(string $objectType, string $id, string $accept): JSONResponse
	{
		$type = match (true) {
			str_contains(haystack: $accept, needle: 'application/json') => 'json',
			$accept === 'application/yaml' => 'yaml',
			default => null
		};

		if ($type === null) {
			return new JSONResponse(data: ['error' => "The Accept type $accept is not supported."], statusCode: 400);
		}

		try {
			$mapper = $this->objectService->getMapper(objectType: $objectType);
		} catch (InvalidArgumentException|NotFoundExceptionInterface|ContainerExceptionInterface $e) {
			return new JSONResponse(data: ['error' => "Could not find a mapper for this {type}: " . $objectType], statusCode: 400);
		}

		if (in_array(strtolower($objectType), ['calllog','consumer','event','eventmessage','joblog',
				'synchronizationcontract','synchronizationcontractlog']) === true
		) {
			return new JSONResponse(data: ['error' => "It is not allowed to export objects of {type}: " . $objectType], statusCode: 400);
		}

		try {
			$object = $mapper->find($id);
		} catch (Exception $exception) {
			return new JSONResponse(data: ['error' => "Could not find an object with this {id}: ".$id], statusCode: 400);
		}

		$objectArray = $this->prepareObject(objectType: $objectType, mapper: $mapper, object: $object);

		$filename = ucfirst($objectType).'-'.($objectArray['name'] ?? $objectType).'-v'.($objectArray['version'] ?? '0.0.0');

		$dataString = $this->encode(objectArray: $objectArray, type: $accept);

		$this->download(dataString: $dataString, filename: $filename, type: $type);
	}

	/**
	 * Prepares a PHP array with all data of the object we want to end up in the downloadable file.
	 *
	 * @param string $objectType The type of object to export.
	 * @param mixed $mapper The mapper of the correct object type to export.
	 * @param Entity $object The object we want to export.
	 *
	 * @return array The object array data.
	 */
	private function prepareObject(string $objectType, mixed $mapper, Entity $object): array
	{
		$objectArray = $object->jsonSerialize();

		if (empty($objectArray['reference']) === true) {
			$objectArray['reference'] = $this->urlGenerator->getAbsoluteURL(
				url: $this->urlGenerator->linkToRoute(
					routeName: 'openconnector.'.ucfirst($objectType).'s.show',
					arguments: ['id' => $object->getId()]
				)
			);

			unset($objectArray['id'], $objectArray['uuid'], $objectArray['created'], $objectArray['updated'],
				$objectArray['dateCreated'], $objectArray['dateModified']);

			// Make sure we update the reference of this object if it wasn't set yet.
			$mapper->updateFromArray(id: $object->getId(), object: $objectArray);
		}

		$url = $objectArray['reference'];

		// Prepare Json-LD default properties.
		$jsonLdDefault = [
			'@context' => [
				"schema" => "http://schema.org",
				"register" => "Not Implemented"
			],
			'@type' => $objectType,
			'@id' => $url
		];

		return array_merge($jsonLdDefault, $objectArray);
	}

	/**
	 * A function used to encode object array to a data string.
	 * So it can be used to create a downloadable file.
	 *
	 * @param array $objectArray The object array data.
	 * @param string|null $type The type from the accept header.
	 *
	 * @return string|null The encoded data string or null.
	 */
	private function encode(array $objectArray, ?string $type): ?string
	{
		switch ($type) {
			case 'application/json':
				$dataString = json_encode(value: $objectArray, flags: JSON_PRETTY_PRINT);
				break;
			case 'application/yaml':
				$dataString = Yaml::dump(input: $objectArray);
				break;
			default:
				// If type is not specified or not recognized, try to encode as JSON first, then YAML
				$dataString = json_encode(value: $objectArray, flags: JSON_PRETTY_PRINT);
				if ($dataString === false) {
					try {
						$dataString = Yaml::dump(input: $objectArray);
					} catch (Exception $exception) {
						$dataString = null;
					}
				}
				break;
		}

		if ($dataString === null || $dataString === false) {
			return null;
		}

		return $dataString;
	}

	/**
	 * Generate a downloadable file response.
	 *
	 * @param string $dataString The data to create a file with of the given $type.
	 * @param string $filename The filename, .[$type] will be added after this filename in this function.
	 * @param string $type The type of file to create and download. Default = json.
	 *
	 * @return void
	 */
	#[NoReturn] private function download(string $dataString, string $filename, string $type = 'json'): void
	{
		// Define the file name and path for the temporary JSON file
		$fileName = "$filename.$type";
		$filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $fileName;

		// Create and write the (JSON) data to the file
		file_put_contents(filename: $filePath, data: $dataString);

		// Set headers to download the file
		header("Content-Type: application/$type");
		header('Content-Disposition: attachment; filename="' . $fileName . '"');
		header('Content-Length: ' . filesize(filename: $filePath));

		// Output the file contents
		readfile(filename: $filePath);

		// Clean up: delete the temporary file
		unlink(filename: $filePath);
		exit; // Ensure no further script execution
	}
}
