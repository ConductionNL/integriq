<?php

/**
 * The payload-free view of a dead letter an agent may read.
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-109--the-dead-letter-read-must-be-payload-free-and-no-tool-may-return-or-accept-payload-content
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\AgentTools;

/**
 * Builds each row from named fields only (design Decision 3). Nothing is
 * copied wholesale, so a property added to either schema later never reaches
 * an agent by accident; `payload` and `lastResponse` are never read.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-109--the-dead-letter-read-must-be-payload-free-and-no-tool-may-return-or-accept-payload-content
 */
class DeadLetterProjection {

	/**
	 * The exact key set of every row.
	 */
	public const KEYS = [
		'id', 'store', 'synchronization', 'subscription', 'phase', 'error',
		'attempts', 'retryCount', 'status', 'created', 'replayedAt', 'discardedAt',
	];

	/**
	 * How long an error string may be before it is cut.
	 */
	public const ERROR_LENGTH = 200;

	/**
	 * Project one stored dead letter.
	 *
	 * @param string              $store `sync` or `event`.
	 * @param string              $id    The object uuid.
	 * @param array<string,mixed> $data  The stored object.
	 *
	 * @return array<string,mixed> The row, exactly KEYS.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-109--the-dead-letter-read-must-be-payload-free-and-no-tool-may-return-or-accept-payload-content
	 */
	public function project(string $store, string $id, array $data): array {
		$error = null;
		if ($store === 'sync' && is_string($data['error'] ?? null) === true) {
			$error = $this->truncate(value: $data['error']);
		}

		$attempts = null;
		if (is_array($data['attempts'] ?? null) === true) {
			$attempts = count($data['attempts']);
		}

		return [
			'id'              => $id,
			'store'           => $store,
			'synchronization' => $this->stringOrNull(value: ($data['synchronization'] ?? null)),
			'subscription'    => $this->stringOrNull(value: ($data['subscription'] ?? null)),
			'phase'           => $this->stringOrNull(value: ($data['phase'] ?? null)),
			'error'           => $error,
			'attempts'        => $attempts,
			'retryCount'      => (int)($data['retryCount'] ?? 0),
			'status'          => $this->stringOrNull(value: ($data['status'] ?? null)),
			'created'         => $this->stringOrNull(value: ($data['created'] ?? null)),
			'replayedAt'      => $this->stringOrNull(value: ($data['replayedAt'] ?? null)),
			'discardedAt'     => $this->stringOrNull(value: ($data['discardedAt'] ?? null)),
		];
	}//end project()

	/**
	 * Cut an error string to the fixed length.
	 *
	 * @param string $value The error.
	 *
	 * @return string The error, at most ERROR_LENGTH characters and an ellipsis.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-109--the-dead-letter-read-must-be-payload-free-and-no-tool-may-return-or-accept-payload-content
	 */
	public function truncate(string $value): string {
		if (mb_strlen($value) <= self::ERROR_LENGTH) {
			return $value;
		}

		return mb_substr($value, 0, self::ERROR_LENGTH) . '…';
	}//end truncate()

	/**
	 * A scalar as a string, anything else as null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The string.
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true || is_int($value) === true) {
			return (string)$value;
		}

		return null;
	}//end stringOrNull()
}//end class
