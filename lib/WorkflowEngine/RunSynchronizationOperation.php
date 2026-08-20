<?php

/**
 * OpenConnector "Run synchronization" WorkflowEngine Operation.
 *
 * Thin `OCP\WorkflowEngine\ISpecificOperation` adapter that lets an NC admin
 * wire "file event + checks -> run this OpenConnector synchronization" from
 * Settings > Flow. `onEvent()` decodes the matched flow's settings and
 * delegates to the existing `SynchronizationService::getSynchronization()` +
 * `synchronize()` — no synchronization logic is reimplemented here.
 *
 * @category WorkflowEngine
 * @package  OCA\OpenConnector\WorkflowEngine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-run-synchronization-operations-onevent-must-dispatch-to-synchronizationservice-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\WorkflowEngine;

use OCA\OpenConnector\AppInfo\Application;
use OCA\OpenConnector\Service\SynchronizationService;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * "Run synchronization" Flow operation — file-entity-scoped, admin-only.
 *
 * @SuppressWarnings(PHPMD.LongVariable) $synchronizationService mirrors the
 * property name SynchronizationService's own other callers already use.
 *
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-run-synchronization-operations-onevent-must-dispatch-to-synchronizationservice-req-002
 */
class RunSynchronizationOperation implements ISpecificOperation {
	/**
	 * Constructor.
	 *
	 * @param SynchronizationService $synchronizationService Service that resolves and runs synchronizations.
	 * @param IL10N $l10n App-scoped translator for the operation's admin-UI copy.
	 * @param IURLGenerator $urlGenerator Nextcloud URL generator, used for the operation icon.
	 * @param LoggerInterface $logger Logger instance.
	 */
	public function __construct(
		private readonly SynchronizationService $synchronizationService,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-all-three-operations-must-be-admin-scoped-and-file-entity-scoped-only-req-005
	 */
	public function getEntityId(): string {
		// Referenced only as a compile-time ::class string — NC core's
		// `workflowengine` app ships this class but it is never
		// instantiated/type-hinted here, so no autoload is triggered even
		// when the bundled `workflowengine` app is disabled (design.md
		// Decision 1 / discovery.md finding 6).
		return \OCA\WorkflowEngine\Entity\File::class;
	}//end getEntityId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Run synchronization');
	}//end getDisplayName()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
	 */
	public function getDescription(): string {
		return $this->l10n->t('Runs a configured OpenConnector synchronization when this rule matches.');
	}//end getDescription()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}//end getIcon()

	/**
	 * {@inheritDoc}
	 *
	 * @param int $scope The requested scope (`IManager::SCOPE_*`).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-all-three-operations-must-be-admin-scoped-and-file-entity-scoped-only-req-005
	 */
	public function isAvailableForScope(int $scope): bool {
		return $scope === IManager::SCOPE_ADMIN;
	}//end isAvailableForScope()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name The rule name (unused — required by the interface contract).
	 * @param array $checks The rule's configured checks (unused — required by the interface contract).
	 * @param string $operation The raw JSON operation settings, expected shape `{"synchronizationId": "<uuid>"}`.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the settings are malformed, `synchronizationId` is missing/empty,
	 *                                  or the referenced synchronization does not resolve.
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-validateoperation-must-reject-unresolvable-or-malformed-target-settings-before-a-rule-can-be-saved-req-006
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $name/$checks are mandated by the IOperation contract.
	 */
	public function validateOperation(string $name, array $checks, string $operation): void {
		$synchronizationId = $this->decodeSynchronizationId(operation: $operation);
		if ($synchronizationId === null) {
			throw new UnexpectedValueException($this->l10n->t('A synchronization must be selected.'));
		}

		try {
			$this->synchronizationService->getSynchronization(id: $synchronizationId);
		} catch (Throwable $e) {
			throw new UnexpectedValueException($this->l10n->t('The configured synchronization could not be found.'));
		}

	}//end validateOperation()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $eventName The NC event name that fired.
	 * @param Event $event The fired NC event (unused — the flow's own settings carry everything
	 *                     needed).
	 * @param IRuleMatcher $ruleMatcher Resolves the matched flows for this operation + entity + event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-run-synchronization-operations-onevent-must-dispatch-to-synchronizationservice-req-002
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $event is mandated by the IOperation contract.
	 */
	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		foreach ($ruleMatcher->getFlows(false) as $flow) {
			try {
				$synchronizationId = $this->decodeSynchronizationId(operation: (string)($flow['operation'] ?? ''));
				if ($synchronizationId === null) {
					continue;
				}

				$synchronization = $this->synchronizationService->getSynchronization(id: $synchronizationId);
				$this->synchronizationService->synchronize(synchronization: $synchronization);
			} catch (Throwable $e) {
				// Deliberately swallowed — one bad flow must not stop the
				// remaining matched flows or propagate into the NC event
				// dispatcher that invoked onEvent() (design.md Risk 1).
				$this->logger->warning(
					'WorkflowEngine "Run synchronization" operation dispatch failed: ' . $e->getMessage(),
					[
						'exception' => $e,
						'eventName' => $eventName,
					]
				);
			}//end try
		}//end foreach

	}//end onEvent()

	/**
	 * Decode a flow's raw `operation` JSON and extract its `synchronizationId`.
	 *
	 * @param string $operation The raw JSON operation settings string.
	 *
	 * @return string|null The synchronization id, or null when the JSON is malformed
	 *                     or `synchronizationId` is missing/empty/non-string.
	 */
	private function decodeSynchronizationId(string $operation): ?string {
		try {
			$settings = json_decode($operation, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($settings) === false) {
			return null;
		}

		$synchronizationId = ($settings['synchronizationId'] ?? null);
		if (is_string($synchronizationId) === false || $synchronizationId === '') {
			return null;
		}

		return $synchronizationId;
	}//end decodeSynchronizationId()
}//end class
