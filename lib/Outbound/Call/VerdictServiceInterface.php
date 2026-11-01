<?php

/**
 * Contract of the VerdictService.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound\Call
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

namespace OCA\Integriq\Outbound\Call;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see VerdictService} that other components call.
 */
interface VerdictServiceInterface {
	/**
	 * Record one verdict.
	 *
	 * @param string $objectRef The object this verdict is about.
	 * @param string $state One of {@see VerdictService::STATES}.
	 * @param string $source Which checker said it.
	 * @param string $reason Why, in the checker's own words.
	 * @param array<string,mixed> $payload What the checker sent.
	 *
	 * @return ObjectEntity The stored verdict.
	 *
	 * @throws InvalidArgumentException When the object or the state is missing or unknown. A
	 *                                  verdict nobody can attach to an object is not a verdict.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on VerdictService::record()
	 */
	public function record(
		string $objectRef,
		string $state,
		string $source,
		string $reason = '',
		array $payload = [],
	): ObjectEntity;

	/**
	 * The verdicts recorded against one object, newest first.
	 *
	 * @param string $objectRef The object.
	 *
	 * @return array<int,array<string,mixed>> The verdicts.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on VerdictService::forObject()
	 */
	public function forObject(string $objectRef): array;
}
