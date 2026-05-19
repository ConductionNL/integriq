<?php

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Service\ExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class ExportController extends Controller
{
	/**
	 * Constructor for the ExportController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param IAppConfig $config The app configuration object
	 * @param ExportService $exportService The Export Service.
	 */
    public function __construct(
        $appName,
        IRequest $request,
        private IAppConfig $config,
		private readonly ExportService $exportService,
		private readonly IL10N $l
    )
    {
        parent::__construct($appName, $request);
    }

	/**
	 * Creates and return a json file for a specific object.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $type The object type we want to export an object for.
	 * @param string $id The id used to find an existing object to export.
	 *
	 * @return JSONResponse
	 */
	public function export(string $type, string $id): JSONResponse
    {
		$accept = $this->request->getHeader(name: 'Accept');

		if (empty($accept) === true) {
			return new JSONResponse(data: ['error' => $this->l->t('Request is missing header Accept')], statusCode: 400);
		}

		return $this->exportService->export(objectType: $type, id: $id, accept: $accept);
    }
}
