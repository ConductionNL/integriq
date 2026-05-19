<?php

namespace OCA\OpenConnector\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * UI Controller that serves SPA entry for history-mode deep links.
 *
 * @psalm-type TemplateName = 'index'
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class UiController extends Controller
{
    /**
     * @param string $appName
     * @param IRequest $request
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct($appName, $request);
    }

    /**
     * Returns the base SPA template response with permissive connect-src for API calls.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    private function makeSpaResponse(): TemplateResponse
    {
        // Create the SPA template response
        $response = new TemplateResponse(
            $this->appName,
            'index',
            []
        );

        // Allow connections to any domain so the app can call APIs as configured
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedConnectDomain('*');
        $response->setContentSecurityPolicy($csp);

        return $response;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function dashboard(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function sources(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function sourcesLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function endpoints(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function endpointsLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function endpointsId(string $id): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function consumers(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function consumersId(string $id): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function webhooks(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function jobs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function jobsLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function mappings(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function mappingsId(string $id): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function rules(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function rulesId(string $id): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function synchronizations(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function synchronizationsContracts(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function synchronizationsLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function cloudEvents(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function cloudEventsEvents(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function cloudEventsEventsId(string $id): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function cloudEventsLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function import(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }
}


