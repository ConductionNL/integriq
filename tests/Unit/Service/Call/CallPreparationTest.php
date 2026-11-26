<?php

/**
 * Unit tests: a call being prepared takes its pacing mode out of the config.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/archive/2026-11-25-sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-a-live-call-waits-briefly-background-work-waits-for-the-window-req-rlpc-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Call;

use OCA\Integriq\Service\Call\CallPreparation;
use OCA\Integriq\Service\Call\CallRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * The pacing mode: live for a gateway call or the interactive test, background otherwise.
 *
 * @covers \OCA\Integriq\Service\Call\CallPreparation
 * @uses \OCA\Integriq\Service\Call\CallRequest
 */
class CallPreparationTest extends TestCase {

	/**
	 * A call prepared with the given config and log setting.
	 *
	 * @param array $config The call's config.
	 * @param bool $persistLog False for the interactive test.
	 *
	 * @return CallPreparation The preparation.
	 */
	private function preparation(array $config, bool $persistLog = true): CallPreparation {
		$run = new CallPreparation(source: $this->createMock(ObjectEntity::class), request: new CallRequest(persistLog: $persistLog));
		$run->config = $config;

		return $run;
	}//end preparation()

	/**
	 * A gateway call is live, and its pacing key never reaches the request config.
	 *
	 * @return void
	 */
	public function testAGatewayCallIsLiveAndThePacingKeyIsRemoved(): void {
		$run = $this->preparation(config: ['query' => ['a' => 1], 'pacing' => 'live']);

		$this->assertTrue($run->takePacingMode());
		$this->assertSame(['query' => ['a' => 1]], $run->config);
		$this->assertSame(['query' => ['a' => 1]], $run->prepared['config']);
	}//end testAGatewayCallIsLiveAndThePacingKeyIsRemoved()

	/**
	 * Background work is not live; the interactive test, which keeps no log, is.
	 *
	 * @return void
	 */
	public function testBackgroundWorkIsNotLiveButTheInteractiveTestIs(): void {
		$this->assertFalse($this->preparation(config: ['headers' => []])->takePacingMode());
		$this->assertFalse($this->preparation(config: ['pacing' => 'background'])->takePacingMode());
		$this->assertTrue($this->preparation(config: [], persistLog: false)->takePacingMode());
	}//end testBackgroundWorkIsNotLiveButTheInteractiveTestIs()
}//end class
