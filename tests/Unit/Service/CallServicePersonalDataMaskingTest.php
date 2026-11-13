<?php

/**
 * Integriq — personal data masked in the call log and the trace, never in what is sent.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
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
 * A StUF-ZKN call with a BSN and a surname, inside an investigation window so
 * the bodies are captured.
 */
class CallServicePersonalDataMaskingTest extends TestCase {

	/**
	 * The BSN in the request.
	 *
	 * @var string
	 */
	private const BSN = '999993653';

	/**
	 * The surname in the request.
	 *
	 * @var string
	 */
	private const SURNAME = 'Jansen';

	/**
	 * A StUF-ZKN request body carrying `inp.bsn` and `geslachtsnaam`.
	 *
	 * @var string
	 */
	private const STUF = '<ZKN:zakLk01 xmlns:ZKN="http://www.egem.nl/StUF/sector/zkn/0310" xmlns:BG="http://www.egem.nl/StUF/sector/bg/0310">'
		. '<ZKN:object><ZKN:heeftAlsInitiator><ZKN:gerelateerde><ZKN:natuurlijkPersoon>'
		. '<BG:inp.bsn>' . self::BSN . '</BG:inp.bsn><BG:geslachtsnaam>' . self::SURNAME . '</BG:geslachtsnaam>'
		. '<BG:geboorteplaats>Utrecht</BG:geboorteplaats>'
		. '</ZKN:natuurlijkPersoon></ZKN:gerelateerde></ZKN:heeftAlsInitiator></ZKN:object></ZKN:zakLk01>';

	/**
	 * Single saves handed to ObjectService::saveObject().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The options the HTTP client was asked to send.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $sent = [];

	/**
	 * Build the service; the app config holds the given added personal data fields.
	 *
	 * @param Response $answer What the upstream answers.
	 * @param array<int,string> $added The field names an administrator added.
	 *
	 * @return CallService
	 */
	private function service(Response $answer, array $added = []): CallService {
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

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === SensitiveFieldRegistry::PERSONAL_DATA_CONFIG_KEY
				? (string)json_encode($added)
				: $default
		);

		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-10-09T12:00:00+00:00'));

		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$service = new CallService(
			$objectService,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(appConfig: $appConfig),
			new BodyCapturePolicy($appConfig, $clock),
		);

		$client = $this->createMock(Client::class);
		$client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options = []) use ($answer): Response {
				$this->sent[] = $options;
				return $answer;
			}
		);
		(new ReflectionProperty(CallService::class, 'client'))->setValue($service, $client);

		return $service;
	}//end service()

	/**
	 * A source with an open investigation window and the given request configuration.
	 *
	 * @param array<string,mixed> $configuration The request configuration.
	 *
	 * @return ObjectEntity
	 */
	private function source(array $configuration): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('7d3f0c1e-5b2a-4c8d-9e6f-0a1b2c3d4e5f');
		$source->setObject(
			[
				'name' => 'stuf-zkn',
				'isEnabled' => true,
				'location' => 'https://zaken.example.invalid',
				'configuration' => $configuration,
				'bodyCaptureUntil' => '2026-10-10T12:00:00+00:00',
				'bodyCaptureReason' => 'melding 4711',
				'bodyCaptureBy' => 'admin',
			]
		);

		return $source;
	}//end source()

	/**
	 * The stored call logs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function callLogs(): array {
		return array_values(
			array_map(
				static fn (array $row): array => $row['object'],
				array_filter($this->saved, static fn (array $row): bool => $row['schema'] === 'call_log')
			)
		);
	}//end callLogs()

	/**
	 * The StUF call is logged with the BSN and surname masked; the partner got both.
	 *
	 * @return void
	 */
	public function testAStufCallIsLoggedWithoutItsBsnAndSurname(): void {
		$service = $this->service(new Response(200, ['Content-Type' => 'text/xml'], '<ZKN:Bv03Bericht/>'));

		$service->call(source: $this->source(['body' => self::STUF]), endpoint: '/stuf', method: 'POST');

		$this->assertStringContainsString('<BG:inp.bsn>' . self::BSN . '</BG:inp.bsn>', $this->sent[0]['body']);
		$this->assertStringContainsString('<BG:geslachtsnaam>' . self::SURNAME . '</BG:geslachtsnaam>', $this->sent[0]['body']);

		$stored = $this->callLogs()[0]['request']['body'];
		$this->assertStringContainsString('<BG:inp.bsn>[afgeschermd]</BG:inp.bsn>', $stored);
		$this->assertStringContainsString('<BG:geslachtsnaam>[afgeschermd]</BG:geslachtsnaam>', $stored);
		$this->assertStringContainsString('<BG:geboorteplaats>Utrecht</BG:geboorteplaats>', $stored);
	}//end testAStufCallIsLoggedWithoutItsBsnAndSurname()

	/**
	 * Personal data in a JSON response body and the URL query is masked too.
	 *
	 * @return void
	 */
	public function testAJsonResponseAndAQueryAreMasked(): void {
		$service = $this->service(new Response(200, [], '{"personen":[{"burgerservicenummer":"999990755","naam":{"voornamen":"Piet"},"leeftijd":40}]}'));

		$service->call(
			source: $this->source(['query' => ['bsn' => self::BSN, 'fields' => 'naam']]),
			endpoint: '/personen',
			method: 'GET'
		);

		$log = $this->callLogs()[0];
		$this->assertSame(
			'{"personen":[{"burgerservicenummer":"[afgeschermd]","naam":{"voornamen":"[afgeschermd]"},"leeftijd":40}]}',
			$log['response']['body']
		);
		$this->assertSame('[afgeschermd]', $log['request']['query']['bsn']);
		$this->assertSame('naam', $log['request']['query']['fields']);
		$this->assertSame(self::BSN, $this->sent[0]['query']['bsn']);
		$this->assertStringNotContainsString(self::BSN, (string)json_encode($log));
	}//end testAJsonResponseAndAQueryAreMasked()

	/**
	 * A field an administrator added is masked; without the addition it is not.
	 *
	 * @return void
	 */
	public function testAnAddedFieldIsMasked(): void {
		$this->service(new Response(200, [], '{"kenteken":"AB-123-C"}'), ['kenteken'])
			->call(source: $this->source([]), endpoint: '/voertuigen', method: 'GET');
		$this->assertSame('{"kenteken":"[afgeschermd]"}', $this->callLogs()[0]['response']['body']);

		$this->saved = [];
		$this->service(new Response(200, [], '{"kenteken":"AB-123-C"}'))
			->call(source: $this->source([]), endpoint: '/voertuigen', method: 'GET');
		$this->assertSame('{"kenteken":"AB-123-C"}', $this->callLogs()[0]['response']['body']);
	}//end testAnAddedFieldIsMasked()

	/**
	 * A failed call keeps the real request as `replayRequest`, because a replay sends it,
	 * while the request shown in the log is masked.
	 *
	 * @return void
	 */
	public function testAFailedCallKeepsTheRealRequestOnlyForItsReplay(): void {
		$service = $this->service(new Response(502, [], 'bad gateway'));

		$service->call(source: $this->source(['json' => ['bsn' => self::BSN]]), endpoint: '/zaken', method: 'POST');

		$log = $this->callLogs()[0];
		$this->assertSame('[afgeschermd]', $log['request']['json']['bsn']);
		$this->assertSame(self::BSN, $log['replayRequest']['json']['bsn']);
	}//end testAFailedCallKeepsTheRealRequestOnlyForItsReplay()

	/**
	 * The trace snapshot holds the masked request.
	 *
	 * @return void
	 */
	public function testTheTraceSnapshotIsMasked(): void {
		$trace = new ExecutionTraceContext('trace-pdm');

		$this->service(new Response(200, [], '{"email":"piet@example.invalid"}'))->call(
			source: $this->source(['json' => ['bsn' => self::BSN]]),
			endpoint: '/zaken',
			method: 'POST',
			trace: $trace
		);

		$step = $trace->getSteps()[0];
		$this->assertSame('[afgeschermd]', $step['input']['json']['bsn']);
		$this->assertSame('{"email":"[afgeschermd]"}', $step['output']['body']);
	}//end testTheTraceSnapshotIsMasked()
}//end class
