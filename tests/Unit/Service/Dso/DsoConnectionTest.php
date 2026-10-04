<?php

/**
 * Tests for DsoConnection: a STAM push gets the identity of the dso-stam consumer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Exception\DsoSignatureException;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each outcome of DsoConnection::authenticate().
 */
class DsoConnectionTest extends TestCase {
	use DsoConnectionWorld;

	private const BODY = '{"verzoekId":"dso-123"}';

	private DsoConnection $connection;

	/**
	 * Set up an empty world.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resetWorld();
		$this->connection = $this->buildWorldConnection(objectService: $this->buildWorldObjectService());
	}//end setUp()

	/**
	 * Assert authenticate() fails with a reason and an error code.
	 *
	 * @param string $reason    The expected reason.
	 * @param string $errorCode The expected error code.
	 *
	 * @return void
	 */
	private function assertUnavailable(string $reason, string $errorCode): void {
		try {
			$this->connection->authenticate(rawBody: self::BODY, signatureHeader: $this->signBody(self::BODY));
		} catch (DsoConnectionUnavailableException $exception) {
			$this->assertSame($reason, $exception->getReason());
			$this->assertSame($errorCode, $exception->getErrorCode());
			return;
		}

		$this->fail('authenticate() did not refuse with ' . $reason);
	}//end assertUnavailable()

	/**
	 * A valid signature resolves to the consumer's account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/consumer-management/spec.md#scenario-a-valid-signature-resolves-to-the-consumers-account
	 */
	public function testValidSignatureResolvesToTheConsumersAccount(): void {
		$this->addAccount(uid: 'dso-intake');
		$this->addDsoConsumer(userId: 'dso-intake');

		$identity = $this->connection->authenticate(rawBody: self::BODY, signatureHeader: $this->signBody(self::BODY));

		$this->assertSame('dso-intake', $identity->account->getUID());
		$this->assertSame('consumer-dso', $identity->consumerUuid);
		$this->assertSame([], $this->worldWrites, 'authenticate() writes nothing');

	}//end testValidSignatureResolvesToTheConsumersAccount()

	/**
	 * The consumer is read as an engine read, never under the caller's RBAC.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function testTheConsumerIsAnEngineRead(): void {
		$this->addAccount(uid: 'dso-intake');
		$this->addDsoConsumer(userId: 'dso-intake');

		$this->connection->authenticate(rawBody: self::BODY, signatureHeader: $this->signBody(self::BODY));

		$consumerReads = array_filter($this->worldReads, static fn (array $read): bool => $read['schema'] === 'consumer');
		$this->assertNotSame([], $consumerReads);
		foreach ($consumerReads as $read) {
			$this->assertFalse($read['rbac']);
		}

	}//end testTheConsumerIsAnEngineRead()

	/**
	 * A bad signature throws DsoSignatureException, before any account check.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function testBadSignatureThrowsSignatureException(): void {
		$this->addDsoConsumer(userId: '');

		$this->expectException(DsoSignatureException::class);
		$this->connection->authenticate(rawBody: self::BODY, signatureHeader: 'sha256=' . hash_hmac('sha256', self::BODY, 'forged'));

	}//end testBadSignatureThrowsSignatureException()

	/**
	 * A missing header is a bad signature.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function testMissingHeaderThrowsSignatureException(): void {
		$this->addAccount(uid: 'dso-intake');
		$this->addDsoConsumer(userId: 'dso-intake');

		$this->expectException(DsoSignatureException::class);
		$this->connection->authenticate(rawBody: self::BODY, signatureHeader: null);

	}//end testMissingHeaderThrowsSignatureException()

	/**
	 * No dso-stam consumer: no_connection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function testNoConsumerIsNoConnection(): void {
		$this->addAccount(uid: 'dso-intake');
		$this->worldConsumers['consumer-apikey'] = ['name' => 'other', 'authorizationType' => 'apiKey', 'userId' => 'dso-intake'];

		$this->assertUnavailable(reason: 'no_connection', errorCode: 'dso_connection_not_configured');

	}//end testNoConsumerIsNoConnection()

	/**
	 * Two dso-stam consumers: the intake does not guess.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function testTwoConsumersAreAmbiguous(): void {
		$this->addAccount(uid: 'dso-intake');
		$this->addDsoConsumer(userId: 'dso-intake', uuid: 'consumer-a');
		$this->addDsoConsumer(userId: 'dso-intake', uuid: 'consumer-b');

		$this->assertUnavailable(reason: 'ambiguous_connection', errorCode: 'dso_connection_not_configured');

	}//end testTwoConsumersAreAmbiguous()

	/**
	 * An empty userId: no_account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-a-missing-account-fails-loud
	 */
	public function testEmptyUserIdIsNoAccount(): void {
		$this->addDsoConsumer(userId: '');

		$this->assertUnavailable(reason: 'no_account', errorCode: 'dso_account_unavailable');

	}//end testEmptyUserIdIsNoAccount()

	/**
	 * A uid that does not exist: account_unknown.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-a-missing-account-fails-loud
	 */
	public function testUnknownUserIsAccountUnknown(): void {
		$this->addDsoConsumer(userId: 'ghost');

		$this->assertUnavailable(reason: 'account_unknown', errorCode: 'dso_account_unavailable');

	}//end testUnknownUserIsAccountUnknown()

	/**
	 * A disabled account: account_disabled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-a-missing-account-fails-loud
	 */
	public function testDisabledUserIsAccountDisabled(): void {
		$this->addAccount(uid: 'dso-intake', enabled: false);
		$this->addDsoConsumer(userId: 'dso-intake');

		$this->assertUnavailable(reason: 'account_disabled', errorCode: 'dso_account_unavailable');

	}//end testDisabledUserIsAccountDisabled()

	/**
	 * Create without update: account_lacks_rights, before any write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-an-account-without-rights-writes-nothing-half
	 */
	public function testCreateWithoutUpdateLacksRights(): void {
		$this->addAccount(uid: 'dso-intake', grants: ['create', 'read']);
		$this->addDsoConsumer(userId: 'dso-intake');

		$this->assertUnavailable(reason: 'account_lacks_rights', errorCode: 'dso_account_lacks_rights');
		$this->assertSame(['update'], $this->connection->missingRights(userId: 'dso-intake'));

	}//end testCreateWithoutUpdateLacksRights()

	/**
	 * No PermissionHandler: the intake fails closed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function testAbsentPermissionHandlerFailsClosed(): void {
		$this->addAccount(uid: 'dso-intake');
		$this->addDsoConsumer(userId: 'dso-intake');
		$this->worldHasPermissionHandler = false;

		$this->assertUnavailable(reason: 'rights_unverifiable', errorCode: 'dso_account_lacks_rights');

	}//end testAbsentPermissionHandlerFailsClosed()

	/**
	 * RunAs() makes the account active inside and restores the caller after,
	 * also on a throw.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function testRunAsActivatesTheAccountAndRestoresTheCaller(): void {
		$this->addAccount(uid: 'dso-intake');
		$account = $this->worldUser('dso-intake');

		$seen = $this->connection->runAs($account, fn (): ?string => $this->worldSession->getUser()?->getUID());
		$this->assertSame('dso-intake', $seen);
		$this->assertNull($this->worldSession->getUser());

		try {
			$this->connection->runAs($account, static function (): void {
				throw new RuntimeException('boom');
			});
		} catch (RuntimeException) {
		}

		$this->assertNull($this->worldSession->getUser());

	}//end testRunAsActivatesTheAccountAndRestoresTheCaller()
}//end class
