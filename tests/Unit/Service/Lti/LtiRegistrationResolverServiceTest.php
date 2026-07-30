<?php
/**
 * Unit tests for LtiRegistrationResolverService's registration trust gate.
 *
 * Covers REQ-LTI-011: `pending`/`suspended` (and missing-`status`, defensively)
 * registrations MUST resolve identically to "not found" across every lookup
 * this service exposes; only `approved` registrations resolve.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/lti-tool-provider-role/specs/lti-platform/spec.md#req-lti-011
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Lti;

use OCA\OpenConnector\Exception\LtiValidationException;
use OCA\OpenConnector\Service\Lti\LtiRegistrationResolverService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the `status`-gated registration/deployment lookups.
 */
class LtiRegistrationResolverServiceTest extends TestCase
{

    /**
     * In-memory "database" of registration objects keyed by uuid.
     *
     * @var array<string, array>
     */
    private array $registrations = [];


    /**
     * Build an LtiRegistrationResolverService backed by an in-memory registration store.
     *
     * @return LtiRegistrationResolverService
     */
    private function makeService(): LtiRegistrationResolverService
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function ($config=[], $_rbac=true, $_multitenancy=true) {
                $filters = ($config['filters'] ?? []);
                $schema  = ($filters['schema'] ?? null);
                $results = [];

                foreach ($this->registrations as $uuid => $data) {
                    if (($data['@schema'] ?? null) !== $schema) {
                        continue;
                    }

                    if (isset($filters['issuer']) === true && ($data['issuer'] ?? null) !== $filters['issuer']) {
                        continue;
                    }

                    if (isset($filters['clientId']) === true && ($data['clientId'] ?? null) !== $filters['clientId']) {
                        continue;
                    }

                    $entity = new ObjectEntity();
                    $entity->setUuid($uuid);
                    $entity->setObject($data);
                    $results[] = $entity;
                }

                return ['results' => $results];
            }
        );

        $objectService->method('find')->willReturnCallback(
            function ($id, $_extend=[], $files=false, $register=null, $schema=null, $_rbac=true, $_multitenancy=true) {
                if (isset($this->registrations[$id]) === false) {
                    throw new DoesNotExistException('not found');
                }

                $entity = new ObjectEntity();
                $entity->setUuid($id);
                $entity->setObject($this->registrations[$id]);
                return $entity;
            }
        );

        return new LtiRegistrationResolverService($objectService, new NullLogger());

    }//end makeService()


    /**
     * Seed a registration row.
     *
     * @param string      $uuid   The registration uuid.
     * @param string      $schema `lti_platform` or `lti_tool`.
     * @param array       $extra  Extra fields (issuer, clientId, status, ...).
     *
     * @return void
     */
    private function seedRegistration(string $uuid, string $schema, array $extra=[]): void
    {
        $this->registrations[$uuid] = array_merge(['@schema' => $schema], $extra);

    }//end seedRegistration()


    // =========================================================================
    // findPlatformByIssuer()
    // =========================================================================

    /**
     * An `approved` platform resolves normally.
     *
     * @return void
     */
    public function testApprovedPlatformResolves(): void
    {
        $this->seedRegistration('plat-1', 'lti_platform', ['issuer' => 'https://platform.example', 'status' => 'approved']);
        $service = $this->makeService();

        $result = $service->findPlatformByIssuer('https://platform.example');

        $this->assertNotNull($result);
        $this->assertSame('plat-1', $result->getUuid());

    }//end testApprovedPlatformResolves()


    /**
     * A `pending` platform resolves as null — identical to "not found".
     *
     * @return void
     */
    public function testPendingPlatformResolvesAsNotFound(): void
    {
        $this->seedRegistration('plat-2', 'lti_platform', ['issuer' => 'https://pending.example', 'status' => 'pending']);
        $service = $this->makeService();

        $this->assertNull($service->findPlatformByIssuer('https://pending.example'));

    }//end testPendingPlatformResolvesAsNotFound()


    /**
     * A `suspended` platform resolves as null — identical to "not found".
     *
     * @return void
     */
    public function testSuspendedPlatformResolvesAsNotFound(): void
    {
        $this->seedRegistration('plat-3', 'lti_platform', ['issuer' => 'https://suspended.example', 'status' => 'suspended']);
        $service = $this->makeService();

        $this->assertNull($service->findPlatformByIssuer('https://suspended.example'));

    }//end testSuspendedPlatformResolvesAsNotFound()


    /**
     * A platform row with no `status` at all (defensive — should never happen
     * once the schema default applies, but a row written before the field
     * existed must not silently resolve as trusted) resolves as null.
     *
     * @return void
     */
    public function testPlatformWithMissingStatusResolvesAsNotFound(): void
    {
        $this->seedRegistration('plat-4', 'lti_platform', ['issuer' => 'https://no-status.example']);
        $service = $this->makeService();

        $this->assertNull($service->findPlatformByIssuer('https://no-status.example'));

    }//end testPlatformWithMissingStatusResolvesAsNotFound()


    /**
     * A genuinely unregistered issuer also resolves as null (baseline —
     * proves the pending/suspended cases are indistinguishable from this one
     * at this service's return-value level).
     *
     * @return void
     */
    public function testUnregisteredIssuerResolvesAsNotFound(): void
    {
        $service = $this->makeService();

        $this->assertNull($service->findPlatformByIssuer('https://never-registered.example'));

    }//end testUnregisteredIssuerResolvesAsNotFound()


    // =========================================================================
    // findToolByClientId()
    // =========================================================================

    /**
     * An `approved` tool resolves normally.
     *
     * @return void
     */
    public function testApprovedToolResolves(): void
    {
        $this->seedRegistration('tool-1', 'lti_tool', ['clientId' => 'tool-client-1', 'status' => 'approved']);
        $service = $this->makeService();

        $result = $service->findToolByClientId('tool-client-1');

        $this->assertNotNull($result);
        $this->assertSame('tool-1', $result->getUuid());

    }//end testApprovedToolResolves()


    /**
     * A `pending` tool resolves as null.
     *
     * @return void
     */
    public function testPendingToolResolvesAsNotFound(): void
    {
        $this->seedRegistration('tool-2', 'lti_tool', ['clientId' => 'tool-client-2', 'status' => 'pending']);
        $service = $this->makeService();

        $this->assertNull($service->findToolByClientId('tool-client-2'));

    }//end testPendingToolResolvesAsNotFound()


    /**
     * A `suspended` tool resolves as null.
     *
     * @return void
     */
    public function testSuspendedToolResolvesAsNotFound(): void
    {
        $this->seedRegistration('tool-3', 'lti_tool', ['clientId' => 'tool-client-3', 'status' => 'suspended']);
        $service = $this->makeService();

        $this->assertNull($service->findToolByClientId('tool-client-3'));

    }//end testSuspendedToolResolvesAsNotFound()


    // =========================================================================
    // findRegistrationByUuid() — used by Platform-role launch initiation
    // (REQ-LTI-006) and Deep Linking response signing, so it must be gated
    // identically to the issuer/clientId lookups.
    // =========================================================================

    /**
     * An `approved` registration resolves via UUID lookup.
     *
     * @return void
     */
    public function testApprovedRegistrationResolvesByUuid(): void
    {
        $this->seedRegistration('plat-5', 'lti_platform', ['status' => 'approved']);
        $service = $this->makeService();

        $result = $service->findRegistrationByUuid('lti_platform', 'plat-5');

        $this->assertNotNull($result);
        $this->assertSame('plat-5', $result->getUuid());

    }//end testApprovedRegistrationResolvesByUuid()


    /**
     * A `pending` registration resolves as null via UUID lookup too — a
     * Platform-role launch or Deep Linking response MUST NOT be signable
     * through an unapproved registration.
     *
     * @return void
     */
    public function testPendingRegistrationResolvesAsNotFoundByUuid(): void
    {
        $this->seedRegistration('plat-6', 'lti_platform', ['status' => 'pending']);
        $service = $this->makeService();

        $this->assertNull($service->findRegistrationByUuid('lti_platform', 'plat-6'));

    }//end testPendingRegistrationResolvesAsNotFoundByUuid()


    /**
     * A `suspended` registration resolves as null via UUID lookup.
     *
     * @return void
     */
    public function testSuspendedRegistrationResolvesAsNotFoundByUuid(): void
    {
        $this->seedRegistration('tool-4', 'lti_tool', ['status' => 'suspended']);
        $service = $this->makeService();

        $this->assertNull($service->findRegistrationByUuid('lti_tool', 'tool-4'));

    }//end testSuspendedRegistrationResolvesAsNotFoundByUuid()


    // =========================================================================
    // findDeploymentByUuid() — REQ-LTI-001 scenario 2 single-reference gate
    // (defensive re-check now wired at the single deployment-resolution owner
    // every AGS/NRPS dispatch calls).
    // =========================================================================

    /**
     * A deployment referencing exactly one registration resolves normally
     * (the `ltiToolId`-only shape an AGS token-issuance dispatch expects).
     *
     * @return void
     */
    public function testDeploymentWithSingleReferenceResolves(): void
    {
        $this->registrations['dep-ok'] = ['@schema' => 'lti_deployment', 'ltiToolId' => 'tool-1'];
        $service = $this->makeService();

        $result = $service->findDeploymentByUuid('dep-ok');

        $this->assertNotNull($result);
        $this->assertSame('dep-ok', $result->getUuid());

    }//end testDeploymentWithSingleReferenceResolves()


    /**
     * An ambiguous deployment referencing BOTH `ltiPlatformId` and `ltiToolId`
     * fails closed at resolution time — the exact row that could otherwise pass
     * an AGS `ltiToolId === tool` isolation check while also belonging to a
     * platform, issuing a token scoped to an ambiguous deployment.
     *
     * @return void
     */
    public function testDeploymentWithBothReferencesIsRejected(): void
    {
        $this->registrations['dep-both'] = [
            '@schema'       => 'lti_deployment',
            'ltiPlatformId' => 'plat-1',
            'ltiToolId'     => 'tool-1',
        ];
        $service = $this->makeService();

        $this->expectException(LtiValidationException::class);
        $service->findDeploymentByUuid('dep-both');

    }//end testDeploymentWithBothReferencesIsRejected()


    /**
     * A deployment referencing NEITHER registration fails closed at resolution
     * time rather than resolving to an unusable, un-scoped placement.
     *
     * @return void
     */
    public function testDeploymentWithNeitherReferenceIsRejected(): void
    {
        $this->registrations['dep-none'] = ['@schema' => 'lti_deployment'];
        $service = $this->makeService();

        $this->expectException(LtiValidationException::class);
        $service->findDeploymentByUuid('dep-none');

    }//end testDeploymentWithNeitherReferenceIsRejected()
}//end class
