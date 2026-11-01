<?php

/**
 * Golden characterization of EudiCredentialOfferService::exchangeToken().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-token-endpoint-issues-a-single-use-pre-authorized-code-grant-req-eudi-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateInterval;
use DateTime;
use OCA\Integriq\Exception\EudiIssuanceException;
use OCA\Integriq\Service\EudiCredentialOfferService;
use OCA\Integriq\Service\EudiIssuerKeyService;
use OCA\Integriq\Service\EudiStatusListService;
use OCA\Integriq\Service\OrganisationBridgeService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exchanges pre-authorized codes against offers in every state and records
 * the offer lookups, writes, refusals and the issued token against a golden
 * file. The token endpoint is called by a wallet, not by a signed-in user, so
 * no user identity enters the exchange: the code (and its tx_code) alone
 * decide. Regenerate the file on the code it must describe with
 * EUDI_TOKEN_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\EudiCredentialOfferService
 */
class EudiTokenExchangeGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/eudi/token-exchange.json';

	private const GRANT = 'urn:ietf:params:oauth:grant-type:pre-authorized_code';

	/**
	 * Everything the exchange looked up and wrote, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * The stored offers, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * An offer for a code, with overrides.
	 *
	 * @param string               $code     The pre-authorized code.
	 * @param array<string, mixed> $override Offer fields to set.
	 *
	 * @return array<string, mixed>
	 */
	private function offer(string $code, array $override = []): array {
		return array_merge(
			[
				'status' => 'created',
				'preAuthorizedCodeHash' => hash('sha256', $code),
				'format' => 'dc+sd-jwt',
				'credentialConfigurationId' => 'pid-sd-jwt',
				'consumerId' => 'consumer-1',
			],
			$override
		);

	}//end offer()

	/**
	 * The cases: name => [stored offer or null, request body].
	 *
	 * @return array<string, array{0: array<string, mixed>|null, 1: array<string, mixed>}>
	 */
	private function cases(): array {
		$future = (new DateTime())->add(new DateInterval('PT10M'))->format('c');
		$past = (new DateTime())->sub(new DateInterval('PT1M'))->format('c');
		$pin = ['txCodeRequired' => true, 'txCodeHash' => hash('sha256', '1234')];
		$grant = static fn (array $extra = []): array => (['grant_type' => self::GRANT, 'pre-authorized_code' => 'code-1'] + $extra);

		return [
			'no-grant' => [$this->offer('code-1'), ['pre-authorized_code' => 'code-1']],
			'other-grant' => [$this->offer('code-1'), ['grant_type' => 'authorization_code', 'pre-authorized_code' => 'code-1']],
			'no-code' => [$this->offer('code-1'), ['grant_type' => self::GRANT]],
			'empty-code' => [$this->offer('code-1'), ['grant_type' => self::GRANT, 'pre-authorized_code' => '']],
			'code-not-string' => [$this->offer('code-1'), ['grant_type' => self::GRANT, 'pre-authorized_code' => ['code-1']]],
			'unknown-code' => [$this->offer('code-1'), ['grant_type' => self::GRANT, 'pre-authorized_code' => 'code-2']],
			'claimed' => [$this->offer('code-1', ['status' => 'claimed']), $grant()],
			'revoked' => [$this->offer('code-1', ['status' => 'revoked']), $grant()],
			'no-status' => [$this->offer('code-1', ['status' => null]), $grant()],
			'expired' => [$this->offer('code-1', ['expiresAt' => $past]), $grant()],
			'not-expired' => [$this->offer('code-1', ['expiresAt' => $future]), $grant()],
			'empty-expiry' => [$this->offer('code-1', ['expiresAt' => '']), $grant()],
			'pin-locked' => [$this->offer('code-1', $pin + ['txCodeFailedAttempts' => 5]), $grant(['tx_code' => '1234'])],
			'pin-last-attempt-wrong' => [$this->offer('code-1', $pin + ['txCodeFailedAttempts' => 4]), $grant(['tx_code' => '9999'])],
			'pin-missing' => [$this->offer('code-1', $pin), $grant()],
			'pin-empty' => [$this->offer('code-1', $pin), $grant(['tx_code' => ''])],
			'pin-not-string' => [$this->offer('code-1', $pin), $grant(['tx_code' => 1234])],
			'pin-empty-against-empty-hash' => [$this->offer('code-1', ['txCodeRequired' => true, 'txCodeHash' => hash('sha256', '')]), $grant(['tx_code' => ''])],
			'pin-right' => [$this->offer('code-1', $pin + ['txCodeFailedAttempts' => 4]), $grant(['tx_code' => '1234'])],
			'pin-flag-not-true' => [$this->offer('code-1', ['txCodeRequired' => 'true', 'txCodeHash' => 'x']), $grant()],
			'no-configuration-id' => [$this->offer('code-1', ['credentialConfigurationId' => null]), $grant()],
			'plain' => [$this->offer('code-1'), $grant()],
		];

	}//end cases()

	/**
	 * Build the service over a recording object store.
	 *
	 * @return EudiCredentialOfferService
	 */
	private function service(): EudiCredentialOfferService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function ($config = []): array {
				$filters = ($config['filters'] ?? []);
				$this->trace[] = ['findAll' => $filters];
				$results = [];
				foreach ($this->rows as $uuid => $data) {
					if (($data['preAuthorizedCodeHash'] ?? null) === ($filters['preAuthorizedCodeHash'] ?? '')) {
						$entity = new ObjectEntity();
						$entity->setUuid($uuid);
						$entity->setObject($data);
						$results[] = $entity;
					}
				}

				return ['results' => $results];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null): ObjectEntity {
				foreach (['accessTokenHash', 'cNonce'] as $key) {
					if (isset($object[$key]) === true) {
						$this->rawSaves[$key][] = $object[$key];
					}
				}

				$this->trace[] = ['save' => $schema, 'register' => $register, 'uuidShape' => $this->uuidShape(uuid: $uuid), 'object' => $this->stable(object: $object)];
				$entity = new ObjectEntity();
				$entity->setUuid((string)$uuid);
				$entity->setObject($object);
				return $entity;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new EudiCredentialOfferService(
			$objects,
			$this->createMock(EudiIssuerKeyService::class),
			$this->createMock(EudiStatusListService::class),
			$this->createMock(WebhookSignatureService::class),
			$this->createMock(OrganisationBridgeService::class),
			$this->createMock(IClientService::class),
			$cacheFactory,
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * Whether a uuid is the stored offer's, a fresh v4 uuid, or absent.
	 *
	 * @param mixed $uuid The uuid a save names.
	 *
	 * @return string
	 */
	private function uuidShape(mixed $uuid): string {
		if ($uuid === 'offer-1') {
			return 'offer-1';
		}

		if (is_string($uuid) === true && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) === 1) {
			return 'v4';
		}

		return 'other:' . json_encode($uuid);

	}//end uuidShape()

	/**
	 * A saved object with its random and clock values replaced by their shape.
	 *
	 * @param array<string, mixed> $object The saved object.
	 *
	 * @return array<string, mixed>
	 */
	private function stable(array $object): array {
		foreach (['accessTokenHash', 'cNonce'] as $key) {
			if (isset($object[$key]) === true) {
				$object[$key] = 'len:' . strlen((string)$object[$key]);
			}
		}

		foreach (['cNonceExpiresAt', 'expiresAt'] as $key) {
			if (isset($object[$key]) === true && $object[$key] !== '') {
				$object[$key] = 'now+' . (int)round(((new DateTime((string)$object[$key]))->getTimestamp() - time()) / 60) . 'min';
			}
		}

		return $object;

	}//end stable()

	/**
	 * Every exchange matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryExchangeMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => [$offer, $body]) {
			$this->rows = ['offer-1' => $offer];
			$this->trace = [];
			$this->rawSaves = [];
			$service = $this->service();
			$sessions = [];
			try {
				$token = $service->exchangeToken($body);
				$outcome = [
					'keys' => array_keys($token),
					'token_type' => $token['token_type'],
					'expires_in' => $token['expires_in'],
					'c_nonce_expires_in' => $token['c_nonce_expires_in'],
					'access_token' => 'len:' . strlen($token['access_token']),
					'c_nonce' => 'len:' . strlen($token['c_nonce']),
				];
				$sessions = $this->sessionLinks(token: $token);
			} catch (EudiIssuanceException $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage(), 'status' => $exception->getHttpStatus(), 'error' => $exception->getErrorCode()];
			}

			$actual[$name] = ['trace' => $this->trace, 'outcome' => $outcome, 'session' => $sessions];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('EUDI_TOKEN_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame((string)file_get_contents(self::GOLDEN), $encoded);

	}//end testEveryExchangeMatchesTheGoldenFile()

	/**
	 * Whether the session written carries the hash of the returned token and its nonce.
	 *
	 * @param array<string, mixed> $token The token response.
	 *
	 * @return array<string, bool>
	 */
	private function sessionLinks(array $token): array {
		return [
			'tokenHashMatches' => in_array(hash('sha256', (string)$token['access_token']), $this->savedValues(key: 'accessTokenHash'), true),
			'nonceMatches' => in_array($token['c_nonce'], $this->savedValues(key: 'cNonce'), true),
		];

	}//end sessionLinks()

	/**
	 * The raw values saved under one key, across the run.
	 *
	 * @param string $key The object key.
	 *
	 * @return array<int, mixed>
	 */
	private function savedValues(string $key): array {
		return $this->rawSaves[$key] ?? [];

	}//end savedValues()

	/**
	 * Raw saved values by key, for the session links.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $rawSaves = [];
}//end class
