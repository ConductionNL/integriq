<?php

/**
 * Tests for ApiProductPublishGuardListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\ApiProductPublishGuardListener;
use OCA\Integriq\Service\ApiDesign\ApiProductDesignCheck;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The guard on a product status change to active.
 */
#[CoversClass(ApiProductPublishGuardListener::class)]
class ApiProductPublishGuardListenerTest extends TestCase {

	/**
	 * Check mock.
	 *
	 * @var ApiProductDesignCheck&MockObject
	 */
	private ApiProductDesignCheck&MockObject $check;

	/**
	 * The listener under test.
	 *
	 * @var ApiProductPublishGuardListener
	 */
	private ApiProductPublishGuardListener $listener;

	/**
	 * Set up with a schema mapper that knows schema 7 as api_product.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->check = $this->createMock(ApiProductDesignCheck::class);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(static fn ($id): object => new class(((int)$id === 7) ? 'api_product' : 'source') {
			/**
			 * Constructor.
			 *
			 * @param string $slug The slug.
			 */
			public function __construct(private readonly string $slug) {
			}

			/**
			 * The slug.
			 *
			 * @return string
			 */
			public function getSlug(): string {
				return $this->slug;
			}
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, ($count === 1) ? $one : $many));
		$this->listener = new ApiProductPublishGuardListener(check: $this->check, schemaMapper: $schemas, l10n: $l10n, logger: new NullLogger());

	}//end setUp()

	/**
	 * An object of the given schema and status.
	 *
	 * @param string $status The status.
	 * @param int    $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private static function object(string $status, int $schema = 7): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('p1');
		$entity->setSchema((string)$schema);
		$entity->setObject(['name' => 'Besluiten API', 'status' => $status]);

		return $entity;

	}//end object()

	/**
	 * An open error refuses the change with the finding listed.
	 *
	 * @return void
	 */
	public function testAnOpenErrorRefusesMakingTheProductActive(): void {
		$finding = ['ruleId' => '/core/no-trailing-slash', 'severity' => 'error', 'status' => 'open', 'path' => '/paths/~1besluiten~1'];
		$this->check->expects(self::once())->method('blockingFindings')->willReturn([$finding]);
		$event = new ObjectUpdatingEvent(self::object('active'), self::object('deprecated'));

		$this->listener->handle($event);

		self::assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		self::assertSame('api_design_open_errors', $errors['code']);
		self::assertSame(409, $errors['status']);
		self::assertSame([$finding], $errors['findings']);
		self::assertStringContainsString('1 design rule error is open', $errors['message']);

	}//end testAnOpenErrorRefusesMakingTheProductActive()

	/**
	 * With nothing blocking (all errors waived, or only warnings) the change goes through.
	 *
	 * @return void
	 */
	public function testNothingBlockingLetsTheChangeThrough(): void {
		$this->check->expects(self::once())->method('blockingFindings')->willReturn([]);
		$event = new ObjectUpdatingEvent(self::object('active'), self::object('deprecated'));

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());

	}//end testNothingBlockingLetsTheChangeThrough()

	/**
	 * A check that cannot run refuses too: an unchecked product is not published.
	 *
	 * @return void
	 */
	public function testACheckThatCannotRunRefuses(): void {
		$this->check->method('blockingFindings')->willThrowException(new RuntimeException('no document'));
		$event = new ObjectUpdatingEvent(self::object('active'), null);

		$this->listener->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('api_design_check_unavailable', $event->getErrors()['code']);

	}//end testACheckThatCannotRunRefuses()

	/**
	 * A product that stays active, one that is not made active, another schema and another event are not checked.
	 *
	 * @return void
	 */
	public function testOnlyAChangeToActiveOfAProductIsChecked(): void {
		$this->check->expects(self::never())->method('blockingFindings');

		$events = [
			new ObjectUpdatingEvent(self::object('active'), self::object('active')),
			new ObjectUpdatingEvent(self::object('deprecated'), self::object('active')),
			new ObjectUpdatingEvent(self::object('active', 3), self::object('deprecated', 3)),
		];
		foreach ($events as $event) {
			$this->listener->handle($event);
			self::assertFalse($event->isPropagationStopped());
		}

		$this->listener->handle(new Event());

	}//end testOnlyAChangeToActiveOfAProductIsChecked()
}//end class
