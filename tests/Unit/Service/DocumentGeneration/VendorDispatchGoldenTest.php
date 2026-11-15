<?php

/**
 * Golden test for the brokered dispatch of the REST document generation providers.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\DocumentGeneration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DocumentGeneration;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\DocumentGeneration\SmartDocumentsProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every broker call and every outcome of the vendor calls — list,
 * render, status, fetch — on success and on each failure, against a golden file.
 */
class VendorDispatchGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/document-generation/vendor-dispatch.json';

	/**
	 * Run one operation over a broker that answers as given.
	 *
	 * @param callable          $operation The operation on the provider.
	 * @param Response|\Throwable $answer  The dispatch answer or failure.
	 *
	 * @return array The calls, warnings and outcome.
	 */
	private function exchange(callable $operation, Response|\Throwable $answer): array {
		$calls  = [];
		$broker = $this->createMock(BrokeredCallService::class);
		$broker->method('hasCredentialRef')->willReturn(true);
		$broker->method('prepare')->willReturnCallback(
			function (array $config, array $sourceData, bool $asynchronous) use (&$calls): array {
				$calls[] = ['prepare', $config, $sourceData, $asynchronous];
				return ['credentialId' => 'cred', 'actingUserId' => null];
			}
		);
		$broker->method('dispatch')->willReturnCallback(
			function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config) use (&$calls, $answer) {
				$calls[] = ['dispatch', $credentialId, $actingUserId, $method, $url, $config];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$warnings = [];
		$logger   = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		try {
			$outcome = $operation(new SmartDocumentsProvider($broker, $logger));
			if (is_object($outcome) === true) {
				$outcome = $outcome->toArray();
			}
		} catch (DocumentGenerationException $exception) {
			$outcome = ['exception' => $exception->getMessage(), 'unreachable' => $exception->isUnreachable(), 'previous' => $exception->getPrevious() !== null];
		}

		return ['calls' => $calls, 'warnings' => $warnings, 'outcome' => $outcome];

	}//end exchange()

	/**
	 * Every exchange matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryExchangeMatchesTheGoldenFile(): void {
		$config = ['baseUrl' => 'https://vendor.example/api/', 'authentication' => ['credentialRef' => 'ref']];
		$list   = static fn ($provider) => $provider->listTemplates($config);
		$render = static fn ($provider) => $provider->render($config, 'tpl-1', ['naam' => 'Jan']);
		$status = static fn ($provider) => $provider->status($config, 'job 1');
		$fetch  = static fn ($provider) => $provider->fetch($config, 'doc/1');

		$actual = [
			'list' => $this->exchange($list, new Response(200, [], '{"templates":[{"id":"t1","name":"Brief"},{"templateId":"t2"},{"name":"no id"}]}')),
			'render' => $this->exchange($render, new Response(202, [], '{"jobId":"J-1","status":"queued"}')),
			'status' => $this->exchange($status, new Response(200, [], '{"status":"done","documentId":"D-1"}')),
			'fetch' => $this->exchange($fetch, new Response(200, [], '%PDF')),
			'fetch-404' => $this->exchange($fetch, new Response(404, [], '')),
			'render-500' => $this->exchange($render, new Response(503, [], '{}')),
			'render-400' => $this->exchange($render, new Response(400, [], '{}')),
			'render-299-not-json' => $this->exchange($render, new Response(299, [], 'nope')),
			'render-configuration' => $this->exchange($render, new BrokeredCallConfigurationException(message: 'credential not found')),
			'status-transport' => $this->exchange($status, new RuntimeException('timeout')),
			'list-transport' => $this->exchange($list, new RuntimeException('timeout')),
		];

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true), json_decode((string) json_encode($actual), true));

	}//end testEveryExchangeMatchesTheGoldenFile()
}//end class
