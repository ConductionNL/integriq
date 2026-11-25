<?php

/**
 * Integriq DSO Account Rights: OpenRegister's PermissionAsk signature.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-23-dso-intake-through-an-integriq-connection/design.md#contract-gaps
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Service\Dso\DsoAccountRights;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The rights check against a handler that takes one PermissionAsk.
 *
 * Against openregister main the old named-argument call raised
 * "Unknown named parameter $schema", so every DSO push answered 503.
 */
class DsoAccountRightsPermissionAskTest extends TestCase {

	/**
	 * The asks the handler received.
	 *
	 * @var list<PermissionAsk>
	 */
	private array $asks = [];

	/**
	 * Build the rights check over a PermissionAsk handler.
	 *
	 * @param list<string> $granted The actions the handler grants.
	 *
	 * @return DsoAccountRights
	 */
	private function rights(array $granted): DsoAccountRights {
		$schema       = new Schema();
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$asks    = &$this->asks;
		$handler = new class($asks, $granted) {
			/**
			 * @param list<PermissionAsk> $asks    The shared record.
			 * @param list<string>        $granted The granted actions.
			 */
			public function __construct(private array &$asks, private readonly array $granted) {
			}

			/**
			 * OpenRegister main's signature.
			 *
			 * @param PermissionAsk $ask   The question.
			 * @param bool          $_rbac Whether RBAC applies.
			 *
			 * @return bool
			 */
			public function hasPermission(PermissionAsk $ask, bool $_rbac = true): bool {
				$this->asks[] = $ask;
				return in_array($ask->action, $this->granted, true);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($handler);

		return new DsoAccountRights($schemaMapper, $container, new NullLogger());
	}//end rights()

	/**
	 * Each action is asked as one PermissionAsk carrying the account's uid.
	 *
	 * @return void
	 */
	public function testAsksOnePermissionAskPerActionForTheAccount(): void {
		$missing = $this->rights(['read', 'create'])->missing('svc-dso', ['read', 'create', 'update']);

		$this->assertSame(['update'], $missing);
		$this->assertCount(3, $this->asks);
		$this->assertSame(['read', 'create', 'update'], array_map(static fn (PermissionAsk $ask): string => $ask->action, $this->asks));
		foreach ($this->asks as $ask) {
			$this->assertSame('svc-dso', $ask->userId);
			$this->assertNull($ask->objectOwner);
		}
	}//end testAsksOnePermissionAskPerActionForTheAccount()

	/**
	 * All actions held answers an empty list, not null.
	 *
	 * @return void
	 */
	public function testAllHeldIsAnEmptyList(): void {
		$this->assertSame([], $this->rights(['read', 'create'])->missing('svc-dso', ['read', 'create']));
	}//end testAllHeldIsAnEmptyList()
}//end class
