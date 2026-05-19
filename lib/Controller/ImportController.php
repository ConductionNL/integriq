<?php

namespace OCA\OpenConnector\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenConnector\Service\ImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CountInLoopExpression)
 */
class ImportController extends Controller
{
	/**
	 * Constructor for the ImportController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param IAppConfig $config The app configuration object
	 * @param ImportService $importService The Import Service.
	 */
    public function __construct(
        $appName,
        IRequest $request,
        private IAppConfig $config,
		private readonly ImportService $importService
    )
    {
        parent::__construct($appName, $request);
    }

	/**
	 * Creates a new object or updates an existing one using a json text/string as input.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 * @throws GuzzleException
	 */
	public function import(): JSONResponse
    {
		$data = $this->request->getParams();
		$uploadedFiles = [];

		// Check if multiple files have been uploaded.
		$files = $_FILES['files'] ?? null;

		if (empty($files) === false) {
			// Loop through each file using the count of 'name'
			for ($i = 0; $i < count($files['name']); $i++) {
				$uploadedFiles[] = [
					'name' => $files['name'][$i],
					'type' => $files['type'][$i],
					'tmp_name' => $files['tmp_name'][$i],
					'error' => $files['error'][$i],
					'size' => $files['size'][$i]
				];
			}
		}

		// Get the uploaded file from the request if a single file hase been uploaded.
		$uploadedFile = $this->request->getUploadedFile(key: 'file');
		if (empty($uploadedFile) === false) {
			$uploadedFiles[] = $uploadedFile;
		}

		return $this->importService->import(data: $data, uploadedFiles: $uploadedFiles);
    }
}
