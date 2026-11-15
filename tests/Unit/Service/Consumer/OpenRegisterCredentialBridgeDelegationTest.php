<?php

/**
 * Integriq — the credential bridge hands every check to OpenRegister and
 * translates its refusals.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\Consumer\IntegriqConsumerSource;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\AuthenticationException as OpenRegisterAuthenticationException;
use OCA\OpenRegister\Service\AuthorizationService;
use OCA\OpenRegister\Service\Consumer\ResolvedConsumer;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Basic, OAuth, CORS and payload checks go straight to OpenRegister with the
 * caller's arguments; a refusal comes back as integriq's own exception with
 * the same message and details, and no consumer stays resolved.
 *
 * @covers \OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge
 * @uses \OCA\Integriq\Service\Consumer\OpenRegisterEntryPoints
 * @uses \OCA\Integriq\Service\Consumer\IntegriqConsumerSource
 * @uses \OCA\Integriq\Exception\AuthenticationException
 */
class OpenRegisterCredentialBridgeDelegationTest extends TestCase {

	/**
	 * OpenRegister's authorization service.
	 *
	 * @var AuthorizationService&MockObject
	 */
	private AuthorizationService $authorization;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Build the mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->authorization = $this->createMock(AuthorizationService::class);
		$this->userSession   = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * The bridge under test.
	 *
	 * @return OpenRegisterCredentialBridge
	 */
	private function bridge(): OpenRegisterCredentialBridge {
		return new OpenRegisterCredentialBridge(
			authorization: $this->authorization,
			objectService: $this->createMock(ObjectService::class),
			userSession: $this->userSession,
		);
	}//end bridge()

	/**
	 * The integriq exception a call raises.
	 *
	 * @param callable $call The call.
	 *
	 * @return AuthenticationException The refusal.
	 */
	private function refusal(callable $call): AuthenticationException {
		try {
			$call();
		} catch (AuthenticationException $e) {
			return $e;
		}

		$this->fail('the call was not refused');
	}//end refusal()

	/**
	 * A bearer token with the given claims.
	 *
	 * @param array<string, mixed> $claims The claims.
	 *
	 * @return string The Authorization header.
	 */
	private function bearer(array $claims): string {
		$encode = static fn (string $part): string => rtrim(strtr(base64_encode($part), '+/', '-_'), '=');

		return 'Bearer '.$encode('{"alg":"HS256"}').'.'.$encode((string)json_encode($claims)).'.sig';
	}//end bearer()

	/**
	 * Basic and OAuth go to OpenRegister with the header and the allow-lists.
	 *
	 * @return void
	 */
	public function testBasicAndOAuthAreHandedOnAsGiven(): void {
		$this->authorization->expects($this->once())->method('authorizeBasic')->with('Basic abc', ['u1'], ['g1']);
		$this->authorization->expects($this->once())->method('authorizeOAuth')->with('Bearer xyz', ['u2'], ['g2']);
		$bridge = $this->bridge();

		$bridge->authorizeBasic(header: 'Basic abc', users: ['u1'], groups: ['g1']);
		$bridge->authorizeOAuth(header: 'Bearer xyz', users: ['u2'], groups: ['g2']);

		$this->assertNull($bridge->getResolvedConsumer());
	}//end testBasicAndOAuthAreHandedOnAsGiven()

	/**
	 * A Basic or OAuth refusal comes back as integriq's exception with the same message and details.
	 *
	 * @return void
	 */
	public function testBasicAndOAuthRefusalsAreTranslated(): void {
		$this->authorization->method('authorizeBasic')->willThrowException(new OpenRegisterAuthenticationException('Invalid credentials', ['reason' => 'basic']));
		$this->authorization->method('authorizeOAuth')->willThrowException(new OpenRegisterAuthenticationException('Token expired', ['reason' => 'oauth']));
		$bridge = $this->bridge();

		$basic = $this->refusal(fn () => $bridge->authorizeBasic(header: 'Basic abc', users: [], groups: []));
		$oauth = $this->refusal(fn () => $bridge->authorizeOAuth(header: 'Bearer xyz', users: [], groups: []));

		$this->assertSame(['Invalid credentials', ['reason' => 'basic']], [$basic->getMessage(), $basic->getDetails()]);
		$this->assertSame(['Token expired', ['reason' => 'oauth']], [$oauth->getMessage(), $oauth->getDetails()]);
	}//end testBasicAndOAuthRefusalsAreTranslated()

	/**
	 * A Basic check after a resolved JWT leaves no consumer resolved.
	 *
	 * @return void
	 */
	public function testABasicCheckForgetsTheEarlierConsumer(): void {
		$record = new ObjectEntity();
		$this->authorization->method('getResolvedConsumer')->willReturn($this->resolved(record: $record));
		$bridge = $this->bridge();
		$bridge->authorizeApiKey(header: 'key', keys: []);
		$this->assertSame($record, $bridge->getResolvedConsumer());

		$bridge->authorizeBasic(header: 'Basic abc', users: [], groups: []);

		$this->assertNull($bridge->getResolvedConsumer());
	}//end testABasicCheckForgetsTheEarlierConsumer()

	/**
	 * CORS is OpenRegister's answer, unchanged.
	 *
	 * @return void
	 */
	public function testCorsIsOpenRegistersResponse(): void {
		$request  = $this->createMock(IRequest::class);
		$response = new Response();
		$answer   = new Response();
		$this->authorization->expects($this->once())->method('corsAfterController')->with($request, $response)->willReturn($answer);

		$this->assertSame($answer, $this->bridge()->corsAfterController(request: $request, response: $response));
	}//end testCorsIsOpenRegistersResponse()

	/**
	 * A payload without both lifetime claims is not capped.
	 *
	 * @return void
	 */
	public function testAPayloadWithoutLifetimeClaimsIsNotCapped(): void {
		$this->authorization->expects($this->exactly(2))->method('validatePayload');
		$bridge = $this->bridge();

		$bridge->validatePayload(payload: ['iat' => 0]);
		$bridge->validatePayload(payload: ['exp' => 999999]);

		$this->addToAssertionCount(1);
	}//end testAPayloadWithoutLifetimeClaimsIsNotCapped()

	/**
	 * A payload valid for more than an hour is refused, with the figures.
	 *
	 * @return void
	 */
	public function testAPayloadValidForMoreThanAnHourIsRefused(): void {
		$bridge = $this->bridge();

		$bridge->validatePayload(payload: ['iat' => 1000, 'exp' => 4600]);
		$refused = $this->refusal(fn () => $bridge->validatePayload(payload: ['iat' => 1000, 'exp' => 4601]));

		$this->assertSame('The token lifetime exceeds the maximum allowed duration', $refused->getMessage());
		$this->assertSame(['iat' => 1000, 'exp' => 4601, 'max_lifetime_seconds' => 3600], $refused->getDetails());
	}//end testAPayloadValidForMoreThanAnHourIsRefused()

	/**
	 * A JWT whose payload cannot be read is OpenRegister's to judge; the bridge adds no cap.
	 *
	 * @return void
	 */
	public function testAnUnreadableJwtPayloadIsNotCapped(): void {
		$this->authorization->expects($this->once())->method('authorizeJwt')->with('Bearer not-a-jwt');
		$this->userSession->expects($this->never())->method('setVolatileActiveUser');

		$bridge = $this->bridge();
		$bridge->authorizeJwt(authorization: 'Bearer not-a-jwt');

		$this->assertNull($bridge->getResolvedConsumer());
	}//end testAnUnreadableJwtPayloadIsNotCapped()

	/**
	 * A long-lived JWT is refused after OpenRegister accepted it, and the acting user is dropped.
	 *
	 * @return void
	 */
	public function testALongLivedJwtDropsTheActingUser(): void {
		$this->userSession->expects($this->once())->method('setVolatileActiveUser')->with(null);

		$refused = $this->refusal(fn () => $this->bridge()->authorizeJwt(authorization: $this->bearer(['iat' => 0, 'exp' => 7200])));

		$this->assertSame('The token lifetime exceeds the maximum allowed duration', $refused->getMessage());
	}//end testALongLivedJwtDropsTheActingUser()

	/**
	 * Only an integriq consumer whose record is an entity is resolved.
	 *
	 * @return void
	 */
	public function testOnlyAnIntegriqEntityRecordIsResolved(): void {
		$entity = new ObjectEntity();
		$this->authorization->method('getResolvedConsumer')->willReturnOnConsecutiveCalls(
			$this->resolved(record: ['id' => 'array-row']),
			$this->resolved(record: $entity, source: 'some-other-store'),
			null,
			$this->resolved(record: $entity),
		);
		$bridge = $this->bridge();
		$seen   = [];
		for ($i = 0; $i < 4; $i++) {
			$bridge->authorizeApiKey(header: 'key', keys: []);
			$seen[] = $bridge->getResolvedConsumer();
		}

		$this->assertSame([null, null, null, $entity], $seen);
	}//end testOnlyAnIntegriqEntityRecordIsResolved()

	/**
	 * A consumer as OpenRegister resolves it.
	 *
	 * @param mixed  $record The store's own row.
	 * @param string $source The consumer source.
	 *
	 * @return ResolvedConsumer The consumer.
	 */
	private function resolved(mixed $record, string $source=IntegriqConsumerSource::SOURCE): ResolvedConsumer {
		return new ResolvedConsumer(
			source: $source,
			uuid: 'c-1',
			name: 'partner',
			userId: null,
			authorizationType: 'apiKey',
			configuration: [],
			record: $record,
		);
	}//end resolved()
}//end class
