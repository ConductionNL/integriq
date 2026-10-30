<?php

/**
 * Contract of the SourceTestService.
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
 * Public operations of {@see SourceTestService} that other components call.
 */
interface SourceTestServiceInterface {
	/**
	 * The call failed before a response came back.
	 *
	 * @var string
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * The call returned without response data.
	 *
	 * @var string
	 */
	public const OUTCOME_NO_RESPONSE = 'no-response';

	/**
	 * Fire a test call with `persistLog: false`.
	 *
	 * An interactive test and a scheduled probe return the live response but
	 * must not write a CallLog: persisting one runs a full OpenRegister save and
	 * mutates the source's rate-limit state. Any engine failure is caught, so a
	 * caller always gets a readable outcome instead of an exception.
	 *
	 * @param ObjectEntity $source The source object.
	 * @param string $endpoint The endpoint path, empty for the source root.
	 * @param string $method The HTTP method.
	 * @param array<string,mixed> $config Guzzle options: headers, query, body.
	 *
	 * @return array{outcome:string,result:?array,statusCode:?int,statusMessage:string,error:string}
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
	 */
	public function run(ObjectEntity $source, string $endpoint = '', string $method = 'GET', array $config = []): array;
}
