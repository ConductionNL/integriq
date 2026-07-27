<?php

/**
 * The synchronisation step, contributed to OpenRegister's flow engine.
 *
 * This is what makes openconnector a CONSUMER of the fleet's one flow engine
 * (ADR-065) rather than the owner of a seventh one. Openconnector keeps the one
 * thing only it can do — run a synchronisation — and contributes it as a node
 * type; OpenRegister's engine walks the graph, handles branching, joins, waits,
 * run persistence and the trace. The bespoke JobTask / lifecycle-listener /
 * followUps chaining becomes expressible as a declarative flow instead.
 *
 * The synchronisation itself is unchanged: this leaf is a thin adapter over the
 * proven `SynchronizationService::synchronize()`. Only the surface around it
 * moves — from a bespoke scheduler/listener to OpenRegister's `IFlowNode`. Per
 * ADR-002 (amended for the chaining concern by openconnector-flow-migration),
 * the connector transform/rule logic stays app-local, now inside leaves like
 * this one; only the ordering/chaining moves to the flow graph.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Flow
 * @package  OCA\OpenConnector\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Flow\Nodes;

use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Server;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Runs a synchronisation as one step of an OpenRegister flow.
 *
 * @template-implements IFlowNode
 */
class SynchronizationNode implements IFlowNode
{
    /**
     * Constructor.
     *
     * `SynchronizationService` is resolved lazily in {@see execute()} via
     * `Server::get()` rather than constructor-injected: it is a large service
     * with many dependencies, and the palette build calls only the light
     * metadata methods on every node — forcing its construction just to render
     * a palette entry would be wasteful.
     *
     * @param IL10N         $l10n Translations.
     * @param IURLGenerator $urls For the palette icon.
     */
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urls
    ) {

    }//end __construct()

    /**
     * The step type.
     *
     * @return string The id.
     *
     * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
     */
    public function getId(): string
    {
        return 'openconnector.synchronization';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     *
     * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Run synchronisation');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     *
     * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Run an OpenConnector synchronisation and put the produced records on the flow.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     *
     * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('openconnector', 'app-dark.svg');

    }//end getIcon()

    /**
     * Running a synchronisation is an administrative action.
     *
     * @param int $scope The scope constant.
     *
     * @return boolean Whether it is available.
     *
     * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
     */
    public function isAvailableForScope(int $scope): bool
    {
        return $scope === IManager::SCOPE_ADMIN;

    }//end isAvailableForScope()

    /**
     * Reject a synchronisation step that names no synchronisation.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When no synchronizationId is given.
     *
     * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
     */
    public function validateConfig(array $config): void
    {
        if (trim((string) ($config['synchronizationId'] ?? '')) === '') {
            throw new UnexpectedValueException($this->l10n->t('A synchronisation step needs a synchronizationId.'));
        }

    }//end validateConfig()

    /**
     * Resolve the referenced synchronisation and run it.
     *
     * A source-style node: it does not depend on upstream items and returns the
     * records the synchronisation produced as the flow item list. Exceptions
     * from resolution or `synchronize()` are NOT caught — they propagate so the
     * engine can apply the step's `onError` policy.
     *
     * @param array $items   The input items (ignored; this is a source node).
     * @param array $config  The step configuration ({synchronizationId, force?}).
     * @param array $context Run-level metadata — NOT the data channel.
     *
     * @return array The produced records as flow items.
     *
     * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
     */
    public function execute(array $items, array $config, array $context): array
    {
        $synchronizationId = trim((string) ($config['synchronizationId'] ?? ''));
        if ($synchronizationId === '') {
            return $items;
        }

        $force = filter_var(($config['force'] ?? false), FILTER_VALIDATE_BOOLEAN);

        $service         = Server::get(SynchronizationService::class);
        $synchronization = $service->getSynchronization(id: $synchronizationId);
        $result          = $service->synchronize(
            synchronization: $synchronization,
            isTest: false,
            force: $force
        );

        return $this->toItems(result: $result);

    }//end execute()

    /**
     * Normalise `synchronize()`'s variable return into a flow item list.
     *
     * `synchronize()` may return a list of produced records or a single summary
     * / log array. A list is spread one-item-per-record; anything else is
     * wrapped as a single summary item. `null` yields an empty list (the branch
     * ends).
     *
     * @param array|null $result The synchronise result.
     *
     * @return array The well-formed item list.
     */
    private function toItems(array | null $result): array
    {
        if ($result === null || $result === []) {
            return [];
        }

        if (array_is_list($result) === true) {
            $items = [];
            foreach ($result as $index => $record) {
                $items[] = [
                    'json'       => $this->toJson(record: $record),
                    'binary'     => [],
                    'pairedItem' => ['item' => $index],
                ];
            }

            return $items;
        }

        return [
            [
                'json'       => $result,
                'binary'     => [],
                'pairedItem' => null,
            ],
        ];

    }//end toItems()

    /**
     * Coerce one produced record into the item `json` shape.
     *
     * @param mixed $record An ObjectEntity, array, or scalar.
     *
     * @return array The record as an associative array.
     */
    private function toJson(mixed $record): array
    {
        if ($record instanceof ObjectEntity) {
            return $record->jsonSerialize();
        }

        if (is_array($record) === true) {
            return $record;
        }

        return ['value' => $record];

    }//end toJson()
}//end class
