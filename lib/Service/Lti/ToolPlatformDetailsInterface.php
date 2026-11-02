<?php

/**
 * Contract of the LtiPlatformDetailsService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Lti
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

namespace OCA\Integriq\Service\Lti;

/**
 * Public operations of {@see LtiPlatformDetailsService} that other components call.
 */
interface ToolPlatformDetailsInterface {
	/**
	 * The six values for a tool, or null when the tool does not exist.
	 *
	 * @param string $toolUuid The `lti_tool` registration uuid.
	 *
	 * @return array{issuer: string, clientId: string, deploymentIds: list<string>, authorizationUrl: string, tokenUrl: string, keySetUrl: string}|null
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
	 */
	public function forTool(string $toolUuid): ?array;
}
