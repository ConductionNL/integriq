<?php

/**
 * Contract of the DeadLetterProjection.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\AgentTools
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\AgentTools;

/**
 * Public operations of {@see DeadLetterProjection} that other components call.
 */
interface DeadLetterProjectionInterface {
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
	public function project(string $store, string $id, array $data): array;

	/**
	 * Cut an error string to the fixed length.
	 *
	 * @param string $value The error.
	 *
	 * @return string The error, at most ERROR_LENGTH characters and an ellipsis.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-109--the-dead-letter-read-must-be-payload-free-and-no-tool-may-return-or-accept-payload-content
	 */
	public function truncate(string $value): string;
}
