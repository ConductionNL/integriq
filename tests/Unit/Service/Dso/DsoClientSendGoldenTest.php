<?php

/**
 * Golden master of DsoClient::send().
 *
 * Records, per case, the returned reference or the thrown exception (class,
 * message, previous class), every request that went out (method, URL,
 * headers, body) and the warnings logged, over token and mTLS mode,
 * missing configuration, unknown type, transport failures and non-2xx
 * answers. A derived local reference has its timestamp replaced by T.
 * Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\Dso\DsoClient;
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Recorded send() behaviour per case.
 *
 * @covers \OCA\Integriq\Service\Dso\DsoClient
 * @uses   \OCA\Integriq\Exception\DsoProviderException
 * @uses   \OCA\Integriq\Exception\MtlsTransportException
 * @uses   \OCA\Integriq\Service\Mtls\MtlsCertificateBundle
 */
class DsoClientSendGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../fixtures/golden/dso-client-send.json';

	private const BASE = ['baseUrl' => 'https://dso.example/api/v1/', 'authentication' => ['encryptedToken' => 'cipher']];

	/**
	 * The cases: configuration, type, Guzzle answers, mTLS behaviour.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$mtls = ['baseUrl' => 'https://dso.example/api/v1', 'authentication' => ['mode' => 'mtls', 'mtls' => []]];
		return [
			'status-json-ref' => ['config' => self::BASE, 'type' => 'status', 'answers' => [new Response(201, [], '{"ref":"R-1"}')]],
			'besluit-empty-body' => ['config' => self::BASE, 'type' => 'besluit', 'answers' => [new Response(200, [], '')]],
			'ref-not-string' => ['config' => self::BASE, 'type' => 'status', 'answers' => [new Response(200, [], '{"ref":5}')]],
			'scheme' => ['config' => ['baseUrl' => 'https://dso.example', 'authentication' => ['encryptedToken' => 'c', 'scheme' => 'Token']], 'type' => 'status', 'answers' => [new Response(200, [], '{"ref":"R-2"}')]],
			'http-500' => ['config' => self::BASE, 'type' => 'status', 'answers' => [new Response(500, [], 'boom')]],
			'http-199' => ['config' => self::BASE, 'type' => 'status', 'answers' => [new Response(199, [], '')]],
			'http-300' => ['config' => self::BASE, 'type' => 'status', 'answers' => [new Response(300, [], '')]],
			'transport' => ['config' => self::BASE, 'type' => 'status', 'answers' => [new ConnectException('refused', new Request('POST', 'https://dso.example'))]],
			'no-base' => ['config' => ['authentication' => ['encryptedToken' => 'c']], 'type' => 'status', 'answers' => []],
			'base-slash-only' => ['config' => ['baseUrl' => '/', 'authentication' => ['encryptedToken' => 'c']], 'type' => 'status', 'answers' => []],
			'unknown-type' => ['config' => self::BASE, 'type' => 'other', 'answers' => []],
			'no-token' => ['config' => ['baseUrl' => 'https://dso.example'], 'type' => 'status', 'answers' => []],
			'decrypt-fails' => ['config' => ['baseUrl' => 'https://dso.example', 'authentication' => ['encryptedToken' => 'bad']], 'type' => 'status', 'answers' => []],
			'mtls-ok' => ['config' => $mtls, 'type' => 'besluit', 'mtls' => 'ok'],
			'mtls-fails' => ['config' => $mtls, 'type' => 'status', 'mtls' => 'fail'],
			'mtls-guzzle' => ['config' => $mtls, 'type' => 'status', 'mtls' => 'guzzle'],
			'mtls-404' => ['config' => $mtls, 'type' => 'status', 'mtls' => '404'],
		];
	}//end cases()

	/**
	 * Run one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$history = [];
		$stack   = HandlerStack::create(new MockHandler($case['answers'] ?? []));
		$stack->push(Middleware::history($history));

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(
			static function (string $cipher) {
				if ($cipher === 'bad') {
					throw new RuntimeException('bad cipher');
				}

				return 'plain-' . $cipher;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => 't(' . $text . ')');

		$warnings = [];
		$logger   = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$warnings) {
				$warnings[] = [$message, $context];
			}
		);

		$mtlsCalls = [];
		$resolver  = $this->createMock(MtlsConfigResolver::class);
		$resolver->method('isMtlsConfigured')->willReturn(isset($case['mtls']));
		$resolver->method('resolve')->willReturn(new MtlsCertificateBundle(certificatePem: 'CERT', privateKeyPem: 'KEY'));
		$transport = $this->createMock(MtlsTransportService::class);
		$transport->method('request')->willReturnCallback(
			function ($client, string $method, string $url, array $options) use (&$mtlsCalls, $case) {
				$mtlsCalls[] = [$method, $url, $options];
				return match ($case['mtls']) {
					'fail' => throw new MtlsTransportException('handshake', 'MTLS_HANDSHAKE'),
					'guzzle' => throw new ConnectException('down', new Request('POST', $url)),
					'404' => new Response(404, [], ''),
					default => new Response(200, [], '{"ref":"M-1"}'),
				};
			}
		);

		$client = new DsoClient(new Client(['handler' => $stack]), $crypto, $l10n, $logger, $resolver, $transport);
		$out    = [];
		try {
			$ref        = $client->send($case['config'], 'req-1', $case['type'], ['status' => 'x', 'n' => 1]);
			$out['ref'] = preg_replace('/-\d{9,}$/', '-T', $ref);
		} catch (Throwable $e) {
			$out['thrown'] = [get_class($e), $e->getMessage(), $e->getPrevious() === null ? null : get_class($e->getPrevious())];
		}

		$out['requests'] = array_map(
			static fn (array $h) => [$h['request']->getMethod(), (string)$h['request']->getUri(), $h['request']->getHeaders(), (string)$h['request']->getBody()],
			$history
		);
		$out['mtls']     = $mtlsCalls;
		$out['warnings'] = array_map(static fn (array $w) => [$w[0], array_keys($w[1]), $w[1]['errorCode'] ?? null], $warnings);
		return $out;
	}//end runCase()

	/**
	 * Every case against the recorded fixture.
	 *
	 * @return void
	 */
	public function testSendMatchesTheRecording(): void {
		$found = [];
		foreach ($this->cases() as $name => $case) {
			$found[$name] = $this->runCase(case: $case);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, json_decode((string)json_encode($found[$name]), true), $name);
		}
	}//end testSendMatchesTheRecording()
}//end class
