<?php

/**
 * Unit tests for KlantinteractiesClient.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/kiss-kcc-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Kiss;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\KissProviderException;
use OCA\OpenConnector\Service\Kiss\KlantinteractiesClient;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the generic REST KISS/Klantinteracties client (auth header, expansion params, payload shapes).
 *
 * @spec openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md#requirement-klantinteracties-provider-abstraction-with-log-and-rest-bindings
 */
class KlantinteractiesClientTest extends TestCase {

	/**
	 * @var array<int,array{request:\GuzzleHttp\Psr7\Request}>
	 */
	private array $history = [];

	/**
	 * @var ICrypto|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $crypto;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * The valid configuration used by every "happy path" test.
	 *
	 * @var array
	 */
	private array $configuration = [
		'baseUrl' => 'https://kiss.example.nl/api/v1',
		'authentication' => ['encryptedToken' => 'ciphertext-blob'],
	];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('decrypt')->willReturn('raw-token-value');

		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);

		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build a client whose Guzzle client is backed by a MockHandler queue.
	 *
	 * @param array<int,Response> $responses The queued mock responses.
	 *
	 * @return KlantinteractiesClient The client under test.
	 */
	private function buildClient(array $responses): KlantinteractiesClient {
		$mock = new MockHandler($responses);
		$stack = HandlerStack::create($mock);

		$this->history = [];
		$stack->push(Middleware::history($this->history));

		return new KlantinteractiesClient(
			new Client(['handler' => $stack]),
			$this->crypto,
			$this->l,
			$this->logger
		);

	}//end buildClient()

	/**
	 * listKlantcontacten() sends the Authorization header with the default `Token` scheme.
	 *
	 * @return void
	 */
	public function testListSendsTokenAuthorizationHeader(): void {
		$client = $this->buildClient([new Response(200, [], json_encode(['results' => []]))]);

		$client->listKlantcontacten(sourceConfiguration: $this->configuration, since: null, pageSize: 50);

		$this->assertCount(1, $this->history);
		$this->assertSame(
			'Token raw-token-value',
			$this->history[0]['request']->getHeaderLine('Authorization')
		);

	}//end testListSendsTokenAuthorizationHeader()

	/**
	 * A configured authentication.scheme overrides the default `Token` scheme.
	 *
	 * @return void
	 */
	public function testListHonoursConfiguredAuthScheme(): void {
		$configuration = $this->configuration;
		$configuration['authentication']['scheme'] = 'Bearer';

		$client = $this->buildClient([new Response(200, [], json_encode(['results' => []]))]);
		$client->listKlantcontacten(sourceConfiguration: $configuration, since: null, pageSize: 50);

		$this->assertSame(
			'Bearer raw-token-value',
			$this->history[0]['request']->getHeaderLine('Authorization')
		);

	}//end testListHonoursConfiguredAuthScheme()

	/**
	 * listKlantcontacten() requests the betrokkenen+onderwerpobjecten expand, sort, and page size.
	 *
	 * @return void
	 */
	public function testListRequestsExpandSortAndPageSize(): void {
		$client = $this->buildClient([new Response(200, [], json_encode(['results' => []]))]);

		$client->listKlantcontacten(sourceConfiguration: $this->configuration, since: null, pageSize: 25);

		$request = $this->history[0]['request'];
		$this->assertSame('GET', $request->getMethod());
		$this->assertSame('/api/v1/klantcontacten', $request->getUri()->getPath());
		parse_str($request->getUri()->getQuery(), $query);
		$this->assertSame('betrokkenen,onderwerpobjecten', $query['expand']);
		$this->assertSame('registratiedatum', $query['sorteer']);
		$this->assertSame('25', $query['pageSize']);
		$this->assertArrayNotHasKey('registratiedatum__gte', $query);

	}//end testListRequestsExpandSortAndPageSize()

	/**
	 * A non-null `since` is translated to the VNG `registratiedatum__gte` filter.
	 *
	 * @return void
	 */
	public function testListAppliesSinceAsGteFilter(): void {
		$client = $this->buildClient([new Response(200, [], json_encode(['results' => []]))]);

		$client->listKlantcontacten(
			sourceConfiguration: $this->configuration,
			since: '2026-07-01T00:00:00+00:00',
			pageSize: 50
		);

		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertSame('2026-07-01T00:00:00+00:00', $query['registratiedatum__gte']);

	}//end testListAppliesSinceAsGteFilter()

	/**
	 * listKlantcontacten() computes nextCursor as the max registratiedatum in the page.
	 *
	 * @return void
	 */
	public function testListComputesNextCursorAsMaxRegistratiedatum(): void {
		$body = [
			'results' => [
				['uuid' => 'a', 'registratiedatum' => '2026-07-01T10:00:00+00:00'],
				['uuid' => 'b', 'registratiedatum' => '2026-07-02T10:00:00+00:00'],
				['uuid' => 'c', 'registratiedatum' => '2026-07-01T15:00:00+00:00'],
			],
		];
		$client = $this->buildClient([new Response(200, [], json_encode($body))]);

		$result = $client->listKlantcontacten(sourceConfiguration: $this->configuration, since: null, pageSize: 50);

		$this->assertCount(3, $result['items']);
		$this->assertSame('2026-07-02T10:00:00+00:00', $result['nextCursor']);

	}//end testListComputesNextCursorAsMaxRegistratiedatum()

	/**
	 * An empty results page yields a null nextCursor.
	 *
	 * @return void
	 */
	public function testListWithEmptyResultsYieldsNullCursor(): void {
		$client = $this->buildClient([new Response(200, [], json_encode(['results' => []]))]);

		$result = $client->listKlantcontacten(sourceConfiguration: $this->configuration, since: null, pageSize: 50);

		$this->assertSame([], $result['items']);
		$this->assertNull($result['nextCursor']);

	}//end testListWithEmptyResultsYieldsNullCursor()

	/**
	 * createKlantcontact() posts the klantcontact payload and extracts the `uuid`.
	 *
	 * @return void
	 */
	public function testCreateKlantcontactPostsPayloadAndExtractsUuid(): void {
		$client = $this->buildClient([new Response(201, [], json_encode(['uuid' => 'kc-1']))]);

		$id = $client->createKlantcontact(
			sourceConfiguration: $this->configuration,
			payload: ['onderwerp' => 'Vraag', 'channel' => 'telefoon']
		);

		$this->assertSame('kc-1', $id);
		$request = $this->history[0]['request'];
		$this->assertSame('POST', $request->getMethod());
		$this->assertSame('/api/v1/klantcontacten', $request->getUri()->getPath());
		$body = json_decode((string)$request->getBody(), true);
		$this->assertSame('Vraag', $body['onderwerp']);
		$this->assertArrayNotHasKey('betrokkene', $body);

	}//end testCreateKlantcontactPostsPayloadAndExtractsUuid()

	/**
	 * createKlantcontact() with a `betrokkene` payload issues a secondary POST /betrokkenen carrying the klantcontact FK.
	 *
	 * @return void
	 */
	public function testCreateKlantcontactAlsoCreatesBetrokkene(): void {
		$client = $this->buildClient(
			[
				new Response(201, [], json_encode(['uuid' => 'kc-1'])),
				new Response(201, [], json_encode(['uuid' => 'betr-1'])),
			]
		);

		$client->createKlantcontact(
			sourceConfiguration: $this->configuration,
			payload: ['onderwerp' => 'Vraag', 'betrokkene' => ['rol' => 'klant']]
		);

		$this->assertCount(2, $this->history);
		$involvedPartyRequest = $this->history[1]['request'];
		$this->assertSame('/api/v1/betrokkenen', $involvedPartyRequest->getUri()->getPath());
		$body = json_decode((string)$involvedPartyRequest->getBody(), true);
		$this->assertSame('klant', $body['rol']);
		$this->assertSame('kc-1', $body['klantcontact']['uuid']);

	}//end testCreateKlantcontactAlsoCreatesBetrokkene()

	/**
	 * A betrokkene-creation failure is swallowed — the klantcontact id is still returned.
	 *
	 * @return void
	 */
	public function testCreateKlantcontactSurvivesBetrokkeneFailure(): void {
		$client = $this->buildClient(
			[
				new Response(201, [], json_encode(['uuid' => 'kc-1'])),
				new Response(500, [], 'betrokkene service down'),
			]
		);

		$id = $client->createKlantcontact(
			sourceConfiguration: $this->configuration,
			payload: ['onderwerp' => 'Vraag', 'betrokkene' => ['rol' => 'klant']]
		);

		$this->assertSame('kc-1', $id);

	}//end testCreateKlantcontactSurvivesBetrokkeneFailure()

	/**
	 * linkOnderwerpobject() posts the onderwerpobjectidentificator with codeSoortObjectId=UUID for a UUID-shaped reference.
	 *
	 * @return void
	 */
	public function testLinkOnderwerpobjectClassifiesUuidReference(): void {
		$client = $this->buildClient([new Response(201, [], json_encode(['uuid' => 'obj-1']))]);

		$id = $client->linkOnderwerpobject(
			sourceConfiguration: $this->configuration,
			customerContactId: 'kc-1',
			caseReference: '11111111-2222-3333-4444-555555555555',
			caseObjectType: 'zaak'
		);

		$this->assertSame('obj-1', $id);
		$request = $this->history[0]['request'];
		$this->assertSame('/api/v1/onderwerpobjecten', $request->getUri()->getPath());
		$body = json_decode((string)$request->getBody(), true);
		$this->assertSame('kc-1', $body['klantcontact']['uuid']);
		$this->assertSame('11111111-2222-3333-4444-555555555555', $body['onderwerpobjectidentificator']['objectId']);
		$this->assertSame('zaak', $body['onderwerpobjectidentificator']['codeObjecttype']);
		$this->assertSame('ZRC', $body['onderwerpobjectidentificator']['codeRegister']);
		$this->assertSame('UUID', $body['onderwerpobjectidentificator']['codeSoortObjectId']);

	}//end testLinkOnderwerpobjectClassifiesUuidReference()

	/**
	 * linkOnderwerpobject() classifies a non-UUID reference as an IDENTIFICATIE.
	 *
	 * @return void
	 */
	public function testLinkOnderwerpobjectClassifiesIdentificatieReference(): void {
		$client = $this->buildClient([new Response(201, [], json_encode(['uuid' => 'obj-1']))]);

		$client->linkOnderwerpobject(
			sourceConfiguration: $this->configuration,
			customerContactId: 'kc-1',
			caseReference: 'ZAAK-2026-001234',
			caseObjectType: 'zaak'
		);

		$body = json_decode((string)$this->history[0]['request']->getBody(), true);
		$this->assertSame('IDENTIFICATIE', $body['onderwerpobjectidentificator']['codeSoortObjectId']);

	}//end testLinkOnderwerpobjectClassifiesIdentificatieReference()

	/**
	 * A configured `onderwerpobject.codeRegister` override is honoured.
	 *
	 * @return void
	 */
	public function testLinkOnderwerpobjectHonoursCodeRegisterOverride(): void {
		$configuration = $this->configuration;
		$configuration['onderwerpobject'] = ['codeRegister' => 'CUSTOM'];

		$client = $this->buildClient([new Response(201, [], json_encode(['uuid' => 'obj-1']))]);
		$client->linkOnderwerpobject(
			sourceConfiguration: $configuration,
			customerContactId: 'kc-1',
			caseReference: 'ZAAK-1',
			caseObjectType: 'zaak'
		);

		$body = json_decode((string)$this->history[0]['request']->getBody(), true);
		$this->assertSame('CUSTOM', $body['onderwerpobjectidentificator']['codeRegister']);

	}//end testLinkOnderwerpobjectHonoursCodeRegisterOverride()

	/**
	 * A missing baseUrl is rejected before any network call.
	 *
	 * @return void
	 */
	public function testMissingBaseUrlThrowsBeforeDispatch(): void {
		$client = $this->buildClient([]);

		$this->expectException(KissProviderException::class);

		$client->listKlantcontacten(sourceConfiguration: ['authentication' => ['encryptedToken' => 'x']], since: null, pageSize: 10);

	}//end testMissingBaseUrlThrowsBeforeDispatch()

	/**
	 * A missing credential is rejected before any network call.
	 *
	 * @return void
	 */
	public function testMissingCredentialThrowsBeforeDispatch(): void {
		$client = $this->buildClient([]);

		$this->expectException(KissProviderException::class);

		$client->listKlantcontacten(sourceConfiguration: ['baseUrl' => 'https://kiss.example.nl'], since: null, pageSize: 10);

	}//end testMissingCredentialThrowsBeforeDispatch()

	/**
	 * A non-2xx KISS response is a descriptive KissProviderException, never a crash.
	 *
	 * @return void
	 */
	public function testNonSuccessStatusIsMappedNotCrash(): void {
		$client = $this->buildClient([new Response(503, [], 'upstream down')]);

		$this->expectException(KissProviderException::class);

		$client->listKlantcontacten(sourceConfiguration: $this->configuration, since: null, pageSize: 10);

	}//end testNonSuccessStatusIsMappedNotCrash()

	/**
	 * A created-resource response with no usable id raises a descriptive exception.
	 *
	 * @return void
	 */
	public function testCreateKlantcontactWithoutUsableIdThrows(): void {
		$client = $this->buildClient([new Response(201, [], json_encode(['status' => 'ok']))]);

		$this->expectException(KissProviderException::class);

		$client->createKlantcontact(sourceConfiguration: $this->configuration, payload: ['onderwerp' => 'x']);

	}//end testCreateKlantcontactWithoutUsableIdThrows()

	/**
	 * getProviderId() exposes the stable identifier used by KissSyncService::resolveProvider().
	 *
	 * @return void
	 */
	public function testProviderIdentity(): void {
		$client = $this->buildClient([]);

		$this->assertSame('rest', $client->getProviderId());

	}//end testProviderIdentity()
}//end class
