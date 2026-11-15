<?php

/**
 * Characterisation test for SubscriptionRequestHandler::handle.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registry
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

namespace OCA\Integriq\Tests\Unit\Service\Registry;

use OCA\Integriq\Service\Registry\RegistryUpdateClient;
use OCA\Integriq\Service\Registry\SubscriptionProviderInterface;
use OCA\Integriq\Service\Registry\SubscriptionRegistry;
use OCA\Integriq\Service\Registry\SubscriptionRequestHandler;
use OCA\Integriq\Service\Registry\SubscriptionResult;
use OCA\Integriq\Service\Registry\SubscriptionRoster;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden record of the subscription requests.
 */
class SubscriptionRequestHandleGoldenTest extends TestCase {

	/**
	 * Every call, in order.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * The handler.
	 *
	 * @param bool $withMapper Whether a schema mapper is wired.
	 *
	 * @return SubscriptionRequestHandler
	 */
	private function handler(bool $withMapper): SubscriptionRequestHandler {
		$provider = $this->createMock(SubscriptionProviderInterface::class);
		$provider->method('subscribe')->willReturnCallback(
			function (string $identity): SubscriptionResult {
				$this->log[] = ['subscribe' => $identity];
				if (str_starts_with($identity, 'bad') === true) {
					return SubscriptionResult::failed(identity: $identity, error: 'refused by registry');
				}

				return SubscriptionResult::active(identity: $identity, reference: 'ref-' . $identity);
			}
		);
		$registry = $this->createMock(SubscriptionRegistry::class);
		$registry->method('get')->willReturnCallback(
			fn (string $id): ?SubscriptionProviderInterface => ($id === 'brp' ? $provider : null)
		);

		$roster = $this->createMock(SubscriptionRoster::class);
		$roster->method('add')->willReturnCallback(
			function (string $registryId, string $identity, string $reference = ''): void {
				$this->log[] = ['roster.add' => [$registryId, $identity, $reference]];
			}
		);
		$roster->method('addTarget')->willReturnCallback(
			function (string $registryId, string $identity, string $targetSchema): void {
				$this->log[] = ['roster.addTarget' => [$registryId, $identity, $targetSchema]];
			}
		);

		$update = $this->createMock(RegistryUpdateClient::class);
		$update->method('postUpdate')->willReturnCallback(
			function (string $registryId, array $payload): int {
				$this->log[] = ['postUpdate' => [$registryId, $payload]];
				return 200;
			}
		);

		$mapper = null;
		if ($withMapper === true) {
			$mapper = $this->createMock(SchemaMapper::class);
			$mapper->method('find')->willReturnCallback(
				static function (string|int $id): ?object {
					if ($id === 'boom') {
						throw new RuntimeException('schema store down');
					}

					if ($id === 'missing') {
						return null;
					}

					$slug = ($id === 'blank' ? '' : 'slug-' . $id);
					return new class($slug) {
						/**
						 * @param string $slug The slug.
						 */
						public function __construct(private readonly string $slug) {
						}

						/**
						 * @return string
						 */
						public function getSlug(): string {
							return $this->slug;
						}
					};
				}
			);
		}

		$log = &$this->log;
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

		return new SubscriptionRequestHandler($registry, $roster, $update, $logger, $mapper);
	}//end handler()

	/**
	 * The recorded outcomes.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$cases = [
			'no identity' => [true, ['registry' => 'brp', 'schema' => 'x']],
			'no registry' => [true, ['identity' => '999']],
			'unknown registry' => [true, ['registry' => 'kvk', 'identity' => '999']],
			'active with schema' => [true, ['registry' => 'brp', 'identity' => '111', 'schema' => '12']],
			'aliases' => [true, ['registryId' => 'brp', 'identityValue' => '222', 'schema' => 'blank']],
			'schema find throws' => [true, ['registry' => 'brp', 'identity' => '333', 'schema' => 'boom']],
			'schema not found' => [true, ['registry' => 'brp', 'identity' => '444', 'schema' => 'missing']],
			'active without schema' => [true, ['registry' => 'brp', 'identity' => '555']],
			'failed with schema' => [true, ['registry' => 'brp', 'identity' => 'bad-666', 'schema' => '12']],
			'no mapper' => [false, ['registry' => 'brp', 'identity' => '777', 'schema' => '12']],
			'both keys' => [true, ['registry' => 'brp', 'registryId' => 'kvk', 'identity' => '888', 'identityValue' => '000']],
		];
		$record = [];
		foreach ($cases as $name => [$withMapper, $request]) {
			$this->log = [];
			$result = $this->handler($withMapper)->handle($request);
			$record[$name] = ['calls' => $this->log, 'result' => $result?->toArray()];
		}

		$path = __DIR__ . '/../../../fixtures/registry/subscription-request.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
