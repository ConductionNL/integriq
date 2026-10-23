<?php

/**
 * Unit tests for `EndpointService::processAuthenticationRule()`'s custom
 * `configuration.authentication.header` lookup.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * PHP's SAPI folds a request header into a `HTTP_`-prefixed `$_SERVER` key by
 * uppercasing it and turning every `-` into `_` — a step that cannot be
 * undone later, since `X-Corsa-Secret` and `X_Corsa_Secret` arrive as the
 * same key. `EndpointService::getHeaders()`/`FlowToken::getHeaders()` strip
 * the prefix and lowercase, so a header configured with hyphens (the normal
 * HTTP convention: `X-Api-Key`, `X-Corsa-Secret`, ...) must have its hyphens
 * folded to underscores too before the lookup, or it never matches its own
 * value. Before this fix, every hyphenated custom header 403'd unconditionally,
 * regardless of whether the caller supplied the correct secret.
 */
class EndpointServiceCustomHeaderAuthTest extends TestCase {

	/**
	 * @var OpenRegisterCredentialBridge|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $authorizationService;

	/**
	 * @var EndpointService
	 */
	private EndpointService $service;

	protected function setUp(): void {
		parent::setUp();

		if (class_exists('OC\AppFramework\Http') === false) {
			$this->markTestSkipped(
				'Requires the internal OC\AppFramework\Http class, which the unit bootstrap does not stub.'
			);
		}

		$this->authorizationService = $this->createMock(OpenRegisterCredentialBridge::class);

		$reflection = new ReflectionClass(EndpointService::class);
		$this->service = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('authorizationService')->setValue($this->service, $this->authorizationService);

	}//end setUp()

	/**
	 * Invoke the private rule processor.
	 *
	 * @param array $authentication The rule's `configuration.authentication` block.
	 * @param array $data The pipeline data.
	 *
	 * @return array|JSONResponse The rule's verdict.
	 */
	private function process(array $authentication, array $data = []) {
		$rule = new ObjectEntity();
		$rule->setObject(['configuration' => ['authentication' => $authentication]]);

		$method = (new ReflectionClass(EndpointService::class))->getMethod('processAuthenticationRule');

		return $method->invoke($this->service, $rule, $data);
	}//end process()

	/**
	 * The defect: `$_SERVER['HTTP_X_CORSA_SECRET']` is what a real
	 * `X-Corsa-Secret` request header becomes, and the pipeline's `headers`
	 * bag therefore carries it under `x_corsa_secret` (underscored). A
	 * correctly-authenticated request must still reach `authorizeApiKey()`
	 * with that value.
	 *
	 * @return void
	 */
	public function testAHyphenatedCustomHeaderMatchesItsUnderscoredServerKey(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizeApiKey')
			->with('s3cr3t', ['s3cr3t' => 'bct']);

		$result = $this->process(
			[
				'type' => 'apikey',
				'header' => 'X-Corsa-Secret',
				'keys' => ['s3cr3t' => 'bct'],
			],
			['headers' => ['x_corsa_secret' => 's3cr3t']]
		);

		$this->assertIsArray($result);

	}//end testAHyphenatedCustomHeaderMatchesItsUnderscoredServerKey()

	/**
	 * A genuinely missing header still 403s — the fix narrows the lookup key
	 * mismatch, it does not loosen the header-presence guard.
	 *
	 * @return void
	 */
	public function testAMissingCustomHeaderStill403s(): void {
		$this->authorizationService->expects($this->never())->method('authorizeApiKey');

		$result = $this->process(
			[
				'type' => 'apikey',
				'header' => 'X-Corsa-Secret',
				'keys' => ['s3cr3t' => 'bct'],
			],
			['headers' => []]
		);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(403, $result->getStatus());

	}//end testAMissingCustomHeaderStill403s()

	/**
	 * The default (unconfigured) header name, `Authorization`, has no hyphen
	 * to fold — this pins that the fix does not disturb that existing path.
	 *
	 * @return void
	 */
	public function testTheDefaultAuthorizationHeaderStillWorksUnconfigured(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizeApiKey')
			->with('Bearer abc', ['Bearer abc' => 'bct']);

		$result = $this->process(
			[
				'type' => 'apikey',
				'keys' => ['Bearer abc' => 'bct'],
			],
			['headers' => ['authorization' => 'Bearer abc']]
		);

		$this->assertIsArray($result);

	}//end testTheDefaultAuthorizationHeaderStillWorksUnconfigured()
}//end class
