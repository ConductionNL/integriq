<?php

/**
 * Integriq API Product Publish Guard Listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\ApiDesign\ApiProductDesignCheck;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses making an API product active while a check finds an open error.
 *
 * Runs when a product's status changes to `active`. The check replaces the
 * product version's open findings, so the refusal lists what the product page
 * then shows. A product whose description cannot be written is refused too:
 * an unchecked product is not published.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
 */
class ApiProductPublishGuardListener implements IEventListener {

	/**
	 * The schema of an API product.
	 *
	 * @var string
	 */
	public const SCHEMA = 'api_product';

	/**
	 * Constructor.
	 *
	 * @param ApiProductDesignCheck $check        Runs the check.
	 * @param SchemaMapper          $schemaMapper Tells an API product apart.
	 * @param IL10N                 $l10n         The refusal message.
	 * @param LoggerInterface       $logger       Logs a check that could not run.
	 */
	public function __construct(
		private readonly ApiProductDesignCheck $check,
		private readonly SchemaMapper $schemaMapper,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Guard a product status change to active.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$product = $event->getNewObject();
		if ($this->becomesActive(product: $product, old: $event->getOldObject()) === false) {
			return;
		}

		try {
			$blocking = $this->check->blockingFindings(product: $product);
		} catch (Throwable $e) {
			$this->logger->warning('[integriq] API product not made active: the design check could not run', ['errorClass' => get_class($e)]);
			$this->refuse(
				event: $event,
				code: 'api_design_check_unavailable',
				message: $this->l10n->t('The API product cannot be made active: its description could not be checked.'),
				findings: []
			);
			return;
		}

		if ($blocking === []) {
			return;
		}

		$this->refuse(
			event: $event,
			code: 'api_design_open_errors',
			message: $this->l10n->n(
				'The API product cannot be made active: %n design rule error is open. Fix it or waive it with a reason.',
				'The API product cannot be made active: %n design rule errors are open. Fix them or waive them with a reason.',
				count($blocking)
			),
			findings: $blocking
		);

	}//end handle()

	/**
	 * Whether this update moves an API product to active.
	 *
	 * @param ObjectEntity      $product The product as it will be saved.
	 * @param ObjectEntity|null $old     The product as it was.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
	 */
	private function becomesActive(ObjectEntity $product, ?ObjectEntity $old): bool {
		if (($product->getObject()['status'] ?? null) !== 'active') {
			return false;
		}

		if ($old !== null && ($old->getObject()['status'] ?? null) === 'active') {
			return false;
		}

		try {
			return $this->schemaMapper->find($product->getSchema())->getSlug() === self::SCHEMA;
		} catch (Throwable) {
			return false;
		}

	}//end becomesActive()

	/**
	 * Refuse the save with the findings that hold it back.
	 *
	 * @param ObjectUpdatingEvent        $event    The event.
	 * @param string                     $code     A stable refusal code.
	 * @param string                     $message  The translated message.
	 * @param list<array<string, mixed>> $findings The blocking findings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
	 */
	private function refuse(ObjectUpdatingEvent $event, string $code, string $message, array $findings): void {
		$event->setErrors(['code' => $code, 'message' => $message, 'status' => 409, 'findings' => $findings]);
		$event->stopPropagation();

	}//end refuse()
}//end class
