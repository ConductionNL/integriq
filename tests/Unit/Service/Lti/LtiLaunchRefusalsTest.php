<?php

/**
 * Unit tests for the LTI launch refusals the happy-path tests leave out.
 *
 * Each refusal is an LtiValidationException with a fixed message and HTTP
 * status, raised before anything is cached, redirected or signed. These tests
 * pin that for login initiation, the one-time launch reference, a
 * platform-role launch and a deep-linking response.
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

use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Lti\LtiJwksResolverService;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiRegistrationResolverService;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCA\Integriq\Tests\Helpers\OpenRegisterCredentials;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Refusals before redirect or signing, and the one-time launch reference.
 *
 * @covers \OCA\Integriq\Service\Lti\LtiLaunchService
 */
class LtiLaunchRefusalsTest extends TestCase {

	private LtiRegistrationResolverService&MockObject $resolver;

	private LtiKeyService&MockObject $keyService;

	/**
	 * The distributed caches by namespace.
	 *
	 * @var array<string,ICache>
	 */
	private array $caches = [];

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = $this->createMock(LtiRegistrationResolverService::class);
		$this->keyService = $this->createMock(LtiKeyService::class);
		$this->caches = [];
	}//end setUp()

	/**
	 * A login without a login hint or target link is refused before any lookup.
	 *
	 * @return void
	 */
	public function testALoginWithoutAHintOrTargetIsRefused(): void {
		$this->resolver->expects($this->never())->method('findPlatformByIssuer');

		foreach ([['target_link_uri' => 'https://tool/app'], ['login_hint' => 'u-1']] as $missing) {
			$this->assertRefused(
				fn () => $this->service()->initiateLogin('dep-1', ['iss' => 'https://lms'] + $missing, 'https://us/launch'),
				'Missing login_hint or target_link_uri',
				400
			);
		}
	}//end testALoginWithoutAHintOrTargetIsRefused()

	/**
	 * A login for an unknown deployment is refused and stores no nonce.
	 *
	 * @return void
	 */
	public function testALoginForAnUnknownDeploymentIsRefused(): void {
		$this->resolver->method('findPlatformByIssuer')->willReturn($this->entity('plat-1', ['clientId' => 'c-1']));
		$this->resolver->method('findDeploymentByUuid')->with('dep-x')->willReturn(null);
		$nonces = $this->createMock(ICache::class);
		$nonces->expects($this->never())->method('set');
		$this->caches['integriq.lti.nonce'] = $nonces;

		$this->assertRefused(
			fn () => $this->service()->initiateLogin('dep-x', $this->loginParams(), 'https://us/launch'),
			'Unknown LTI deployment',
			400
		);
	}//end testALoginForAnUnknownDeploymentIsRefused()

	/**
	 * A message hint the platform sent is passed back on the redirect.
	 *
	 * @return void
	 */
	public function testAMessageHintIsPassedBackOnTheRedirect(): void {
		$this->resolver->method('findPlatformByIssuer')->willReturn(
			$this->entity('plat-1', ['clientId' => 'c-1', 'authLoginUrl' => 'https://lms/auth'])
		);
		$this->resolver->method('findDeploymentByUuid')->willReturn($this->entity('dep-1', []));

		$result = $this->service()->initiateLogin('dep-1', $this->loginParams() + ['lti_message_hint' => 'hint-42'], 'https://us/launch');

		parse_str((string)parse_url($result['redirectUrl'], PHP_URL_QUERY), $query);
		$this->assertStringStartsWith('https://lms/auth?', $result['redirectUrl']);
		$this->assertSame('hint-42', $query['lti_message_hint']);
		$this->assertSame('c-1', $query['client_id']);
		$this->assertSame($result['nonce'], $query['nonce']);
	}//end testAMessageHintIsPassedBackOnTheRedirect()

	/**
	 * A launch reference answers its claims once, and nothing for an unknown one.
	 *
	 * @return void
	 */
	public function testALaunchReferenceIsConsumedOnce(): void {
		$service = $this->service();
		$this->caches['integriq.lti.launch']->set('launch:ref-1', json_encode(['claims' => ['sub' => 'u-1'], 'deploymentUuid' => 'dep-1']));

		$this->assertSame(['claims' => ['sub' => 'u-1'], 'deploymentUuid' => 'dep-1'], $service->consumeLaunchReference('ref-1'));
		$this->assertNull($service->consumeLaunchReference('ref-1'));
		$this->assertNull($service->consumeLaunchReference('never-issued'));
	}//end testALaunchReferenceIsConsumedOnce()

	/**
	 * A platform launch is refused for an unknown deployment, a platform deployment, a missing tool and a keyless tool.
	 *
	 * @return void
	 */
	public function testAPlatformLaunchIsRefusedUntilATargetAndKeyExist(): void {
		$deployments = [
			'dep-tool' => $this->entity('dep-tool', ['deploymentId' => 'd-1', 'ltiToolId' => 'tool-1']),
			'dep-plat' => $this->entity('dep-plat', ['deploymentId' => 'd-2', 'ltiPlatformId' => 'plat-1']),
			'dep-gone' => $this->entity('dep-gone', ['deploymentId' => 'd-3', 'ltiToolId' => 'tool-gone']),
		];
		$this->resolver->method('findDeploymentByUuid')->willReturnCallback(static fn (string $uuid) => ($deployments[$uuid] ?? null));
		$this->resolver->method('findRegistrationByUuid')->willReturnCallback(
			fn (string $type, string $uuid) => ($uuid === 'tool-1') ? $this->entity('tool-1', ['clientId' => 't-1']) : null
		);
		$this->keyService->method('getActiveKeyEntry')->with('lti_tool', 'tool-1')->willReturn(null);
		$service = $this->service();

		$this->assertRefused(fn () => $service->initiatePlatformLaunch('dep-x', 'https://us', 'u-1', 'LtiResourceLinkRequest'), 'Unknown LTI deployment', 400);
		$this->assertRefused(fn () => $service->initiatePlatformLaunch('dep-plat', 'https://us', 'u-1', 'LtiResourceLinkRequest'), 'Deployment does not reference an lti_tool', 400);
		$this->assertRefused(fn () => $service->initiatePlatformLaunch('dep-gone', 'https://us', 'u-1', 'LtiResourceLinkRequest'), 'Referenced lti_tool not found', 400);
		$this->assertRefused(fn () => $service->initiatePlatformLaunch('dep-tool', 'https://us', 'u-1', 'LtiResourceLinkRequest'), 'lti_tool registration has no active signing key', 400);
	}//end testAPlatformLaunchIsRefusedUntilATargetAndKeyExist()

	/**
	 * Key material that is not base64 is a server error, not a signed token.
	 *
	 * @return void
	 */
	public function testMalformedKeyMaterialIsAServerError(): void {
		$this->resolver->method('findDeploymentByUuid')->willReturn($this->entity('dep-tool', ['deploymentId' => 'd-1', 'ltiToolId' => 'tool-1']));
		$this->resolver->method('findRegistrationByUuid')->willReturn($this->entity('tool-1', ['clientId' => 't-1']));
		$this->keyService->method('getActiveKeyEntry')->willReturn(['kid' => 'k-1', 'algorithm' => 'RS256', 'privateKeySecret' => '***not base64***']);

		$this->assertRefused(
			fn () => $this->service()->initiatePlatformLaunch('dep-tool', 'https://us', 'u-1', 'LtiResourceLinkRequest'),
			'Stored signing key material is malformed',
			500
		);
	}//end testMalformedKeyMaterialIsAServerError()

	/**
	 * A deep-linking response is refused for an unknown platform and for one without a signing key.
	 *
	 * @return void
	 */
	public function testADeepLinkingResponseNeedsAPlatformWithAKey(): void {
		$this->resolver->method('findRegistrationByUuid')->willReturnCallback(
			fn (string $type, string $uuid) => ($type === 'lti_platform' && $uuid === 'plat-1') ? $this->entity('plat-1', []) : null
		);
		$this->keyService->method('getActiveKeyEntry')->with('lti_platform', 'plat-1')->willReturn(null);
		$service = $this->service();

		$this->assertRefused(fn () => $service->buildDeepLinkingResponse('plat-x', 'https://lms/dl-return', [], 'd-1'), 'Unknown lti_platform', 400);
		$this->assertRefused(fn () => $service->buildDeepLinkingResponse('plat-1', 'https://lms/dl-return', [], 'd-1'), 'lti_platform registration has no active signing key', 400);
	}//end testADeepLinkingResponseNeedsAPlatformWithAKey()

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
	 * Valid login initiation parameters.
	 *
	 * @return array<string,string>
	 */
	private function loginParams(): array {
		return ['iss' => 'https://lms', 'login_hint' => 'u-1', 'target_link_uri' => 'https://tool/app', 'client_id' => 'c-1'];
	}//end loginParams()

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
	 * The service, with one in-memory cache per namespace.
	 *
	 * @return LtiLaunchService
	 */
	private function service(): LtiLaunchService {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			function (string $namespace): ICache {
				$this->caches[$namespace] = ($this->caches[$namespace] ?? new ArrayCache());
				return $this->caches[$namespace];
			}
		);
		$bridgeCaches = $this->createMock(ICacheFactory::class);
		$bridgeCaches->method('createDistributed')->willReturn(new ArrayCache());

		return new LtiLaunchService(
			$this->resolver,
			OpenRegisterCredentials::bridge(
				$this->createMock(IUserManager::class),
				$this->createMock(IUserSession::class),
				$this->createMock(\OCA\OpenRegister\Service\ObjectService::class),
				$this->createMock(IGroupManager::class),
				$bridgeCaches,
				$this->createMock(IRequest::class)
			),
			$this->createMock(LtiJwksResolverService::class),
			$this->keyService,
			$cacheFactory,
			new NullLogger()
		);
	}//end service()
}//end class
