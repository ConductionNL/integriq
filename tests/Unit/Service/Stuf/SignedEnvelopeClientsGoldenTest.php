<?php

/**
 * Golden tests for the four signed-envelope education clients.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Stuf;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolverInterface;
use OCA\Integriq\Adapters\Digikoppeling\SignedSoapProfileInterface;
use OCA\Integriq\Exception\OsoProviderException;
use OCA\Integriq\Exception\RodProviderException;
use OCA\Integriq\Exception\UwlrEduVProviderException;
use OCA\Integriq\Exception\VerzuimloketProviderException;
use OCA\Integriq\Service\Oso\OsoKennisnetClient;
use OCA\Integriq\Service\Rod\RodEdukoppelingClient;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVKennisnetClient;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketEdukoppelingClient;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pins every outcome of the send path of the OSO, ROD, UWLR/Edu-V and
 * Verzuimloket clients once signing material resolves: the endpoint refusal,
 * the request sent, the transport failure, the HTTP status refusal, the
 * empty-body fallback and the verified reference.
 */
class SignedEnvelopeClientsGoldenTest extends TestCase {

	/**
	 * Requests the mock handler saw.
	 *
	 * @var array<int, array{request: Request}>
	 */
	private array $history = [];

	/**
	 * Warnings the logger received.
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $warnings = [];

	/**
	 * The clients under test with their expected wording.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: array}>
	 */
	public static function clients(): array {
		return [
			'oso' => [
				OsoKennisnetClient::class,
				OsoProviderException::class,
				'OSO endpoint missing',
				'The OSO export request failed unexpectedly: ',
				'OSO endpoint responded with HTTP ',
				['url' => 'https://oso.example.nl/export', 'header' => null, 'log' => '[OsoKennisnetClient] unexpected transport failure'],
			],
			'rod' => [
				RodEdukoppelingClient::class,
				RodProviderException::class,
				'DUO ROD endpoint missing',
				'The DUO ROD request failed unexpectedly: ',
				'DUO ROD endpoint responded with HTTP ',
				['url' => 'https://oso.example.nl/export', 'header' => ['X-Berichtsoort', 'kind-1'], 'log' => '[RodEdukoppelingClient] unexpected transport failure'],
			],
			'uwlr' => [
				UwlrEduVKennisnetClient::class,
				UwlrEduVProviderException::class,
				'UWLR/Edu-V endpoint missing',
				'The UWLR/Edu-V send request failed unexpectedly: ',
				'UWLR/Edu-V endpoint responded with HTTP ',
				['url' => 'https://oso.example.nl/export/kind-1', 'header' => null, 'log' => '[UwlrEduVKennisnetClient] unexpected transport failure'],
			],
			'verzuimloket' => [
				VerzuimloketEdukoppelingClient::class,
				VerzuimloketProviderException::class,
				'DUO Verzuimloket endpoint missing',
				'The DUO Verzuimloket request failed unexpectedly: ',
				'DUO Verzuimloket endpoint responded with HTTP ',
				['url' => 'https://oso.example.nl/export', 'header' => ['X-Melding-Type', 'kind-1'], 'log' => '[VerzuimloketEdukoppelingClient] unexpected transport failure'],
			],
		];

	}//end clients()

	/**
	 * Build a client whose signing material resolves, over the given responses.
	 *
	 * @param string $class     The client class.
	 * @param array  $responses The queued responses or exceptions.
	 *
	 * @return object The client under test.
	 */
	private function buildClient(string $class, array $responses): object {
		$this->history  = [];
		$this->warnings = [];
		$stack          = HandlerStack::create(new MockHandler($responses));
		$stack->push(Middleware::history($this->history));

		$resolver = $this->createMock(PkiOverheidCredentialResolverInterface::class);
		$profile  = $this->createMock(SignedSoapProfileInterface::class);
		$profile->method('buildSignedRequest')->willReturnCallback(
			static fn (string $certificateRef, string $stufBodyXml): string => '<signed ref="'.$certificateRef.'">'.$stufBodyXml.'</signed>'
		);
		$profile->method('verifyResponse')->willReturnCallback(
			static fn (string $responseXml): string => 'verified:'.$responseXml
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new $class(new Client(['handler' => $stack]), $resolver, $profile, $l10n, $logger);

	}//end buildClient()

	/**
	 * Send through the client's own entry point.
	 *
	 * @param object $client        The client.
	 * @param array  $configuration The source configuration.
	 *
	 * @return string The reference.
	 */
	private function send(object $client, array $configuration): string {
		if ($client instanceof OsoKennisnetClient) {
			return $client->sendExport($configuration, 'kenmerk-1', '<Envelope/>');
		}

		return $client->send($configuration, 'kind-1', 'kenmerk-1', '<Envelope/>');

	}//end send()

	/**
	 * Capture the throwable a send raises.
	 *
	 * @param object $client        The client.
	 * @param array  $configuration The source configuration.
	 *
	 * @return Throwable The raised throwable.
	 */
	private function failure(object $client, array $configuration): Throwable {
		try {
			$this->send($client, $configuration);
		} catch (Throwable $throwable) {
			return $throwable;
		}

		$this->fail('The send did not fail.');

	}//end failure()

	/**
	 * A blank endpoint is refused before anything is signed or sent.
	 *
	 * @param string $class     The client class.
	 * @param string $exception The provider exception class.
	 * @param string $missing   The endpoint-missing wording.
	 *
	 * @return void
	 */
	#[DataProvider('clients')]
	public function testBlankEndpointIsRefused(string $class, string $exception, string $missing): void {
		$failure = $this->failure($this->buildClient($class, []), ['certificateRef' => 'cert-1', 'endpoint' => '/']);

		$this->assertSame($exception, $failure::class);
		$this->assertSame($missing.': `configuration.endpoint` is required.', $failure->getMessage());
		$this->assertSame([], $this->history);

	}//end testBlankEndpointIsRefused()

	/**
	 * The signed envelope goes out as one POST with the expected URL and headers.
	 *
	 * @param string $class     The client class.
	 * @param string $exception The provider exception class.
	 * @param string $missing   The endpoint-missing wording.
	 * @param string $transport The transport-failure wording.
	 * @param string $status    The HTTP-status wording.
	 * @param array  $expected  The expected URL, extra header and log line.
	 *
	 * @return void
	 */
	#[DataProvider('clients')]
	public function testSignedEnvelopeIsPostedAndVerified(
		string $class,
		string $exception,
		string $missing,
		string $transport,
		string $status,
		array $expected
	): void {
		$client    = $this->buildClient($class, [new Response(200, [], " <Answer/>\n")]);
		$reference = $this->send($client, ['certificateRef' => 'cert-1', 'endpoint' => 'https://oso.example.nl/export/']);

		$this->assertSame('verified:<Answer/>', $reference);
		$this->assertCount(1, $this->history);
		$request = $this->history[0]['request'];
		$this->assertSame('POST', $request->getMethod());
		$this->assertSame($expected['url'], (string) $request->getUri());
		$this->assertSame('application/xml', $request->getHeaderLine('Content-Type'));
		$this->assertSame('<signed ref="cert-1"><Envelope/></signed>', (string) $request->getBody());
		$extra = array_values(array_filter(array_keys($request->getHeaders()), static fn (string $name): bool => str_starts_with($name, 'X-')));
		if ($expected['header'] === null) {
			$this->assertSame([], $extra);
			return;
		}

		$this->assertSame([$expected['header'][0]], $extra);
		$this->assertSame($expected['header'][1], $request->getHeaderLine($expected['header'][0]));

	}//end testSignedEnvelopeIsPostedAndVerified()

	/**
	 * An empty answer body falls back to the caller's kenmerk.
	 *
	 * @param string $class The client class.
	 *
	 * @return void
	 */
	#[DataProvider('clients')]
	public function testEmptyAnswerFallsBackToKenmerk(string $class): void {
		$client = $this->buildClient($class, [new Response(204, [], "  \n")]);

		$this->assertSame('kenmerk-1', $this->send($client, ['certificateRef' => 'cert-1', 'endpoint' => 'https://oso.example.nl/export']));

	}//end testEmptyAnswerFallsBackToKenmerk()

	/**
	 * A non-2xx answer is refused with its status, for both edges.
	 *
	 * @param string $class     The client class.
	 * @param string $exception The provider exception class.
	 * @param string $missing   The endpoint-missing wording.
	 * @param string $transport The transport-failure wording.
	 * @param string $status    The HTTP-status wording.
	 *
	 * @return void
	 */
	#[DataProvider('clients')]
	public function testNonSuccessStatusIsRefused(string $class, string $exception, string $missing, string $transport, string $status): void {
		foreach ([199, 300, 500] as $code) {
			$failure = $this->failure(
				$this->buildClient($class, [new Response($code, [], '<Answer/>')]),
				['certificateRef' => 'cert-1', 'endpoint' => 'https://oso.example.nl/export']
			);

			$this->assertSame($exception, $failure::class);
			$this->assertSame($status.$code.'.', $failure->getMessage());
			$this->assertNull($failure->getPrevious());
		}

	}//end testNonSuccessStatusIsRefused()

	/**
	 * A transport failure is logged and rethrown with the Guzzle exception as previous.
	 *
	 * @param string $class     The client class.
	 * @param string $exception The provider exception class.
	 * @param string $missing   The endpoint-missing wording.
	 * @param string $transport The transport-failure wording.
	 * @param string $status    The HTTP-status wording.
	 * @param array  $expected  The expected URL, extra header and log line.
	 *
	 * @return void
	 */
	#[DataProvider('clients')]
	public function testTransportFailureIsLoggedAndRethrown(
		string $class,
		string $exception,
		string $missing,
		string $transport,
		string $status,
		array $expected
	): void {
		$cause   = new ConnectException('refused for 123456789', new Request('POST', 'https://oso.example.nl/export'));
		$failure = $this->failure(
			$this->buildClient($class, [$cause]),
			['certificateRef' => 'cert-1', 'endpoint' => 'https://oso.example.nl/export']
		);

		$detail = 'refused for 123456789';
		if ($class === RodEdukoppelingClient::class) {
			$detail = 'refused for [persoonsgebonden nummer]';
		}

		$this->assertSame($exception, $failure::class);
		$this->assertSame($transport.$detail, $failure->getMessage());
		$this->assertSame($cause, $failure->getPrevious());
		$this->assertSame([[$expected['log'], ['exception' => $detail]]], $this->warnings);

	}//end testTransportFailureIsLoggedAndRethrown()
}//end class
