<?php

/**
 * Unit tests for the LTI registration lookups the trust-gate tests leave out.
 *
 * Every lookup reads OpenRegister with RBAC and multitenancy off (a launch has
 * no Nextcloud session), so the filters it sends are the whole of what keeps
 * one registration's deployment from answering for another's. These tests pin
 * those filters, that an unknown uuid is "not found" rather than an error,
 * and which registration a deployment row points at.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-launch-id-token-validation-and-dispatch-to-the-consuming-app-tool-role-req-lti-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use OCA\Integriq\Service\Lti\LtiRegistrationResolverService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Lookup filters, not-found answers and the deployment's registration type.
 *
 * @covers \OCA\Integriq\Service\Lti\LtiRegistrationResolverService
 */
class LtiRegistrationLookupsTest extends TestCase {

	/**
	 * A platform looked up with a client id filters on both, without RBAC or multitenancy.
	 *
	 * @return void
	 */
	public function testAPlatformLookupWithAClientIdFiltersOnBoth(): void {
		$platform = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'approved'], 'plat-1');
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->expects($this->once())
			->method('findAll')
			->with(
				['filters' => ['register' => 'integriq', 'schema' => 'lti_platform', 'issuer' => 'https://lms.example', 'clientId' => 'client-7']],
				false,
				false
			)
			->willReturn(['results' => [$platform]]);

		$resolver = new LtiRegistrationResolverService($objectService, new NullLogger());

		$this->assertSame($platform, $resolver->findPlatformByIssuer('https://lms.example', 'client-7'));
	}//end testAPlatformLookupWithAClientIdFiltersOnBoth()

	/**
	 * A deployment is looked up under the relation field of its registration type.
	 *
	 * @return void
	 */
	public function testADeploymentIsLookedUpUnderItsRegistrationsRelation(): void {
		$deployment = ObjectServiceMockBuilder::objectEntity($this, ['deploymentId' => 'dep-1'], 'dep-uuid');
		$seen = [];
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config, bool $rbac, bool $multitenancy) use (&$seen, $deployment): array {
				$seen[] = [$config['filters'], $rbac, $multitenancy];
				return [$deployment];
			}
		);
		$resolver = new LtiRegistrationResolverService($objectService, new NullLogger());

		$this->assertSame($deployment, $resolver->findDeployment('lti_platform', 'plat-1', 'dep-1'));
		$this->assertSame($deployment, $resolver->findDeployment('lti_tool', 'tool-1', 'dep-1'));
		$this->assertSame(
			[
				[['register' => 'integriq', 'schema' => 'lti_deployment', 'deploymentId' => 'dep-1', 'ltiPlatformId' => 'plat-1'], false, false],
				[['register' => 'integriq', 'schema' => 'lti_deployment', 'deploymentId' => 'dep-1', 'ltiToolId' => 'tool-1'], false, false],
			],
			$seen
		);
	}//end testADeploymentIsLookedUpUnderItsRegistrationsRelation()

	/**
	 * No deployment row means no deployment.
	 *
	 * @return void
	 */
	public function testNoDeploymentRowMeansNoDeployment(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('findAll')->willReturn(['results' => []]);

		$this->assertNull((new LtiRegistrationResolverService($objectService, new NullLogger()))->findDeployment('lti_tool', 'tool-1', 'dep-9'));
	}//end testNoDeploymentRowMeansNoDeployment()

	/**
	 * A tool without a uuid has no deployments, and nothing is read.
	 *
	 * @return void
	 */
	public function testAToolWithoutAUuidHasNoDeployments(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->expects($this->never())->method('findAll');

		$this->assertSame([], (new LtiRegistrationResolverService($objectService, new NullLogger()))->findDeploymentsForTool(''));
	}//end testAToolWithoutAUuidHasNoDeployments()

	/**
	 * An unknown deployment or registration uuid is not found, not an error.
	 *
	 * @return void
	 */
	public function testAnUnknownUuidIsNotFound(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('find')->willThrowException(new DoesNotExistException('none'));
		$resolver = new LtiRegistrationResolverService($objectService, new NullLogger());

		$this->assertNull($resolver->findDeploymentByUuid('dep-x'));
		$this->assertNull($resolver->findRegistrationByUuid('lti_tool', 'tool-x'));
		$this->assertNull($resolver->findRegistrationStatus('lti_tool', 'tool-x'));
	}//end testAnUnknownUuidIsNotFound()

	/**
	 * A registration's status is read whatever it is, and a missing one reads as pending.
	 *
	 * @return void
	 */
	public function testARegistrationStatusIsReadAsStored(): void {
		$statuses = ['tool-a' => ['status' => 'suspended'], 'tool-b' => []];
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('find')->willReturnCallback(
			fn (string $id, string $register, string $schema, bool $rbac, bool $multitenancy) => ObjectServiceMockBuilder::objectEntity($this, $statuses[$id], $id)
		);
		$resolver = new LtiRegistrationResolverService($objectService, new NullLogger());

		$this->assertSame('suspended', $resolver->findRegistrationStatus('lti_tool', 'tool-a'));
		$this->assertSame('pending', $resolver->findRegistrationStatus('lti_tool', 'tool-b'));
	}//end testARegistrationStatusIsReadAsStored()

	/**
	 * A deployment that references only a platform belongs to a platform registration.
	 *
	 * @return void
	 */
	public function testADeploymentReferencingOnlyAPlatformIsAPlatformDeployment(): void {
		$resolver = new LtiRegistrationResolverService(ObjectServiceMockBuilder::make($this), new NullLogger());

		$this->assertSame('lti_platform', $resolver->assertSingleRegistrationReference(['ltiPlatformId' => 'plat-1', 'ltiToolId' => '']));
		$this->assertSame('lti_tool', $resolver->assertSingleRegistrationReference(['ltiToolId' => 'tool-1']));
	}//end testADeploymentReferencingOnlyAPlatformIsAPlatformDeployment()
}//end class
