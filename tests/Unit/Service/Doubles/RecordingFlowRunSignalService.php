<?php

/**
 * Recording doubles of OpenRegister's FlowRunSignalService and RunSignal.
 *
 * Loaded only by EngineSignalServiceTest, in a separate process, so the
 * guarded no-OpenRegister branch stays reachable for the other test.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

if (class_exists(RunSignal::class) === false) {
	/**
	 * What a signal says, who says it, and the node it addresses.
	 */
	final class RunSignal {
		/**
		 * @param array       $payload  What the signaller wants the run to know.
		 * @param string|null $actorUid Who is answering.
		 * @param string|null $nodeId   The node the answer addresses.
		 */
		public function __construct(
			public readonly array $payload,
			public readonly ?string $actorUid,
			public readonly ?string $nodeId = null,
		) {
		}
	}
}

if (class_exists(FlowRunSignalService::class) === false) {
	/**
	 * Records every answer it is given.
	 */
	class FlowRunSignalService {
		/** @var list<array{0: string, 1: RunSignal}> */
		public array $answered = [];

		/**
		 * @param string    $runUuid The run to answer.
		 * @param RunSignal $signal  What is said, by whom, to which node.
		 *
		 * @return object The parked run.
		 */
		public function answerByUuid(string $runUuid, RunSignal $signal): object {
			$this->answered[] = [$runUuid, $signal];

			return new \stdClass();
		}
	}
}
