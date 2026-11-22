<?php

/**
 * Integriq ProductChangeNoticeListener.
 *
 * Product CRUD goes through OpenRegister's object API, so a product change
 * reaches integriq as an object event: an updated `api_product` that was
 * deprecated, given or moved a sunset date, or retired, and a created one that
 * is a new version of a product with subscribers. Each becomes change notices
 * through ProductChangeNoticeService.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
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
 * @spec openspec/changes/access-developer-portal-and-subscriptions/design.md#d5-the-change-notice-is-an-object-with-a-declared-notification
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\ProductChangeNoticeService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a saved API product into change notices for its subscribers.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
 */
class ProductChangeNoticeListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ProductChangeNoticeService $notices        Writes the notices.
	 * @param RegisterMapper             $registerMapper Resolves the saved object's register slug.
	 * @param SchemaMapper               $schemaMapper   Resolves the saved object's schema slug.
	 * @param LoggerInterface            $logger         The logger.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
	 */
	public function __construct(
		private readonly ProductChangeNoticeService $notices,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the notices a product save calls for.
	 *
	 * The product is already saved; a notice that cannot be written is logged
	 * and does not undo or fail the administrator's change.
	 *
	 * @param Event $event An object created or updated event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent) {
			$new = $event->getNewObject();
			if ($this->isProduct(object: $new) === false) {
				return;
			}

			$kind = $this->notices->kindFor(old: ($event->getOldObject()?->getObject() ?? []), new: $new->getObject());
			if ($kind !== null) {
				$this->safely(work: fn (): int => $this->notices->notifySubscribers(product: $new, kind: $kind));
			}

			return;
		}

		if ($event instanceof ObjectCreatedEvent && $this->isProduct(object: $event->getObject()) === true) {
			$created = $event->getObject();
			$this->safely(work: fn (): int => $this->notices->notifyNewVersion(newProduct: $created));
		}
	}//end handle()

	/**
	 * Run a notice run, logging rather than throwing a failure.
	 *
	 * @param callable(): int $work The run.
	 *
	 * @return void
	 */
	private function safely(callable $work): void {
		try {
			$work();
		} catch (Throwable $e) {
			$this->logger->warning('integriq: product change notices failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}//end safely()

	/**
	 * Whether the object is an integriq `api_product`.
	 *
	 * @param ObjectEntity $object The saved object.
	 *
	 * @return bool True for an API product.
	 */
	private function isProduct(ObjectEntity $object): bool {
		try {
			$registerSlug = $this->registerMapper->find($object->getRegister())->getSlug();
			$schemaSlug = $this->schemaMapper->find($object->getSchema())->getSlug();
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] product change notice: could not resolve register/schema: ' . $failure->getMessage());
			return false;
		}

		return $registerSlug === 'integriq' && $schemaSlug === 'api_product';
	}//end isProduct()
}//end class
