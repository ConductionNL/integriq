<?php

/**
 * Composite transactional fan-out rule.
 *
 * Generic gateway mechanic added by the vng-klantinteracties-adapter change:
 * from a single request body, create a parent object plus a configured set of
 * related child objects as one logical operation, rolling back every write
 * already performed when any later write fails.
 *
 * @category Rule
 * @package  OCA\OpenConnector\Rule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Rule;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;

/**
 * Composite fan-out rule handler.
 *
 * Dialect-agnostic: any Endpoint can attach a rule of type `composite_fanout`
 * with a `configuration.compositeFanout` block describing a parent write and
 * an ordered set of child writes. The VNG Klantinteracties `maak-klantcontact`
 * endpoint is the first consumer (parent = klantcontact/ticket, children =
 * betrokkene/digitaalAdres/onderwerpobject).
 *
 * @spec openspec/specs/rule-pipeline/spec.md
 */
class CompositeFanoutRule {
	/**
	 * Construct the rule handler.
	 *
	 * @param ORObjectService $orObjectService OpenRegister object service used for the parent + child writes.
	 * @param LoggerInterface $logger Logger used to record rollback attempts.
	 */
	public function __construct(
		private readonly ORObjectService $orObjectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply the composite fan-out rule.
	 *
	 * Reads `configuration.compositeFanout` off the rule:
	 * ```
	 * {
	 *   "parent": {"bodyKey": "klantcontact", "register": "pipelinq", "schema": "ticket"},
	 *   "children": [
	 *     {"bodyKey": "involvedParty", "register": "pipelinq", "schema": "contact", "parentField": "klantcontact", "required": false},
	 *     {"bodyKey": "digitaalAdres", "register": "pipelinq", "schema": "digitaalAdres", "parentField": "klantcontact", "required": false}
	 *   ]
	 * }
	 * ```
	 * The parent is written first, then every present child, each stamped
	 * with `parentField` pointing at the created parent's uuid. Any exception
	 * during a child write rolls back (deletes) every object created so far —
	 * parent included — and re-throws a single Exception so the caller's
	 * standard rule-pipeline error handling (HTTP 500, one error body)
	 * applies unchanged.
	 *
	 * @param ObjectEntity $rule The composite-fanout rule configuration object.
	 * @param array $data The current rule data envelope (body/headers/parameters).
	 *
	 * @return array The updated $data with the created parent merged into the body.
	 *
	 * @throws Exception When the parent config is missing or any write fails after rollback.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	public function apply(ObjectEntity $rule, array $data): array {
		$configuration = $rule->getObject()['configuration']['compositeFanout'] ?? [];
		$parentConfig = $configuration['parent'] ?? null;

		if ($parentConfig === null
			|| isset($parentConfig['register']) === false
			|| isset($parentConfig['schema']) === false
		) {
			throw new Exception('Composite fan-out rule is missing a parent configuration (register/schema).');
		}

		$body = $data['body'] ?? [];
		$parentBodyKey = $parentConfig['bodyKey'] ?? null;
		$parentBody = $body;
		if ($parentBodyKey !== null) {
			$parentBody = $body[$parentBodyKey] ?? [];
		}

		/*
		 * @var array<int, array{register: string, schema: string, uuid: string}> $created
		 */

		$created = [];

		try {
			$parentObject = $this->orObjectService->saveObject(
				$parentBody,
				$parentConfig['register'],
				$parentConfig['schema']
			);
			$created[] = [
				'register' => $parentConfig['register'],
				'schema' => $parentConfig['schema'],
				'uuid' => $parentObject->getUuid(),
			];

			/*
			 * @var array<string, string> $childUuidsByBodyKey Uuids of already-written children, keyed by their bodyKey.
			 */

			$childUuidsByBodyKey = [];

			foreach (($configuration['children'] ?? []) as $childConfig) {
				$bodyKey = $childConfig['bodyKey'] ?? null;
				if ($bodyKey === null || isset($body[$bodyKey]) === false) {
					if (($childConfig['required'] ?? false) === true) {
						throw new Exception(sprintf('Composite fan-out is missing required child "%s".', (string)$bodyKey));
					}

					continue;
				}

				$childBody = $body[$bodyKey];
				if (isset($childConfig['parentField']) === true) {
					// "parentRef" selects what uuid to stamp into parentField:
					// the top-level parent (default), or an already-written
					// sibling child referenced by its own bodyKey — e.g. a
					// VNG digitaalAdres nested under its betrokkene.
					$parentRef = $childConfig['parentRef'] ?? 'parent';
					$refUuid = $childUuidsByBodyKey[$parentRef] ?? null;
					if ($parentRef === 'parent') {
						$refUuid = $parentObject->getUuid();
					}

					if ($refUuid === null) {
						throw new Exception(sprintf('Composite fan-out child "%s" references unresolved parentRef "%s".', $bodyKey, $parentRef));
					}

					$childBody[$childConfig['parentField']] = $refUuid;
				}

				$childObject = $this->orObjectService->saveObject(
					$childBody,
					$childConfig['register'] ?? $parentConfig['register'],
					$childConfig['schema']
				);
				$created[] = [
					'register' => $childConfig['register'] ?? $parentConfig['register'],
					'schema' => $childConfig['schema'],
					'uuid' => $childObject->getUuid(),
				];
				$childUuidsByBodyKey[$bodyKey] = $childObject->getUuid();
			}//end foreach
		} catch (Exception $exception) {
			$this->rollback(created: $created);
			throw new Exception('Composite fan-out failed, all writes rolled back: ' . $exception->getMessage(), 0, $exception);
		}//end try

		$data['body'] = $parentObject->jsonSerialize();

		return $data;
	}//end apply()

	/**
	 * Delete every object created so far, most-recently-created first.
	 *
	 * Best-effort: a failed delete is logged (not re-thrown) so the original
	 * failure that triggered the rollback is what surfaces to the caller.
	 *
	 * @param array<int,array{register:string,schema:string,uuid:string}> $created Objects to remove.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function rollback(array $created): void {
		foreach (array_reverse($created) as $entry) {
			try {
				$this->orObjectService->deleteObject($entry['uuid'], $entry['register'], $entry['schema']);
			} catch (Exception $exception) {
				$reference = $entry['register'] . '/' . $entry['schema'] . '/' . $entry['uuid'];
				$this->logger->error(
					'Composite fan-out rollback failed to delete ' . $reference . ': ' . $exception->getMessage()
				);
			}
		}
	}//end rollback()
}//end class
