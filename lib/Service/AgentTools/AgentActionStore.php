<?php

/**
 * Keeps an agent's staged batches and the record of every agent invocation.
 *
 * @category Service
 * @package  OCA\Integriq\Service\AgentTools
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-108--every-invocation-including-refusals-must-be-attributed-to-the-agent-principal-in-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\AgentTools;

use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * One `agent_action` object per invocation (design D8). A staged batch is the
 * record whose outcome is `staged`; its uuid is the proposal reference the
 * agent passes back in phase 2, and later records name it in `proposal`.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-108--every-invocation-including-refusals-must-be-attributed-to-the-agent-principal-in-the-audit-trail
 */
class AgentActionStore {

	/**
	 * The schema slug, in integriq's register.
	 */
	public const SCHEMA = 'agent_action';

	/**
	 * Build the store.
	 *
	 * @param OrObjectService $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Save a new record.
	 *
	 * @param array<string,mixed> $record The agent_action object.
	 *
	 * @return string The new record's uuid.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-108--every-invocation-including-refusals-must-be-attributed-to-the-agent-principal-in-the-audit-trail
	 */
	public function record(array $record): string {
		$saved = $this->objectService->saveObject(
			object: $record,
			register: 'integriq',
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
		return $saved->getUuid();
	}//end record()

	/**
	 * Read a record.
	 *
	 * @param string $uuid The record uuid.
	 *
	 * @return array<string,mixed>|null The object, or null when there is none.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function find(string $uuid): ?array {
		try {
			$entity = $this->objectService->find(
				id: $uuid,
				register: 'integriq',
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $entity->getObject();
	}//end find()

	/**
	 * Overwrite a record.
	 *
	 * @param string              $uuid   The record uuid.
	 * @param array<string,mixed> $record The whole object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function update(string $uuid, array $record): void {
		$this->objectService->saveObject(
			object: $record,
			register: 'integriq',
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);
	}//end update()
}//end class
