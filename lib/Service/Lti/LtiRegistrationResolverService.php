<?php
/**
 * OpenConnector LtiRegistrationResolverService.
 *
 * Shared lookups for `lti_platform`/`lti_tool`/`lti_deployment` registrations
 * used by every LTI service (launch, AGS, NRPS) — a single owner of "find the
 * registration for this issuer/client_id" and "find the deployment for this
 * registration + deployment_id claim" so the per-deployment-isolation lookups
 * (design.md D8) are not duplicated (and cannot silently diverge) across
 * services.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Lti;

use OCA\OpenConnector\Exception\LtiValidationException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Registration + deployment lookups for the LTI adapter.
 *
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-lti-registration-model-is-a-catalogue-entry-not-a-menu-req-lti-001
 */
class LtiRegistrationResolverService
{
    /**
     * The only status every lookup in this service resolves against
     * (REQ-LTI-011). A `pending`/`suspended`/missing `status` is treated
     * identically to "not found" by every caller — the trust gate.
     *
     * @var string
     */
    private const APPROVED_STATUS = 'approved';

    /**
     * Constructor.
     *
     * @param OrObjectService $orObjectService OR ObjectService used for all register reads.
     * @param LoggerInterface $logger          Logs the actual resolved `status` (debug level only) when a
     *                                         registration is found but not approved — operator
     *                                         visibility without changing the HTTP-facing "not found"
     *                                         behaviour.
     */
    public function __construct(
        private readonly OrObjectService $orObjectService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Reject a resolved registration that is not `approved`, logging the
     * actual status at debug level, and return `null` instead (the same
     * shape callers already handle as "not found") — REQ-LTI-011's
     * no-status-enumeration-side-channel gate, enforced once here for every
     * lookup method in this service.
     *
     * @param ObjectEntity|null $registration     The resolved registration, or null when the underlying lookup found nothing.
     * @param string            $registrationType `lti_platform` or `lti_tool` (for the debug log only).
     *
     * @return ObjectEntity|null The registration when (and only when) its `status` is `approved`; `null` otherwise.
     *
     * @spec openspec/changes/lti-tool-provider-role/specs/lti-platform/spec.md#req-lti-011
     */
    private function requireApproved(?ObjectEntity $registration, string $registrationType): ?ObjectEntity
    {
        if ($registration === null) {
            return null;
        }

        $status = ($registration->getObject()['status'] ?? null);
        if ($status === self::APPROVED_STATUS) {
            return $registration;
        }

        $this->logger->debug(
            'LtiRegistrationResolverService: registration resolved but not approved — treated as not found',
            ['registrationType' => $registrationType, 'registrationUuid' => $registration->getUuid(), 'status' => $status]
        );

        return null;

    }//end requireApproved()

    /**
     * Find an `lti_platform` registration by issuer (and optionally clientId).
     *
     * @param string      $issuer   The OIDC `iss` value.
     * @param string|null $clientId When given, the platform's `clientId` must also match.
     *
     * @return ObjectEntity|null The registration, or null when not found.
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-oidc-thirdparty-initiated-login-tool-role-req-lti-004
     */
    public function findPlatformByIssuer(string $issuer, ?string $clientId=null): ?ObjectEntity
    {
        $filters = [
            'register' => 'openconnector',
            'schema'   => 'lti_platform',
            'issuer'   => $issuer,
        ];
        if ($clientId !== null) {
            $filters['clientId'] = $clientId;
        }

        $matches = $this->orObjectService->findAll(
            config: ['filters' => $filters],
            _rbac: false,
            _multitenancy: false
        );
        $results = ($matches['results'] ?? $matches);

        return $this->requireApproved(registration: ($results[0] ?? null), registrationType: 'lti_platform');

    }//end findPlatformByIssuer()

    /**
     * Find an `lti_tool` registration by the clientId this instance assigned it.
     *
     * Used to resolve the issuing tool of an RFC 7523 client assertion
     * (`iss`/`sub` both carry the tool's assigned `client_id`).
     *
     * @param string $clientId The tool's assigned `clientId`.
     *
     * @return ObjectEntity|null The registration, or null when not found.
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-ags-service-token-issuance-and-inbound-scoreline-item-endpoints-platform-role-fanned-out-as-a-cloudevent-req-lti-007
     */
    public function findToolByClientId(string $clientId): ?ObjectEntity
    {
        $matches = $this->orObjectService->findAll(
            config: [
                'filters' => [
                    'register' => 'openconnector',
                    'schema'   => 'lti_tool',
                    'clientId' => $clientId,
                ],
            ],
            _rbac: false,
            _multitenancy: false
        );
        $results = ($matches['results'] ?? $matches);

        return $this->requireApproved(registration: ($results[0] ?? null), registrationType: 'lti_tool');

    }//end findToolByClientId()

    /**
     * Find an `lti_deployment` by its LTI `deployment_id` claim value under a
     * specific `lti_platform` or `lti_tool` registration.
     *
     * @param string $registrationType  `lti_platform` or `lti_tool`.
     * @param string $registrationUuid  The registration's UUID.
     * @param string $deploymentIdClaim The LTI `deployment_id` claim value.
     *
     * @return ObjectEntity|null The deployment, or null when not registered
     *                           under this registration (per-deployment
     *                           isolation — design.md D8).
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-launch-idtoken-validation-and-dispatch-to-the-consuming-app-tool-role-req-lti-005
     */
    public function findDeployment(string $registrationType, string $registrationUuid, string $deploymentIdClaim): ?ObjectEntity
    {
        if ($registrationType === 'lti_platform') {
            $relationField = 'ltiPlatformId';
        } else {
            $relationField = 'ltiToolId';
        }

        $matches = $this->orObjectService->findAll(
            config: [
                'filters' => [
                    'register'     => 'openconnector',
                    'schema'       => 'lti_deployment',
                    'deploymentId' => $deploymentIdClaim,
                    $relationField => $registrationUuid,
                ],
            ],
            _rbac: false,
            _multitenancy: false
        );
        $results = ($matches['results'] ?? $matches);

        return ($results[0] ?? null);

    }//end findDeployment()

    /**
     * Find an `lti_deployment` by its own UUID.
     *
     * @param string $deploymentUuid The deployment's UUID.
     *
     * @return ObjectEntity|null The deployment, or null when not found.
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-launch-idtoken-validation-and-dispatch-to-the-consuming-app-tool-role-req-lti-005
     */
    public function findDeploymentByUuid(string $deploymentUuid): ?ObjectEntity
    {
        try {
            $deployment = $this->orObjectService->find(
                id: $deploymentUuid,
                register: 'openconnector',
                schema: 'lti_deployment',
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $exception) {
            return null;
        }

        // REQ-LTI-001 scenario 2 defensive re-check, at the single owner of
        // deployment-by-uuid resolution — the read-time counterpart to the OR
        // schema `oneOf` write-time constraint the docblock references. Every
        // live AGS/NRPS dispatch resolves its deployment through this method,
        // so an ambiguous row (both `ltiPlatformId` and `ltiToolId`, or
        // neither) that reached storage bypassing OR validation now fails
        // closed here instead of silently resolving to an ambiguous
        // registration at token-issuance/roster-read time.
        $this->assertSingleRegistrationReference(deploymentData: $deployment->getObject());

        return $deployment;

    }//end findDeploymentByUuid()

    /**
     * Load an `lti_platform` or `lti_tool` registration by UUID.
     *
     * @param string $registrationType `lti_platform` or `lti_tool`.
     * @param string $registrationUuid The registration's UUID.
     *
     * @return ObjectEntity|null The registration, or null when not found.
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-platformrole-launch-initiation-and-deep-linking-20-both-directions-req-lti-006
     */
    public function findRegistrationByUuid(string $registrationType, string $registrationUuid): ?ObjectEntity
    {
        try {
            $registration = $this->orObjectService->find(
                id: $registrationUuid,
                register: 'openconnector',
                schema: $registrationType,
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $exception) {
            return null;
        }

        // Same trust gate as findPlatformByIssuer()/findToolByClientId()
        // (REQ-LTI-011) — REQ-LTI-006's Platform-role launch initiation and
        // Deep Linking response signing both resolve their own registration
        // via this method, so a pending/suspended registration must not be
        // usable to launch/sign through either.
        return $this->requireApproved(registration: $registration, registrationType: $registrationType);

    }//end findRegistrationByUuid()

    /**
     * Assert an `lti_deployment` references exactly one of
     * `ltiPlatformId`/`ltiToolId` (REQ-LTI-001 scenario 2).
     *
     * Defensive, in-code re-check of the OR schema-level `oneOf` constraint
     * (`lib/Settings/openconnector_register.json` — `lti_deployment.oneOf`).
     * A row that predates the schema constraint (or reached storage through
     * any path that bypasses OR validation) must not silently resolve to an
     * ambiguous registration at launch/AGS/NRPS dispatch time.
     *
     * @param array $deploymentData The deployment object's data array.
     *
     * @return string Either `lti_platform` or `lti_tool` — the single referenced registration type.
     *
     * @throws LtiValidationException When both or neither is set.
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-lti-registration-model-is-a-catalogue-entry-not-a-menu-req-lti-001
     */
    public function assertSingleRegistrationReference(array $deploymentData): string
    {
        $hasPlatform = (empty($deploymentData['ltiPlatformId'] ?? null) === false);
        $hasTool     = (empty($deploymentData['ltiToolId'] ?? null) === false);

        if ($hasPlatform === $hasTool) {
            throw new LtiValidationException(
                message: 'lti_deployment must reference exactly one of ltiPlatformId or ltiToolId',
                details: ['hasPlatform' => $hasPlatform, 'hasTool' => $hasTool],
                httpStatus: 400
            );
        }

        if ($hasPlatform === true) {
            return 'lti_platform';
        }

        return 'lti_tool';

    }//end assertSingleRegistrationReference()
}//end class
