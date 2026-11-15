<?php

/**
 * Characterisation test for SynchronizationMessageGate::inspect.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\MessageValidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\MessageValidation;

use OCA\Integriq\Service\MessageValidation\MessageValidationRefusedException;
use OCA\Integriq\Service\MessageValidation\EndpointMessageGate;
use OCA\Integriq\Service\MessageValidation\SynchronizationMessageGate;
use OCA\Integriq\Service\MessageValidation\ValidationOutcome;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden record of the synchronisation message gate.
 */
class SynchronizationMessageGateGoldenTest extends TestCase {

	/**
	 * The recorded inspections.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$log = [];
		$messages = $this->createMock(EndpointMessageGate::class);
		$messages->method('checkDeclared')->willReturnCallback(
			static function (array $declared, string $direction, mixed $body) use (&$log): ValidationOutcome {
				$log[] = ['checkDeclared' => [$declared, $direction, $body]];
				if (($body['ok'] ?? false) === true) {
					return ValidationOutcome::valid();
				}

				$errors = [];
				for ($i = 1; $i <= (int)($body['errors'] ?? 1); $i++) {
					$errors[] = ['path' => '/field' . $i, 'message' => 'wrong ' . $i];
				}

				return ValidationOutcome::failed($errors);
			}
		);
		$logger = new class($log) extends AbstractLogger {
			/**
			 * @param list<mixed> $log The shared log.
			 */
			public function __construct(private array &$log) {
			}

			/**
			 * @param mixed                $level   Level.
			 * @param string|\Stringable   $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};
		$gate = new SynchronizationMessageGate($messages, $logger);

		$record = 'record';
		$cases = [
			'no validation' => [[], SynchronizationMessageGate::SOURCE, ['ok' => false], 'o-1'],
			'blank schema' => [['validation' => ['messageSchema' => '']], SynchronizationMessageGate::SOURCE, [], 'o-1'],
			'validation not an array' => [['validation' => 'x'], SynchronizationMessageGate::SOURCE, [], 'o-1'],
			'source valid' => [['validation' => ['messageSchema' => 'zaak']], SynchronizationMessageGate::SOURCE, ['ok' => true], 'o-1'],
			'source recorded' => [['validation' => ['messageSchema' => 'zaak']], SynchronizationMessageGate::SOURCE, ['errors' => 2], 'o-1'],
			'target recorded, no origin' => [['validation' => ['messageSchema' => 'zaak', 'mode' => $record]], SynchronizationMessageGate::TARGET, ['errors' => 25], null],
			'other side' => [['validation' => ['messageSchema' => 'zaak']], 'elsewhere', ['errors' => 1], 'o-2'],
			'source refused' => [['validation' => ['messageSchema' => 'zaak', 'mode' => 'refuse']], SynchronizationMessageGate::SOURCE, ['errors' => 3], 'o-3'],
			'target refused' => [['validation' => ['messageSchema' => 7, 'mode' => 'refuse']], SynchronizationMessageGate::TARGET, ['errors' => 1], 'o-4'],
			'refuse but valid' => [['validation' => ['messageSchema' => 'zaak', 'mode' => 'refuse']], SynchronizationMessageGate::TARGET, ['ok' => true], 'o-5'],
		];
		$result = [];
		foreach ($cases as $name => [$config, $side, $message, $originId]) {
			$log = [];
			try {
				$answer = ['finding' => $gate->inspect($config, $side, $message, $originId)];
			} catch (MessageValidationRefusedException $e) {
				$answer = ['refused' => $e->getMessage()];
			}

			$result[$name] = $answer + ['calls' => $log];
		}

		$path = __DIR__ . '/../../../fixtures/message-validation/synchronization-gate.json';
		$actual = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
