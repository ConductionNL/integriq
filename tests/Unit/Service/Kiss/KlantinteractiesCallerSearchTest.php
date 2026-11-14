<?php

/**
 * Unit tests for the caller search on KlantinteractiesClient.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/kcc-cti-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\KissProviderException;
use OCA\Integriq\Service\Kiss\CallerLookup;
use OCA\Integriq\Service\Kiss\CallerSearchInterface;
use OCA\Integriq\Service\Kiss\KlantinteractiesClient;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The REST binding searches parties by number and reads a party's case references.
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
 */
class KlantinteractiesCallerSearchTest extends TestCase {

	private const PARTY_A = '11111111-1111-4111-8111-111111111111';
	private const PARTY_B = '22222222-2222-4222-8222-222222222222';

	/**
	 * @var array<int,array{request:\GuzzleHttp\Psr7\Request}>
	 */
	private array $history = [];

	/**
	 * The source configuration.
	 *
	 * @var array
	 */
	private array $configuration = [
		'baseUrl' => 'https://kiss.example.nl/api/v1',
		'authentication' => ['encryptedToken' => 'ciphertext-blob'],
	];

	/**
	 * A client answering the queued responses, recording its requests.
	 *
	 * @param array<int,Response> $responses The queued responses.
	 *
	 * @return KlantinteractiesClient The client.
	 */
	private function client(array $responses): KlantinteractiesClient {
		$stack = HandlerStack::create(new MockHandler($responses));
		$this->history = [];
		$stack->push(Middleware::history($this->history));

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('raw-token-value');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new KlantinteractiesClient(
			new Client(['handler' => $stack]),
			$crypto,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);

	}//end client()

	/**
	 * A JSON response.
	 *
	 * @param mixed $body The body.
	 *
	 * @return Response The response.
	 */
	private static function json(mixed $body): Response {
		return new Response(200, [], (string) json_encode($body));

	}//end json()

	/**
	 * A digitaal adres provided by a party.
	 *
	 * @param string|null $party  The party uuid.
	 * @param string      $number The address.
	 *
	 * @return array<string, mixed> The address.
	 */
	private static function address(?string $party, string $number='+31612345678'): array {
		$address = ['adres' => $number, 'soortDigitaalAdres' => 'telefoonnummer'];
		if ($party !== null) {
			$address['verstrektDoorPartij'] = ['uuid' => $party];
		}

		return $address;

	}//end address()

	/**
	 * The client is a caller search binding.
	 *
	 * @return void
	 */
	public function testTheRestBindingCanSearch(): void {
		$this->assertInstanceOf(CallerSearchInterface::class, $this->client([]));

	}//end testTheRestBindingCanSearch()

	/**
	 * The number is asked for exactly, then each providing party is read and
	 * carries the addresses it provided.
	 *
	 * @return void
	 */
	public function testAPartyIsReadWithTheAddressesItProvided(): void {
		$client = $this->client(
			[
				self::json(['results' => [self::address(self::PARTY_A), self::address(null)]]),
				self::json(['uuid' => self::PARTY_A, 'soortPartij' => 'persoon']),
			]
		);

		$parties = $client->findPartiesByPhoneNumber(sourceConfiguration: $this->configuration, e164: '+31612345678');

		$this->assertCount(2, $this->history);
		$search = $this->history[0]['request'];
		$this->assertSame('GET', $search->getMethod());
		$this->assertSame('/api/v1/digitaleadressen', $search->getUri()->getPath());
		$this->assertSame('adres=%2B31612345678', $search->getUri()->getQuery());
		$this->assertSame('Token raw-token-value', $search->getHeaderLine('Authorization'));
		$this->assertSame('/api/v1/partijen/'.self::PARTY_A, $this->history[1]['request']->getUri()->getPath());

		$this->assertSame(
			[
				[
					'uuid' => self::PARTY_A,
					'soortPartij' => 'persoon',
					'digitaleAdressen' => [self::address(self::PARTY_A)],
				],
			],
			$parties
		);
		$this->assertSame($parties[0], (new CallerLookup())->match('+31612345678', $parties));

	}//end testAPartyIsReadWithTheAddressesItProvided()

	/**
	 * Two parties on one number are both returned, so the lookup can refuse.
	 *
	 * @return void
	 */
	public function testEveryPartyOnTheNumberIsACandidate(): void {
		$client = $this->client(
			[
				self::json(['results' => [self::address(self::PARTY_A), self::address(self::PARTY_B)]]),
				self::json(['uuid' => self::PARTY_A]),
				self::json(['uuid' => self::PARTY_B]),
			]
		);

		$parties = $client->findPartiesByPhoneNumber(sourceConfiguration: $this->configuration, e164: '+31612345678');

		$this->assertSame([self::PARTY_A, self::PARTY_B], array_column($parties, 'uuid'));
		$this->assertNull((new CallerLookup())->match('+31612345678', $parties), 'A shared number is no match.');

	}//end testEveryPartyOnTheNumberIsACandidate()

	/**
	 * A backend that ignores the filter cannot make another number a match:
	 * the party carries the address it really has.
	 *
	 * @return void
	 */
	public function testAnotherNumberReturnedByTheBackendMatchesNobody(): void {
		$client = $this->client(
			[
				self::json(['results' => [self::address(self::PARTY_A, '+49612345678')]]),
				self::json(['uuid' => self::PARTY_A]),
			]
		);

		$parties = $client->findPartiesByPhoneNumber(sourceConfiguration: $this->configuration, e164: '+31612345678');

		$this->assertNull((new CallerLookup())->match('+31612345678', $parties));

	}//end testAnotherNumberReturnedByTheBackendMatchesNobody()

	/**
	 * A party id that is not a uuid never reaches a request path.
	 *
	 * @return void
	 */
	public function testAPartyIdThatIsNoUuidIsNotRequested(): void {
		$client = $this->client([self::json(['results' => [self::address('../klantcontacten')]])]);

		$this->assertSame([], $client->findPartiesByPhoneNumber(sourceConfiguration: $this->configuration, e164: '+31612345678'));
		$this->assertCount(1, $this->history);

	}//end testAPartyIdThatIsNoUuidIsNotRequested()

	/**
	 * No more than the cap of parties is read for one number.
	 *
	 * @return void
	 */
	public function testThePartiesReadAreCapped(): void {
		$addresses = [];
		$responses = [];
		for ($i = 0; $i < (KlantinteractiesClient::MAX_CALLER_CANDIDATES + 2); $i++) {
			$uuid        = sprintf('%08d-0000-4000-8000-000000000000', $i);
			$addresses[] = self::address($uuid);
			$responses[] = self::json(['uuid' => $uuid]);
		}

		$client  = $this->client(array_merge([self::json(['results' => $addresses])], $responses));
		$parties = $client->findPartiesByPhoneNumber(sourceConfiguration: $this->configuration, e164: '+31612345678');

		$this->assertCount(KlantinteractiesClient::MAX_CALLER_CANDIDATES, $parties);
		$this->assertCount(KlantinteractiesClient::MAX_CALLER_CANDIDATES + 1, $this->history);

	}//end testThePartiesReadAreCapped()

	/**
	 * A failing backend is an exception, which the context service turns into an unknown caller.
	 *
	 * @return void
	 */
	public function testAFailingBackendThrows(): void {
		$client = $this->client([new Response(503)]);

		$this->expectException(KissProviderException::class);
		$client->findPartiesByPhoneNumber(sourceConfiguration: $this->configuration, e164: '+31612345678');

	}//end testAFailingBackendThrows()

	/**
	 * The case references come from the party's klantcontacten, newest first,
	 * once each, by the REQ-003 rule.
	 *
	 * @return void
	 */
	public function testCaseReferencesAreTheZaakSubjectsNewestFirst(): void {
		$zaak = static fn (string $id, string $type='zaak'): array => [
			'onderwerpobjectidentificator' => ['objectId' => $id, 'codeObjecttype' => $type],
		];
		$client = $this->client(
			[
				self::json(
					[
						'results' => [
							['plaatsgevondenOp' => '2026-01-01T09:00:00Z', 'onderwerpobjecten' => [$zaak('case-old')]],
							['plaatsgevondenOp' => '2026-03-01T09:00:00Z', 'onderwerpobjecten' => [$zaak('doc-1', 'document'), $zaak('case-new', 'Zaak')]],
							['plaatsgevondenOp' => '2026-02-01T09:00:00Z', 'onderwerpobjecten' => [$zaak('case-old')]],
							['plaatsgevondenOp' => '2026-02-15T09:00:00Z', 'onderwerpobjecten' => []],
						],
					]
				),
			]
		);

		$cases = $client->findOpenCasesForParty(sourceConfiguration: $this->configuration, party: ['uuid' => self::PARTY_A]);

		$this->assertSame(['case-new', 'case-old'], $cases);
		$request = $this->history[0]['request'];
		$this->assertSame('/api/v1/klantcontacten', $request->getUri()->getPath());
		parse_str($request->getUri()->getQuery(), $query);
		$this->assertSame(
			[
				'hadBetrokkene__wasPartij__uuid' => self::PARTY_A,
				'expand' => 'onderwerpobjecten',
				'sorteer' => '-plaatsgevondenOp',
				'pageSize' => (string) KlantinteractiesClient::CASE_CONTACTS_PAGE_SIZE,
			],
			$query
		);

	}//end testCaseReferencesAreTheZaakSubjectsNewestFirst()

	/**
	 * A party without a uuid has no cases and nothing is requested.
	 *
	 * @return void
	 */
	public function testAPartyWithoutAUuidHasNoCases(): void {
		$client = $this->client([]);

		$this->assertSame([], $client->findOpenCasesForParty(sourceConfiguration: $this->configuration, party: ['uuid' => 'x']));
		$this->assertSame([], $this->history);

	}//end testAPartyWithoutAUuidHasNoCases()

}//end class
