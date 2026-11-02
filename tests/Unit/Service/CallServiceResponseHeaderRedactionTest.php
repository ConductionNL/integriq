<?php

/**
 * Unit tests: a source's response headers are redacted before a call is stored.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Twig\Loader\ArrayLoader;

/**
 * Response-header redaction on the CallService write paths: the single save,
 * the buffered batch and the execution-trace step.
 */
class CallServiceResponseHeaderRedactionTest extends TestCase {

	/**
	 * What the upstream answers: two cookies, a token in a mixed-case header,
	 * credentials echoed back, and headers that carry no secret.
	 *
	 * @var array<string,string|array<int,string>>
	 */
	private const ANSWER_HEADERS = [
		'Content-Type' => 'application/json',
		'Set-Cookie' => ['session=abc123secret; HttpOnly', 'csrf=def456secret'],
		'Authorization' => 'Bearer echoed-upstream-token',
		'X-Auth-TOKEN' => 'mixed-case-token-value',
		'x-api-key' => 'lower-case-api-key',
		'X-RateLimit-Remaining' => '42',
	];

	/**
	 * Every secret the upstream sent; none may reach a stored record.
	 *
	 * @var array<int,string>
	 */
	private const SECRETS = [
		'abc123secret',
		'def456secret',
		'echoed-upstream-token',
		'mixed-case-token-value',
		'lower-case-api-key',
	];

	/**
	 * Single saves handed to ObjectService::saveObject().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Batches handed to ObjectService::saveObjects().
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private array $batches = [];

	/**
	 * Build the service with a capturing ObjectService and an upstream that answers with ANSWER_HEADERS.
	 *
	 * @return CallService
	 */
	private function service(): CallService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null) {
				$this->saved[] = ['object' => $object, 'schema' => $schema];
				$entity = new ObjectEntity();
				$entity->setUuid('saved-' . count($this->saved));
				$entity->setObject($object);
				return $entity;
			}
		);
		$objectService->method('saveObjects')->willReturnCallback(
			function (array $objects = []) {
				$this->batches[] = $objects;
				return [];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-10-29T12:00:00+00:00'));

		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$service = new CallService(
			$objectService,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
			new BodyCapturePolicy($appConfig, $clock),
		);

		$client = $this->createMock(Client::class);
		$client->method('request')->willReturn(new Response(200, self::ANSWER_HEADERS, '{"ok":true}'));
		$property = new ReflectionProperty(CallService::class, 'client');
		$property->setValue($service, $client);

		return $service;
	}//end service()

	/**
	 * A source without an investigation window.
	 *
	 * @return ObjectEntity
	 */
	private function source(): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('5b0e2c4d-1f3a-4e6b-8c7d-9a0b1c2d3e4f');
		$source->setObject(['name' => 'upstream', 'isEnabled' => true, 'location' => 'https://upstream.example.invalid']);

		return $source;
	}//end source()

	/**
	 * The one call log handed to the single save.
	 *
	 * @return array<string,mixed>
	 */
	private function callLog(): array {
		$logs = array_values(array_filter($this->saved, fn (array $row): bool => $row['schema'] === 'call_log'));
		$this->assertCount(1, $logs);

		return $logs[0]['object'];
	}//end callLog()

	/**
	 * Assert the stored headers carry no secret and keep the harmless headers.
	 *
	 * @param array<string,mixed> $headers The stored response headers.
	 *
	 * @return void
	 */
	private function assertRedacted(array $headers): void {
		$encoded = (string)json_encode($headers);
		foreach (self::SECRETS as $secret) {
			$this->assertStringNotContainsString($secret, $encoded);
		}

		$placeholder = SensitiveFieldRegistry::PLACEHOLDER;
		$this->assertSame([$placeholder, $placeholder], $headers['Set-Cookie']);
		$this->assertSame([$placeholder], $headers['Authorization']);
		$this->assertSame([$placeholder], $headers['X-Auth-TOKEN']);
		$this->assertSame([$placeholder], $headers['x-api-key']);
		$this->assertSame(['application/json'], $headers['Content-Type']);
		$this->assertSame(['42'], $headers['X-RateLimit-Remaining']);
	}//end assertRedacted()

	/**
	 * The stored call log holds no cookie, token or credential from the answer.
	 *
	 * @return void
	 */
	public function testTheStoredCallLogHoldsNoSecretResponseHeader(): void {
		$this->service()->call(source: $this->source(), endpoint: '/items');

		$this->assertRedacted($this->callLog()['response']['headers']);
	}//end testTheStoredCallLogHoldsNoSecretResponseHeader()

	/**
	 * A buffered call log is redacted before it waits in the buffer.
	 *
	 * @return void
	 */
	public function testABufferedCallLogHoldsNoSecretResponseHeader(): void {
		$service = $this->service();
		$service->bufferCallLogs(true);
		$service->call(source: $this->source(), endpoint: '/items');
		$service->flushCallLogs();

		$this->assertCount(1, $this->batches);
		$this->assertRedacted($this->batches[0][0]['response']['headers']);
	}//end testABufferedCallLogHoldsNoSecretResponseHeader()

	/**
	 * The trace step of a traced call holds no secret response header.
	 *
	 * @return void
	 */
	public function testTheTraceStepHoldsNoSecretResponseHeader(): void {
		$trace = new ExecutionTraceContext('redaction');
		$this->service()->call(source: $this->source(), endpoint: '/items', trace: $trace);

		$this->assertRedacted($trace->getSteps()[0]['output']['headers']);
	}//end testTheTraceStepHoldsNoSecretResponseHeader()

	/**
	 * The caller still gets the real headers: it reads Content-Disposition,
	 * pagination and cookie headers from the answer.
	 *
	 * @return void
	 */
	public function testTheCallerStillGetsTheRealHeaders(): void {
		$callLog = $this->service()->call(source: $this->source(), endpoint: '/items');

		$headers = $callLog->getObject()['response']['headers'];
		$this->assertSame(['session=abc123secret; HttpOnly', 'csrf=def456secret'], $headers['Set-Cookie']);
		$this->assertSame(['mixed-case-token-value'], $headers['X-Auth-TOKEN']);
	}//end testTheCallerStillGetsTheRealHeaders()

	/**
	 * Secret request headers, in a list or in mixed case, are stored redacted too.
	 *
	 * @return void
	 */
	public function testSecretRequestHeadersAreStoredRedacted(): void {
		$this->service()->call(
			source: $this->source(),
			endpoint: '/items',
			config: ['headers' => ['authorization' => ['Bearer request-token-in-a-list'], 'X-Session-TOKEN' => 'request-mixed-case']]
		);

		$request = (string)json_encode($this->callLog()['request']);
		$this->assertStringNotContainsString('request-token-in-a-list', $request);
		$this->assertStringNotContainsString('request-mixed-case', $request);
	}//end testSecretRequestHeadersAreStoredRedacted()
}//end class
