<?php
/**
 * Test stub for OpenRegister's IFlowNode (peer app not in vendor).
 *
 * Mirrors `openregister/lib/Service/Flow/IFlowNode.php` so openconnector's
 * SynchronizationNode can be loaded and unit-tested without OpenRegister.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

interface IFlowNode
{
    public function getId(): string;

    public function getDisplayName(): string;

    public function getDescription(): string;

    public function getIcon(): string;

    public function isAvailableForScope(int $scope): bool;

    public function validateConfig(array $config): void;

    public function execute(array $items, array $config, array $context): array;
}
