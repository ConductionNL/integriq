<?php

/**
 * Stub for OCA\OpenRegister\Service\Flow\FlowNodeCall.
 *
 * Same constructor as the OpenRegister value object, so a test builds and
 * reads it the way production does when the peer app is not installed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * One call of a flow node: the input items, the step configuration and the run context.
 */
final class FlowNodeCall {
	/**
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata, not the data channel.
	 */
	public function __construct(
		public readonly array $items = [],
		public readonly array $config = [],
		public readonly array $context = [],
	) {
	}
}
