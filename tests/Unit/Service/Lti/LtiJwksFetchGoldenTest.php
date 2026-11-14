<?php

/**
 * Golden test pinning every outcome of LtiJwksResolverService's JWKS fetch.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-external-jwks-resolution-with-kid-lookup-per-registration-caching-and-rate-limited-refetch-req-lti-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;


namespace OCA\Integriq\Tests\Unit\Service\Lti;

use Jose\Component\KeyManagement\JWKFactory;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Lti\LtiJwksResolverService;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Pins what resolveKey() does on a cache miss: the ad-hoc source and endpoint it calls,
 * every response it refuses (with the log line it writes), and the documents it accepts.
 */
class LtiJwksFetchGoldenTest extends TestCase {

	/** @var array<int, array> The recorded log lines: [level, message, context]. */
	private array $logs = [];

	/** @var array<int, array> The recorded calls: [sourceUuid, sourceObject, endpoint, method, read, persistLog]. */
	private array $calls = [];

	/**
	 * Resolve kid `kid-1` for a fresh registration, with CallService answering as given.
	 *
	 * @param string           $jwksUri The JWKS URI.
	 * @param array|\Throwable $answer  The call log object to return, or the exception to throw.
	 *
	 * @return string|null The resolved key's kid, or null.
	 */
	private function resolve(string $jwksUri, array|\Throwable $answer): ?string {
		$this->logs = [];
		$this->calls = [];
		$callService = $this->createMock(CallService::class);
		$callService->method('call')->willReturnCallback(
			function (...$arguments) use ($answer) {
				// Positional: source, endpoint, method, config, asynchronous, createCertificates, overruleAuth, read, ..., persistLog (11).
				$this->calls[] = [
					$arguments[0]->getUuid(),
					$arguments[0]->getObject(),
					$arguments[1],
					$arguments[2],
					$arguments[7],
					$arguments[11],
				];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				$entity = new ObjectEntity();
				$entity->setObject($answer);
				return $entity;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(new ArrayCache());
		$logs = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array $logs The shared log record list.
			 */
			public function __construct(private array &$logs) {
			}

			/**
			 * Record one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->logs[] = [$level, (string)$message, $context];
			}
		};

		$key = (new LtiJwksResolverService($cacheFactory, $callService, $logger))->resolveKey('lti_platform', 'plat-1', $jwksUri, 'kid-1');

		return $key?->get('kid');

	}//end resolve()

	/**
	 * A JWKS document body publishing `kid-1`.
	 *
	 * @return string The JSON body.
	 */
	private function jwksBody(): string {
		$jwk = JWKFactory::createRSAKey(2048, ['kid' => 'kid-1', 'alg' => 'RS256', 'use' => 'sig']);

		return (string)json_encode(['keys' => [$jwk->toPublic()->jsonSerialize()]]);

	}//end jwksBody()

	/**
	 * The ad-hoc source and endpoint the fetch calls, with and without port, path and query.
	 *
	 * @return void
	 */
	public function testCallsTheAdHocSourceAndEndpoint(): void {
		$ok = ['response' => ['statusCode' => 200, 'body' => $this->jwksBody()]];
		$source = fn (string $location) => ['name' => 'LTI JWKS resolver (ad-hoc, not persisted)', 'isEnabled' => true, 'location' => $location];

		$this->assertSame('kid-1', $this->resolve('https://idp.example:8443/keys/jwks.json?tenant=a&v=2', $ok));
		$this->assertSame(
			[['lti-jwks-adhoc-plat-1', $source('https://idp.example:8443'), '/keys/jwks.json?tenant=a&v=2', 'GET', true, false]],
			$this->calls
		);

		$this->assertSame('kid-1', $this->resolve('http://idp.example', $ok));
		$this->assertSame([['lti-jwks-adhoc-plat-1', $source('http://idp.example'), '', 'GET', true, false]], $this->calls);

		$this->assertSame('kid-1', $this->resolve('https://idp.example?only=query', $ok));
		$this->assertSame('?only=query', $this->calls[0][2]);

	}//end testCallsTheAdHocSourceAndEndpoint()

	/**
	 * An unusable jwks_uri is refused before any call.
	 *
	 * @return void
	 */
	public function testInvalidJwksUriIsRefusedBeforeAnyCall(): void {
		foreach (['not a uri', '/relative/jwks.json', 'https:///nohost', 'idp.example/jwks'] as $uri) {
			$this->assertNull($this->resolve($uri, []), $uri);
			$this->assertSame([], $this->calls, $uri);
			$this->assertSame([['warning', 'LtiJwksResolverService: invalid jwks_uri', ['registrationUuid' => 'plat-1']]], $this->logs, $uri);
		}

	}//end testInvalidJwksUriIsRefusedBeforeAnyCall()

	/**
	 * Every refused response, with the log line it writes.
	 *
	 * @return void
	 */
	public function testRefusedResponses(): void {
		$this->assertNull($this->resolve('https://idp.example/jwks', new RuntimeException('timeout')));
		$this->assertSame(
			[['warning', 'LtiJwksResolverService: outbound JWKS fetch failed', ['registrationUuid' => 'plat-1', 'error' => 'timeout']]],
			$this->logs
		);

		$this->assertNull($this->resolve('https://idp.example/jwks', ['statusCode' => 200, 'response' => ['statusCode' => 404, 'body' => $this->jwksBody()]]));
		$this->assertSame(
			[['info', 'LtiJwksResolverService: JWKS fetch returned non-200', ['registrationUuid' => 'plat-1', 'statusCode' => 404]]],
			$this->logs
		);

		$this->assertNull($this->resolve('https://idp.example/jwks', ['response' => ['body' => $this->jwksBody()]]));
		$this->assertSame(
			[['info', 'LtiJwksResolverService: JWKS fetch returned non-200', ['registrationUuid' => 'plat-1', 'statusCode' => 0]]],
			$this->logs
		);

		$this->assertNull($this->resolve('https://idp.example/jwks', ['statusCode' => '200', 'response' => ['body' => $this->jwksBody()]]));
		$this->assertSame('200', $this->logs[0][2]['statusCode']);

		foreach ([['statusCode' => 200], ['statusCode' => 200, 'response' => ['body' => '']], ['statusCode' => 200, 'response' => ['body' => 42]]] as $index => $answer) {
			$this->assertNull($this->resolve('https://idp.example/jwks', $answer), 'empty body ' . $index);
			$this->assertSame([], $this->logs, 'empty body ' . $index);
		}

		$invalid = [['info', 'LtiJwksResolverService: JWKS response is not a valid JWKS document', ['registrationUuid' => 'plat-1']]];
		foreach (['not json', '"string"', '{"nokeys":[]}', '{"keys":"x"}'] as $body) {
			$this->assertNull($this->resolve('https://idp.example/jwks', ['statusCode' => 200, 'response' => ['body' => $body]]), $body);
			$this->assertSame($invalid, $this->logs, $body);
		}

	}//end testRefusedResponses()

	/**
	 * Accepted documents: the top-level status code is the fallback, base64 bodies are decoded.
	 *
	 * @return void
	 */
	public function testAcceptedResponses(): void {
		$this->assertSame('kid-1', $this->resolve('https://idp.example/jwks', ['statusCode' => 200, 'response' => ['body' => $this->jwksBody()]]));
		$this->assertSame([], $this->logs);

		$this->assertSame(
			'kid-1',
			$this->resolve('https://idp.example/jwks', ['statusCode' => 200, 'response' => ['body' => base64_encode($this->jwksBody()), 'encoding' => 'base64']])
		);

		$this->assertNull($this->resolve('https://idp.example/jwks', ['statusCode' => 200, 'response' => ['body' => $this->jwksBody(), 'encoding' => 'base64']]));
		$this->assertSame('LtiJwksResolverService: JWKS response is not a valid JWKS document', $this->logs[0][1]);

		$this->assertNull($this->resolve('https://idp.example/jwks', ['statusCode' => 200, 'response' => ['body' => '{"keys":[]}']]));
		$this->assertSame([], $this->logs);

	}//end testAcceptedResponses()
}//end class
