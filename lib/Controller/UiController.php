<?php
/**
 * OpenConnector UI controller.
 *
 * UI Controller that serves the SPA entry template for history-mode deep
 * links. Every public method returns the same SPA shell so the client-side
 * Vue router can take over.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

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
 * @SuppressWarnings(PHPMD.TooManyMethods)        Every method is a one-line SPA-shell
 * route handler (delegates to makeSpaResponse()) — the class grows by design
 * as new SPA routes are added (most recently approvals()/approvalsId() for
 * hitl-approval-rule-action); splitting it would not reduce complexity.
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec exclude SPA-shell controller — every method returns the same index template so the Vue
 *              router can take over; no domain behavior (framework lifecycle).
 */
class UiController extends Controller
{
    /**
     * Constructor for the UiController.
     *
     * @param string   $appName The name of the app.
     * @param IRequest $request The current request object.
     *
     * @return void
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Returns the base SPA template response with permissive connect-src for API calls.
     *
     * @return TemplateResponse The SPA index template with a permissive CSP.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    private function makeSpaResponse(): TemplateResponse
    {
        // Create the SPA template response.
        $response = new TemplateResponse(
            $this->appName,
            'index',
            []
        );

        // Allow connections to any domain so the app can call APIs as configured.
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedConnectDomain('*');
        $response->setContentSecurityPolicy($csp);

        return $response;
    }//end makeSpaResponse()

    /**
     * Serves the SPA shell for the dashboard route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function dashboard(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end dashboard()

    /**
     * Serves the SPA shell for the sources list route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function sources(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end sources()

    /**
     * Serves the SPA shell for the source logs route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function sourcesLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end sourcesLogs()

    /**
     * Serves the SPA shell for the endpoints list route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function endpoints(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end endpoints()

    /**
     * Serves the SPA shell for the endpoint logs route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function endpointsLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end endpointsLogs()

    /**
     * Serves the SPA shell for the single endpoint detail route.
     *
     * @param string $id The endpoint identifier (passed through to the client router).
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function endpointsId(string $id): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end endpointsId()

    /**
     * Serves the SPA shell for the consumers list route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function consumers(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end consumers()

    /**
     * Serves the SPA shell for the single consumer detail route.
     *
     * @param string $id The consumer identifier (passed through to the client router).
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function consumersId(string $id): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end consumersId()

    /**
     * Serves the SPA shell for the webhooks list route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function webhooks(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end webhooks()

    /**
     * Serves the SPA shell for the jobs list route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function jobs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end jobs()

    /**
     * Serves the SPA shell for the job logs route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function jobsLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end jobsLogs()

    /**
     * Serves the SPA shell for the mappings list route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function mappings(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end mappings()

    /**
     * Serves the SPA shell for the single mapping detail route.
     *
     * @param string $id The mapping identifier (passed through to the client router).
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function mappingsId(string $id): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end mappingsId()

    /**
     * Serves the SPA shell for the rules list route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function rules(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end rules()

    /**
     * Serves the SPA shell for the single rule detail route.
     *
     * @param string $id The rule identifier (passed through to the client router).
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function rulesId(string $id): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end rulesId()

    /**
     * Serves the SPA shell for the synchronizations list route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function synchronizations(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end synchronizations()

    /**
     * Serves the SPA shell for the synchronization contracts route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function synchronizationsContracts(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end synchronizationsContracts()

    /**
     * Serves the SPA shell for the synchronization logs route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function synchronizationsLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end synchronizationsLogs()

    /**
     * Serves the SPA shell for the cloud events list route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function cloudEvents(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end cloudEvents()

    /**
     * Serves the SPA shell for the cloud events events route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function cloudEventsEvents(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end cloudEventsEvents()

    /**
     * Serves the SPA shell for the single cloud event detail route.
     *
     * @param string $id The cloud event identifier (passed through to the client router).
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function cloudEventsEventsId(string $id): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end cloudEventsEventsId()

    /**
     * Serves the SPA shell for the cloud events logs route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function cloudEventsLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end cloudEventsLogs()

    /**
     * Serves the SPA shell for the Pending Approvals list route.
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function approvals(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end approvals()

    /**
     * Serves the SPA shell for the single approval_request detail route.
     *
     * @param string $id The approval_request identifier (passed through to the client router).
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function approvalsId(string $id): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end approvalsId()

    /**
     * Serves the SPA shell for the Catalog route (connector-catalog-ui).
     *
     * @return TemplateResponse The SPA index template.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell route handler — delegates to makeSpaResponse() returning the index template, no domain behavior (framework lifecycle).
     */
    public function catalog(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end catalog()
}//end class
