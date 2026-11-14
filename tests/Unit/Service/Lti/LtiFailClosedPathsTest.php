<?php

/**
 * Unit tests for the fail-closed and fallback paths of the LTI services.
 *
 * The behaviour suites for these services pin the main flows. These tests
 * pin the edges they leave out: an unknown registration type, a registration
 * without an active key, a malformed cached key set under an active refetch
 * guard, a malformed login hint, a tool lookup that yields nothing, the
 * tool-role roster pull delegation and a lost identity-link creation race.
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
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Lti\LtiAgsService;
use OCA\Integriq\Service\Lti\LtiIdentityLinkService;
use OCA\Integriq\Service\Lti\LtiJwksResolverService;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiNrpsService;
use OCA\Integriq\Service\Lti\LtiPlatformDetailsService;
use OCA\Integriq\Service\Lti\LtiPlatformHint;
use OCA\Integriq\Service\Lti\LtiRegistrationResolverService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * Fail-closed and fallback paths across the LTI services.
 *
 * @covers \OCA\Integriq\Service\Lti\LtiKeyService
 * @covers \OCA\Integriq\Service\Lti\LtiJwksResolverService
 * @covers \OCA\Integriq\Service\Lti\LtiPlatformHint
 * @covers \OCA\Integriq\Service\Lti\LtiPlatformDetailsService
 * @covers \OCA\Integriq\Service\Lti\LtiNrpsService
 * @covers \OCA\Integriq\Service\Lti\LtiIdentityLinkService
 * @uses   \OCA\Integriq\Service\Lti\JwksKeyLookup
 * @uses   \OCA\Integriq\Exception\LtiValidationException
 */
class LtiFailClosedPathsTest extends TestCase {

	/**
	 * A registration type other than lti_platform/lti_tool is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAnUnknownRegistrationTypeIsRefusedBeforeAnyLookup(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('find');
		$service = new LtiKeyService($objects, new NullLogger(), $this->createMock(ICrypto::class));

		try {
			$service->getActiveKeyEntry('lti_deployment', 'reg-1');
			$this->fail('Expected an unknown registration type to be refused.');
		} catch (BadRequestException $e) {
			$this->assertSame('Unknown LTI registration type: lti_deployment', $e->getMessage());
		}
	}//end testAnUnknownRegistrationTypeIsRefusedBeforeAnyLookup()

	/**
	 * A registration whose keys are all rotated or retired has no active key entry.
	 *
	 * @return void
	 */
	public function testARegistrationWithoutAnActiveKeyHasNoActiveEntry(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())->method('find')
			->with('tool-1', 'integriq', 'lti_tool')
			->willReturn(
				$this->entity(
					'tool-1',
					[
						'signingKeys' => [
							['kid' => 'k-old', 'status' => 'rotated', 'privateKeySecret' => 'icrypto:x'],
							['kid' => 'k-older', 'status' => 'retired'],
						],
					]
				)
			);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->never())->method('decrypt');
		$service = new LtiKeyService($objects, new NullLogger(), $crypto);

		$this->assertNull($service->getActiveKeyEntry('lti_tool', 'tool-1'));
	}//end testARegistrationWithoutAnActiveKeyHasNoActiveEntry()

	/**
	 * A malformed cached key set is treated as a miss and, under an active refetch guard, resolves nothing.
	 *
	 * @return void
	 */
	public function testAMalformedCachedKeySetUnderAnActiveGuardResolvesNothing(): void {
		$cache = new ArrayCache();
		$cache->set('jwks:lti_platform:plat-1', '{not a key set');
		$cache->set('jwks:refetch:plat-1', true);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->with('integriq.lti.jwks')->willReturn($cache);
		$callService = $this->createMock(CallService::class);
		$callService->expects($this->never())->method($this->anything());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('LtiJwksResolverService: malformed cached JWKS, treating as miss', $this->arrayHasKey('error'));
		$logger->expects($this->once())->method('info')
			->with(
				'LtiJwksResolverService: refetch guard active, rejecting unresolved kid',
				['registrationType' => 'lti_platform', 'registrationUuid' => 'plat-1', 'kid' => 'kid-1']
			);

		$service = new LtiJwksResolverService($cacheFactory, $callService, $logger);

		$this->assertNull($service->resolveKey('lti_platform', 'plat-1', 'https://lms.example/jwks', 'kid-1'));
	}//end testAMalformedCachedKeySetUnderAnActiveGuardResolvesNothing()

	/**
	 * A hint that is not two dot-separated parts, or whose body is not a JSON object, is malformed.
	 *
	 * @return void
	 */
	public function testAMalformedLoginHintIsRefused(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->never())->method('calculateHMAC');
		$hint = new LtiPlatformHint($crypto, $this->createMock(ITimeFactory::class));

		$notJson = rtrim(strtr(base64_encode('"just a string"'), '+/', '-_'), '=') . '.sig';
		foreach (['no-dot-at-all', 'a.b.c', $notJson] as $malformed) {
			try {
				$hint->peek($malformed);
				$this->fail('Expected "' . $malformed . '" to be refused.');
			} catch (LtiValidationException $e) {
				$this->assertSame('The login hint is malformed', $e->getMessage());
				$this->assertSame(400, $e->getHttpStatus());
			}
		}
	}//end testAMalformedLoginHintIsRefused()

	/**
	 * A well-formed hint body is returned unverified by peek.
	 *
	 * @return void
	 */
	public function testPeekReturnsTheUnverifiedPayload(): void {
		$hint = new LtiPlatformHint($this->createMock(ICrypto::class), $this->createMock(ITimeFactory::class));
		$body = rtrim(strtr(base64_encode('{"userId":"u-1","exp":5}'), '+/', '-_'), '=');

		$this->assertSame(['userId' => 'u-1', 'exp' => 5], $hint->peek($body . '.anything'));
	}//end testPeekReturnsTheUnverifiedPayload()

	/**
	 * A tool lookup that yields no object gives no platform details and builds no URL.
	 *
	 * @return void
	 */
	public function testAToolLookupYieldingNothingGivesNoDetails(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())->method('find')->with('tool-1', 'integriq', 'lti_tool')->willReturn(null);
		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->expects($this->never())->method($this->anything());
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->never())->method($this->anything());

		$service = new LtiPlatformDetailsService($resolver, $objects, $urls);

		$this->assertNull($service->forTool('tool-1'));
	}//end testAToolLookupYieldingNothingGivesNoDetails()

	/**
	 * A tool-role roster pull is the AGS outbound pull with the NRPS scope, returned unchanged.
	 *
	 * @return void
	 */
	public function testARosterPullDelegatesToTheOutboundPullWithTheNrpsScope(): void {
		$response = ['statusCode' => 200, 'body' => ['members' => [['user_id' => 'u-1']]]];
		$ags = $this->createMock(LtiAgsService::class);
		$ags->expects($this->once())->method('pullResourceForDeployment')
			->with('dep-1', 'https://lms.example/memberships', LtiAgsService::SCOPE_NRPS)
			->willReturn($response);

		$service = new LtiNrpsService(
			$this->createMock(LtiRegistrationResolverService::class),
			$ags,
			$this->createMock(ObjectService::class),
			$this->createMock(MappingService::class),
			new NullLogger()
		);

		$this->assertSame($response, $service->pullRoster('dep-1', 'https://lms.example/memberships'));
	}//end testARosterPullDelegatesToTheOutboundPullWithTheNrpsScope()

	/**
	 * A link creation that loses a race returns the winner's link instead of failing.
	 *
	 * @return void
	 */
	public function testALostLinkCreationRaceReturnsTheWinningLink(): void {
		$winner = $this->entity('link-1', ['userId' => 'winner-uid']);
		$objects = $this->autoProvisionObjects([[], ['results' => [$winner]]]);
		$objects->expects($this->once())->method('saveObject')->willThrowException(new RuntimeException('unique constraint'));

		$result = $this->identityService($objects)->resolveIdentity('plat-1', 'sub-1');

		$this->assertSame(['status' => 'linked', 'userId' => 'winner-uid'], $result);
	}//end testALostLinkCreationRaceReturnsTheWinningLink()

	/**
	 * A link creation failure with no winning link to fall back on is rethrown unchanged.
	 *
	 * @return void
	 */
	public function testALinkCreationFailureWithoutAWinnerIsRethrown(): void {
		$failure = new RuntimeException('register unavailable');
		$objects = $this->autoProvisionObjects([[], []]);
		$objects->expects($this->once())->method('saveObject')->willThrowException($failure);

		try {
			$this->identityService($objects)->resolveIdentity('plat-1', 'sub-1');
			$this->fail('Expected the write failure to be rethrown.');
		} catch (RuntimeException $e) {
			$this->assertSame($failure, $e);
		}
	}//end testALinkCreationFailureWithoutAWinnerIsRethrown()

	/**
	 * An object service for an auto-provisioning platform whose link lookups answer in turn.
	 *
	 * @param array<int,array> $lookups The successive findAll answers.
	 *
	 * @return ObjectService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function autoProvisionObjects(array $lookups): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->exactly(count($lookups)))->method('findAll')->willReturnOnConsecutiveCalls(...$lookups);
		$objects->method('find')->with('plat-1', 'integriq', 'lti_platform')->willReturn(
			$this->entity('plat-1', ['identityPolicy' => 'autoProvisionAsRole', 'defaultProvisionGroup' => 'learners'])
		);

		return $objects;
	}//end autoProvisionObjects()

	/**
	 * The identity link service over an existing user already in the provisioning group.
	 *
	 * @param ObjectService $objects The object service.
	 *
	 * @return LtiIdentityLinkService
	 */
	private function identityService(ObjectService $objects): LtiIdentityLinkService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('lti-provisioned');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$users->expects($this->never())->method('createUser');
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(true);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('learners')->willReturn($group);

		return new LtiIdentityLinkService($objects, $users, $groups, $this->createMock(ISecureRandom::class), new NullLogger());
	}//end identityService()

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
}//end class
