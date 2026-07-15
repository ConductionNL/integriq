<?php
/**
 * OpenConnector "Fire CloudEvent" WorkflowEngine Operation.
 *
 * Thin `OCP\WorkflowEngine\ISpecificOperation` adapter that lets an NC admin
 * wire "file event + checks -> emit this CloudEvent" from Settings > Flow.
 * `onEvent()` decodes the matched flow's settings and delegates to the
 * existing `EventService::emitCloudEvent()` — no CloudEvent persistence,
 * delivery, retry, or dead-letter logic is reimplemented here.
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
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-fire-cloudevent-operations-onevent-must-dispatch-to-eventserviceemitcloudevent-req-004
 */

declare(strict_types=1);

namespace OCA\OpenConnector\WorkflowEngine;

use OCA\OpenConnector\AppInfo\Application;
use OCA\OpenConnector\Service\EventService;
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
 * "Fire CloudEvent" Flow operation — file-entity-scoped, admin-only.
 *
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-fire-cloudevent-operations-onevent-must-dispatch-to-eventserviceemitcloudevent-req-004
 */
class FireCloudEventOperation implements ISpecificOperation
{
    /**
     * Constructor.
     *
     * @param EventService    $eventService Service used to emit CloudEvents.
     * @param IL10N           $l10n         App-scoped translator for the operation's admin-UI copy.
     * @param IURLGenerator   $urlGenerator Nextcloud URL generator, used for the operation icon.
     * @param LoggerInterface $logger       Logger instance.
     */
    public function __construct(
        private readonly EventService $eventService,
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
    public function getEntityId(): string
    {
        // Referenced only as a compile-time ::class string — see
        // RunSynchronizationOperation::getEntityId() docblock for the
        // no-autoload rationale.
        return \OCA\WorkflowEngine\Entity\File::class;

    }//end getEntityId()

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Fire CloudEvent');

    }//end getDisplayName()

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Emits a configured CloudEvent when this rule matches.');

    }//end getDescription()

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
     */
    public function getIcon(): string
    {
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
    public function isAvailableForScope(int $scope): bool
    {
        return $scope === IManager::SCOPE_ADMIN;

    }//end isAvailableForScope()

    /**
     * {@inheritDoc}
     *
     * @param string $name      The rule name (unused — required by the interface contract).
     * @param array  $checks    The rule's configured checks (unused — required by the interface contract).
     * @param string $operation The raw JSON operation settings, expected shape `{"type": "...", "source": "...",
     *                          "subject": null, "data": {...optional...}}`.
     *
     * @return void
     *
     * @throws UnexpectedValueException When the settings are malformed or `type`/`source` is missing/empty.
     *
     * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-validateoperation-must-reject-unresolvable-or-malformed-target-settings-before-a-rule-can-be-saved-req-006
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $name/$checks are mandated by the IOperation contract.
     */
    public function validateOperation(string $name, array $checks, string $operation): void
    {
        $settings = $this->decodeSettings(operation: $operation);
        if ($settings === null) {
            throw new UnexpectedValueException($this->l10n->t('A CloudEvent type and source must be configured.'));
        }

    }//end validateOperation()

    /**
     * {@inheritDoc}
     *
     * @param string       $eventName   The NC event name that fired.
     * @param Event        $event       The fired NC event (unused — the flow's own settings carry everything needed).
     * @param IRuleMatcher $ruleMatcher Resolves the matched flows for this operation + entity + event.
     *
     * @return void
     *
     * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-fire-cloudevent-operations-onevent-must-dispatch-to-eventserviceemitcloudevent-req-004
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $event is mandated by the IOperation contract.
     */
    public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void
    {
        foreach ($ruleMatcher->getFlows(false) as $flow) {
            try {
                $settings = $this->decodeSettings(operation: (string) ($flow['operation'] ?? ''));
                if ($settings === null) {
                    continue;
                }

                $data = ['eventName' => $eventName];
                if (is_array(($settings['data'] ?? null)) === true) {
                    $data = array_merge($data, $settings['data']);
                }

                $this->eventService->emitCloudEvent(
                    type: $settings['type'],
                    source: $settings['source'],
                    subject: ($settings['subject'] ?? null),
                    data: $data
                );
            } catch (Throwable $e) {
                // Deliberately swallowed — one bad flow must not stop the
                // remaining matched flows or propagate into the NC event
                // dispatcher that invoked onEvent() (design.md Risk 1).
                $this->logger->warning(
                    'WorkflowEngine "Fire CloudEvent" operation dispatch failed: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'eventName' => $eventName,
                    ]
                );
            }//end try
        }//end foreach

    }//end onEvent()

    /**
     * Decode a flow's raw `operation` JSON into a settings array, guaranteeing
     * non-empty string `type`/`source` keys when returning non-null.
     *
     * @param string $operation The raw JSON operation settings string.
     *
     * @return array|null The decoded settings (with validated `type`/`source`), or null when
     *                     the JSON is malformed or `type`/`source` is missing/empty/non-string.
     */
    private function decodeSettings(string $operation): ?array
    {
        try {
            $settings = json_decode($operation, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return null;
        }

        if (is_array($settings) === false) {
            return null;
        }

        $type   = ($settings['type'] ?? null);
        $source = ($settings['source'] ?? null);
        if (is_string($type) === false || $type === ''
            || is_string($source) === false || $source === ''
        ) {
            return null;
        }

        $settings['type']   = $type;
        $settings['source'] = $source;

        return $settings;

    }//end decodeSettings()
}//end class
