<?php

/**
 * Unit tests for the refusals on an outbound AGS read (Tool role).
 *
 * Before integriq asks a platform for line items or results it must know
 * which platform the deployment belongs to and hold a key to assert itself
 * with. Each missing piece is a fixed refusal, and a failed token request is
 * a 502 that is logged, never a call made without a token.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/lti-platform/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\CloudEventEmitterInterface;
use OCA\Integriq\Service\Lti\LtiAgsService;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiRegistrationResolverService;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Which platform, which key, and a failed token request.
 *
 * @covers \OCA\Integriq\Service\Lti\LtiAgsService
 */
class LtiAgsOutboundRefusalsTest extends TestCase {

	private LtiRegistrationResolverService&MockObject $resolver;

	private LtiKeyService&MockObject $keyService;

	private AuthenticationService&MockObject $authentication;

	private CallService&MockObject $callService;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = $this->createMock(LtiRegistrationResolverService::class);
		$this->keyService = $this->createMock(LtiKeyService::class);
		$this->authentication = $this->createMock(AuthenticationService::class);
		$this->callService = $this->createMock(CallService::class);

		$deployments = [
			'dep-ok' => $this->entity('dep-ok', ['ltiPlatformId' => 'plat-1']),
			'dep-tool' => $this->entity('dep-tool', ['ltiToolId' => 'tool-1']),
			'dep-gone' => $this->entity('dep-gone', ['ltiPlatformId' => 'plat-gone']),
		];
		$this->resolver->method('findDeploymentByUuid')->willReturnCallback(static fn (string $uuid) => ($deployments[$uuid] ?? null));
		$this->resolver->method('findRegistrationByUuid')->willReturnCallback(
			fn (string $type, string $uuid) => ($type === 'lti_platform' && $uuid === 'plat-1')
				? $this->entity('plat-1', ['clientId' => 'our-client', 'authTokenUrl' => 'https://lms/token'])
				: null
		);
	}//end setUp()

	/**
	 * A read for a deployment with no known platform is refused before any token is asked.
	 *
	 * @return void
	 */
	public function testAReadWithoutAKnownPlatformIsRefused(): void {
		$this->authentication->expects($this->never())->method('fetchOAuthTokens');
		$this->callService->expects($this->never())->method($this->anything());
		$service = $this->service(logger: new NullLogger());

		$this->assertRefused(fn () => $service->readResult('dep-x', 'https://lms/lineitems/1'), 'Unknown lti_deployment', 400);
		$this->assertRefused(fn () => $service->pullResourceForDeployment('dep-tool', 'https://lms/lineitems', 'scope'), 'Deployment does not reference an lti_platform', 400);
		$this->assertRefused(fn () => $service->pullResourceForDeployment('dep-gone', 'https://lms/lineitems', 'scope'), 'Referenced lti_platform not found', 400);
	}//end testAReadWithoutAKnownPlatformIsRefused()

	/**
	 * Without an active platform key no token is asked for, and the read fails as a 502.
	 *
	 * @return void
	 */
	public function testWithoutAPlatformKeyNoTokenIsAskedFor(): void {
		$this->keyService->method('getActiveKeyEntry')->with('lti_platform', 'plat-1')->willReturn(null);
		$this->authentication->expects($this->never())->method('fetchOAuthTokens');
		$this->callService->expects($this->never())->method($this->anything());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'LtiAgsService: token endpoint failure during outbound pull',
				['deploymentUuid' => 'dep-ok', 'scope' => 'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly', 'error' => 'lti_platform registration has no active signing key']
			);

		$this->assertRefused(
			fn () => $this->service(logger: $logger)->readResult('dep-ok', 'https://lms/lineitems/1'),
			'Failed to obtain access token from platform',
			502
		);
	}//end testWithoutAPlatformKeyNoTokenIsAskedFor()

	/**
	 * A token endpoint that fails is a 502 and no AGS call is made.
	 *
	 * @return void
	 */
	public function testAFailingTokenEndpointIsA502WithoutACall(): void {
		$this->keyService->method('getActiveKeyEntry')->willReturn(['kid' => 'k-1', 'algorithm' => 'RS256', 'privateKeySecret' => 'c2VjcmV0']);
		$this->authentication->expects($this->once())
			->method('fetchOAuthTokens')
			->with($this->callback(static fn (array $config): bool => $config['client_id'] === 'our-client' && $config['private_key'] === 'c2VjcmV0'))
			->willThrowException(new \RuntimeException('connection refused'));
		$this->callService->expects($this->never())->method($this->anything());

		$this->assertRefused(
			fn () => $this->service(logger: new NullLogger())->pullResourceForDeployment('dep-ok', 'https://lms/lineitems', 'scope-x'),
			'Failed to obtain access token from platform',
			502
		);
	}//end testAFailingTokenEndpointIsA502WithoutACall()

	/**
	 * Assert a call is refused with this message and status.
	 *
	 * @param callable $call    The call.
	 * @param string   $message The refusal message.
	 * @param int      $status  The HTTP status.
	 *
	 * @return void
	 */
	private function assertRefused(callable $call, string $message, int $status): void {
		try {
			$call();
			$this->fail('Expected the call to be refused with "' . $message . '".');
		} catch (LtiValidationException $e) {
			$this->assertSame($message, $e->getMessage());
			$this->assertSame($status, $e->getHttpStatus());
		}
	}//end assertRefused()

	/**
	 * An OpenRegister object with a uuid and body.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $body The body.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $body): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($body);

		return $entity;
	}//end entity()

	/**
	 * The service under test.
	 *
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return LtiAgsService
	 */
	private function service(LoggerInterface $logger): LtiAgsService {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(new ArrayCache());

		return new LtiAgsService(
			$this->resolver,
			$this->createMock(LtiLaunchService::class),
			$this->keyService,
			$this->authentication,
			$this->callService,
			$this->createMock(CloudEventEmitterInterface::class),
			$cacheFactory,
			$logger
		);
	}//end service()
}//end class
