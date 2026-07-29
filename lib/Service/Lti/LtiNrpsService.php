<?php
/**
 * OpenConnector LtiNrpsService.
 *
 * Names & Role Provisioning Services (NRPS): inbound roster reads served
 * synchronously from a deployment's configured `register/schema` source
 * (ADR-008 dispatch, reusing the same OR mapper path
 * `EndpointService::handleSchemaRequest()` uses), and Tool-role outbound
 * roster pulls reusing the AGS/NRPS JWT-bearer client-credentials mechanism.
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
 * @spec openspec/specs/lti-platform/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Lti;

use OCA\OpenConnector\Exception\LtiValidationException;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * NRPS roster read (Platform role, synchronous) + outbound roster pull (Tool role).
 *
 * @spec openspec/specs/lti-platform/spec.md
 */
class LtiNrpsService
{
    /**
     * Constructor.
     *
     * @param LtiRegistrationResolverService $resolver        Registration/deployment lookups.
     * @param LtiAgsService                  $agsService      Reused deployment-scope token enforcement AND the
     *                                                        outbound JWT-bearer grant + dispatch machinery
     *                                                        ({@see LtiAgsService::pullResourceForDeployment()})
     *                                                        for the Tool-role roster pull — no separate
     *                                                        `AuthenticationService` dependency needed here.
     * @param OrObjectService                $orObjectService OR ObjectService, used for the ADR-008 `register/schema` mapper read.
     * @param MappingService                 $mappingService  Reused mapping execution for the IMS Names/Roles response shape.
     * @param LoggerInterface                $logger          Logger for read outcomes.
     */
    public function __construct(
        private readonly LtiRegistrationResolverService $resolver,
        private readonly LtiAgsService $agsService,
        private readonly OrObjectService $orObjectService,
        private readonly MappingService $mappingService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    // =========================================================================
    // REQ-LTI-009 — inbound roster read (Platform role)
    // =========================================================================

    /**
     * Serve an authorized NRPS membership request from the deployment's
     * configured `rosterSource`, synchronously (no CloudEvent — a roster
     * read has no useful async/retry semantics; the caller is blocked on the
     * HTTP response).
     *
     * @param string $accessToken    The bearer token value.
     * @param string $deploymentUuid The `lti_deployment` the roster is requested for.
     *
     * @return array The IMS Names and Role Provisioning Service membership container.
     *
     * @throws LtiValidationException 401/403 on an invalid/unscoped token, 400 when `rosterSource` is unconfigured.
     *
     * @spec openspec/specs/lti-platform/spec.md
     */
    public function readRoster(string $accessToken, string $deploymentUuid): array
    {
        $this->agsService->assertScopedToDeployment(
            accessToken: $accessToken,
            deploymentUuid: $deploymentUuid,
            requiredScope: LtiAgsService::SCOPE_NRPS
        );

        $deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
        if ($deployment === null) {
            throw new LtiValidationException(message: 'Unknown lti_deployment', details: [], httpStatus: 400);
        }

        $deploymentData = $deployment->getObject();
        $rosterSource   = ($deploymentData['rosterSource'] ?? null);
        if (is_array($rosterSource) === false
            || ($rosterSource['targetType'] ?? '') !== 'register/schema'
            || empty($rosterSource['targetId'] ?? '') === true
        ) {
            throw new LtiValidationException(message: 'Deployment has no rosterSource configured', details: [], httpStatus: 400);
        }

        $target = explode('/', (string) $rosterSource['targetId']);
        if (count($target) !== 2) {
            throw new LtiValidationException(
                message: 'rosterSource.targetId is malformed',
                details: ['targetId' => $rosterSource['targetId']],
                httpStatus: 400
            );
        }

        [$registerId, $schemaId] = $target;

        // Same ADR-008 `register/schema` mapper path as
        // EndpointService::handleSchemaRequest() (lib/Service/EndpointService.php:1063).
        $mapper = $this->orObjectService->getMapper(schema: (int) $schemaId, register: (int) $registerId);
        $result = $mapper->findAllPaginated([]);

        $members = [];
        foreach ($result['results'] as $object) {
            $members[] = $this->transformMember(objectData: $object->jsonSerialize(), mappingId: ($deploymentData['mappingId'] ?? null));
        }

        $this->logger->info('LtiNrpsService: roster served', ['deploymentUuid' => $deployment->getUuid(), 'memberCount' => count($members)]);

        return [
            'id'      => ($deploymentData['deploymentId'] ?? ''),
            'context' => ['id' => $deployment->getUuid()],
            'members' => $members,
        ];

    }//end readRoster()

    /**
     * Transform one register/schema object into the IMS Names/Roles member shape.
     *
     * @param array       $objectData The raw OR object array.
     * @param string|null $mappingId  The deployment's configured mapping slug, or null for a best-effort default shape.
     *
     * @return array One IMS NRPS `members[]` entry.
     */
    private function transformMember(array $objectData, ?string $mappingId): array
    {
        if (empty($mappingId) === false) {
            try {
                return $this->mappingService->executeMapping(mapping: $mappingId, input: $objectData);
            } catch (Throwable $exception) {
                $this->logger->warning(
                    'LtiNrpsService: mapping execution failed, falling back to default shape',
                    ['error' => $exception->getMessage()]
                );
            }
        }

        // Best-effort default IMS member shape when no mapping is configured.
        return [
            'user_id' => ($objectData['id'] ?? $objectData['uuid'] ?? null),
            'name'    => ($objectData['name'] ?? null),
            'email'   => ($objectData['email'] ?? null),
            'roles'   => ($objectData['roles'] ?? []),
        ];

    }//end transformMember()

    // =========================================================================
    // REQ-LTI-009 — outbound roster pull (Tool role)
    // =========================================================================

    /**
     * Pull a roster from a Platform's NRPS endpoint (Tool role), reusing the
     * same JWT-bearer client-credentials mechanism as AGS (REQ-LTI-008).
     *
     * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_platform`).
     * @param string $membershipUrl  The Platform's NRPS membership endpoint URL.
     *
     * @return array{statusCode: integer, body: mixed}
     *
     * @throws LtiValidationException When the deployment/platform/active key cannot be resolved, or the token
     *                                 endpoint call fails.
     *
     * @spec openspec/specs/lti-platform/spec.md
     */
    public function pullRoster(string $deploymentUuid, string $membershipUrl): array
    {
        // Delegates the token exchange + dispatch shape to LtiAgsService's
        // internal helpers via its public outbound methods is not
        // applicable here (different scope/endpoint) — reuse the same
        // fetchOAuthTokens() + CallService path by calling readResult()'s
        // sibling with the NRPS scope through a thin outbound call.
        return $this->agsService->pullResourceForDeployment(
            deploymentUuid: $deploymentUuid,
            url: $membershipUrl,
            scope: LtiAgsService::SCOPE_NRPS
        );

    }//end pullRoster()
}//end class
