<?php

/**
 * Unit tests for ProductSubscriptionService, the one subscribe path shared by
 * the administrator's route and the developer portal.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/design.md#d3-request-access-reuses-the-approval-flow
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Integriq\Service\ApprovalServiceInterface;
use OCA\Integriq\Service\Helper\RegisterSave;
use OCA\Integriq\Service\ProductSubscriptionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the shared subscribe path.
 */
#[CoversClass(ProductSubscriptionService::class)]
#[UsesClass(RegisterSave::class)]
class ProductSubscriptionServiceTest extends TestCase {

	/**
	 * An object entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * A product with an open and a gated tier.
	 *
	 * @return ObjectEntity The product.
	 */
	private static function product(): ObjectEntity {
		return self::entity(
			uuid: 'p-1',
			data: ['tiers' => ['basic' => [], 'premium' => ['requiresApproval' => true, 'approverGroup' => 'gateway-approvers']]]
		);
	}//end product()

	/**
	 * A translator that formats.
	 *
	 * @return IL10N The translator.
	 */
	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));

		return $l;
	}//end l10n()

	/**
	 * An open tier activates at once and opens no approval.
	 *
	 * @return void
	 */
	public function testAnOpenTierActivatesAtOnce(): void {
		$saved = [];
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return self::entity(uuid: 'sub-1', data: $object);
			}
		);
		$approvals = $this->createMock(ApprovalServiceInterface::class);
		$approvals->expects($this->never())->method('suspendForSubscription');

		$service = new ProductSubscriptionService(approvalService: $approvals, orObjectService: $objects, l: $this->l10n());
		$result = $service->subscribe(product: self::product(), consumerId: 'c-1', tierName: 'basic', requesterUserId: 'developer1');

		$this->assertFalse($result['pending']);
		$this->assertSame('active', $result['subscription']->getObject()['status']);
		$this->assertSame(['product' => 'p-1', 'consumer' => 'c-1', 'tier' => 'basic', 'status' => 'pending_approval', 'requesterUserId' => 'developer1'], array_diff_key($saved[0], ['createdAt' => true]));
		$this->assertCount(2, $saved);
	}//end testAnOpenTierActivatesAtOnce()

	/**
	 * A gated tier opens the tier's approval and waits, carrying the approval's id.
	 *
	 * @return void
	 */
	public function testAGatedTierOpensTheApprovalForItsGroup(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('saveObject')->willReturnCallback(static fn (array $object): ObjectEntity => self::entity(uuid: 'sub-1', data: $object));
		$approvals = $this->createMock(ApprovalServiceInterface::class);
		$approvals->expects($this->once())->method('suspendForSubscription')
			->with('sub-1', 'gateway-approvers', 'error', ApprovalServiceInterface::DEFAULT_TTL_SECONDS)
			->willReturn(self::entity(uuid: 'approval-1', data: []));

		$service = new ProductSubscriptionService(approvalService: $approvals, orObjectService: $objects, l: $this->l10n());
		$result = $service->subscribe(product: self::product(), consumerId: 'c-1', tierName: 'premium', requesterUserId: 'developer1');

		$this->assertTrue($result['pending']);
		$this->assertSame('pending_approval', $result['subscription']->getObject()['status']);
		$this->assertSame('approval-1', $result['subscription']->getObject()['approvalRequestId']);
	}//end testAGatedTierOpensTheApprovalForItsGroup()

	/**
	 * An unknown tier is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnknownTierIsRefusedAndNothingIsWritten(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->expects($this->never())->method('saveObject');

		$service = new ProductSubscriptionService(approvalService: $this->createMock(ApprovalServiceInterface::class), orObjectService: $objects, l: $this->l10n());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown tier: gold');
		$service->subscribe(product: self::product(), consumerId: 'c-1', tierName: 'gold', requesterUserId: null);
	}//end testAnUnknownTierIsRefusedAndNothingIsWritten()
}//end class
