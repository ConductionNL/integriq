<?php

/**
 * Unit tests for ConsumerPrivilegeGate (iq-a-f5).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use OCA\Integriq\Service\Consumer\ConsumerPrivilegeGate;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A privileged consumer operation needs the scope or an admin-acting consumer,
 * and always the consumer's own source allow-list.
 */
final class ConsumerPrivilegeGateTest extends TestCase {

	/**
	 * Build the gate.
	 *
	 * @param bool $sourceAllowed What the source allow-list answers.
	 *
	 * @return ConsumerPrivilegeGate
	 */
	private function gate(bool $sourceAllowed = true): ConsumerPrivilegeGate {
		$scope = $this->createMock(ConsumerScopeService::class);
		$scope->method('isAllowed')->willReturn($sourceAllowed);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'admin');

		return new ConsumerPrivilegeGate($scope, $groups, $this->createMock(LoggerInterface::class));
	}//end gate()

	/**
	 * A consumer with the given data.
	 *
	 * @param array $data The consumer object.
	 *
	 * @return ObjectEntity
	 */
	private function consumer(array $data): ObjectEntity {
		$consumer = new ObjectEntity();
		$consumer->setUuid('consumer-1');
		$consumer->setObject($data);

		return $consumer;
	}//end consumer()

	/**
	 * The decisions.
	 *
	 * @return void
	 */
	public function testDecisions(): void {
		$request = $this->createMock(IRequest::class);

		$plain = $this->consumer(['userId' => 'service-account']);
		$this->assertFalse($this->gate()->mayUse($plain, ConsumerPrivilegeGate::SCOPE_SCIM, $request), 'a plain consumer key is not enough');

		$scoped = $this->consumer(['userId' => 'service-account', 'authorizationConfiguration' => ['scopes' => ['scim']]]);
		$this->assertTrue($this->gate()->mayUse($scoped, ConsumerPrivilegeGate::SCOPE_SCIM, $request));
		$this->assertFalse($this->gate()->mayUse($scoped, ConsumerPrivilegeGate::SCOPE_EUDI_ISSUE, $request), 'a scope grants only itself');

		$admin = $this->consumer(['userId' => 'admin']);
		$this->assertTrue($this->gate()->mayUse($admin, ConsumerPrivilegeGate::SCOPE_EUDI_ISSUE, $request));

		$this->assertFalse($this->gate(sourceAllowed: false)->mayUse($admin, ConsumerPrivilegeGate::SCOPE_SCIM, $request), 'the source allow-list always applies');
	}//end testDecisions()
}//end class
