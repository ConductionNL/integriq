<?php

/**
 * Tests that the verdict key Hermiq publishes on first use is found by the same request.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\AgentTools
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\AgentTools;

use OCA\Integriq\Service\AgentTools\AgentActionRefusedException;
use OCA\Integriq\Service\AgentTools\ApprovalVerdictVerifier;
use OCA\Integriq\Service\AgentTools\HermiqVerdictClient;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The verifier reloads the app values once when the key is not in this request's cache.
 */
final class ApprovalVerdictVerifierKeyReloadTest extends TestCase {

	/**
	 * Read the verifier's key through its private reader.
	 *
	 * @param IAppConfig $appConfig The app values.
	 *
	 * @return string The raw key.
	 */
	private function publicKeyOf(IAppConfig $appConfig): string {
		$verifier = new ApprovalVerdictVerifier(
			client: $this->createMock(originalClassName: HermiqVerdictClient::class),
			appConfig: $appConfig
		);

		return (new ReflectionMethod(objectOrMethod: $verifier, method: 'publicKey'))->invoke($verifier);
	}//end publicKeyOf()

	/**
	 * Hermiq published its key during this request: the reload finds it.
	 *
	 * @return void
	 */
	public function testAKeyPublishedDuringThisRequestIsFoundAfterOneReload(): void {
		$key = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
		$reloaded = false;
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->expects($this->once())->method('clearCache')->with(true)->willReturnCallback(
			function () use (&$reloaded): void {
				$reloaded = true;
			}
		);
		$appConfig->method('getValueString')->willReturnCallback(
			function () use (&$reloaded, $key): string {
				if ($reloaded === true) {
					return base64_encode($key);
				}

				return '';
			}
		);

		$this->assertSame(expected: $key, actual: $this->publicKeyOf(appConfig: $appConfig));
	}//end testAKeyPublishedDuringThisRequestIsFoundAfterOneReload()

	/**
	 * A key already in the cache is used without a reload.
	 *
	 * @return void
	 */
	public function testACachedKeyNeedsNoReload(): void {
		$key = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->expects($this->never())->method('clearCache');
		$appConfig->method('getValueString')->willReturn(base64_encode($key));

		$this->assertSame(expected: $key, actual: $this->publicKeyOf(appConfig: $appConfig));
	}//end testACachedKeyNeedsNoReload()

	/**
	 * No key after the reload either: the action is refused, as before.
	 *
	 * @return void
	 */
	public function testNoKeyAfterTheReloadIsStillRefused(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->expects($this->once())->method('clearCache');
		$appConfig->method('getValueString')->willReturn('');

		$this->expectException(exception: AgentActionRefusedException::class);
		$this->publicKeyOf(appConfig: $appConfig);
	}//end testNoKeyAfterTheReloadIsStillRefused()
}//end class
