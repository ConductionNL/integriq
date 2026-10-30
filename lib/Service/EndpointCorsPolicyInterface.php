<?php

/**
 * Contract of the EndpointCorsPolicy.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see EndpointCorsPolicy} that other components call.
 */
interface EndpointCorsPolicyInterface {
	/**
	 * The headers for an endpoint's declared policy, or null when it declares none.
	 *
	 * @param ObjectEntity|null $endpoint The matched endpoint, if any.
	 *
	 * @return array<string,string>|null
	 *
	 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-an-endpoint-may-declare-its-own-cors-policy-req-ep-014
	 */
	public function headersFor(?ObjectEntity $endpoint): ?array;
}
