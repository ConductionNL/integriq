<?php

/**
 * Integriq API Design Finding Store.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Integriq\Service\ApiDesign
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ApiDesign;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Keeps the findings of a product version and the custom rule sets as OpenRegister objects.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class ApiDesignFindingStore {

	/**
	 * The register the objects live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema of a finding.
	 *
	 * @var string
	 */
	public const FINDING_SCHEMA = 'api_design_finding';

	/**
	 * The schema of a custom rule set.
	 *
	 * @var string
	 */
	public const RULESET_SCHEMA = 'api_design_ruleset';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService Reads and writes the objects.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * The enabled rule sets stored as objects.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function storedRuleSets(): array {
		$sets = [];
		foreach ($this->objectsOf(schema: self::RULESET_SCHEMA, filters: []) as $entity) {
			$data = $entity->getObject();
			if (($data['enabled'] ?? true) !== false && is_array($data['rules'] ?? null) === true) {
				$sets[] = $data;
			}
		}

		return $sets;

	}//end storedRuleSets()

	/**
	 * Replace the open findings of a product version with a new check's findings.
	 *
	 * A waived finding whose rule and path come back is kept, and the new one
	 * is not stored again. A waived finding whose rule and path are gone is
	 * removed, as are all earlier open findings.
	 *
	 * @param string                     $productId The product uuid.
	 * @param string                     $version   The product version.
	 * @param list<array<string, mixed>> $findings  The new check's findings.
	 * @param string                     $checkedAt When the check ran (ISO 8601).
	 *
	 * @return list<array<string, mixed>> The findings of the version now: kept waivers first, then the open ones.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function replace(string $productId, string $version, array $findings, string $checkedAt): array {
		$fresh = [];
		foreach ($findings as $finding) {
			$fresh[self::matchKey(finding: $finding)] = $finding;
		}

		$result = [];
		foreach ($this->findingsOf(productId: $productId, version: $version) as $entity) {
			$data = $entity->getObject();
			$key = self::matchKey(finding: $data);
			if (($data['status'] ?? 'open') === 'waived' && isset($fresh[$key]) === true) {
				unset($fresh[$key]);
				$result[] = $data + ['id' => (string)$entity->getUuid()];
				continue;
			}

			$this->objectService->deleteObject(uuid: (string)$entity->getUuid(), register: self::REGISTER, schema: self::FINDING_SCHEMA);
		}

		foreach ($fresh as $finding) {
			$object = [
				'product' => $productId,
				'productVersion' => $version,
				'ruleset' => (string)($finding['ruleset'] ?? ''),
				'ruleId' => (string)($finding['ruleId'] ?? ''),
				'severity' => (string)($finding['severity'] ?? 'error'),
				'path' => (string)($finding['path'] ?? ''),
				'message' => (string)($finding['message'] ?? ''),
				'url' => (string)($finding['url'] ?? ''),
				'status' => 'open',
				'checkedAt' => $checkedAt,
			];
			$saved = $this->objectService->saveObject(object: $object, register: self::REGISTER, schema: self::FINDING_SCHEMA);
			$result[] = $object + ['id' => (string)$saved->getUuid()];
		}

		return $result;

	}//end replace()

	/**
	 * Waive an open finding with a reason.
	 *
	 * @param string $findingId The finding uuid.
	 * @param string $reason    Why the finding is accepted; required.
	 * @param string $userId    The administrator who waives it.
	 * @param string $waivedAt  When (ISO 8601).
	 *
	 * @return array<string, mixed> The waived finding.
	 *
	 * @throws InvalidArgumentException When the reason is empty, or the finding is unknown or not open.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
	 */
	public function waive(string $findingId, string $reason, string $userId, string $waivedAt): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new InvalidArgumentException(message: 'A waiver needs a reason.');
		}

		$entity = $this->objectService->find(id: $findingId, register: self::REGISTER, schema: self::FINDING_SCHEMA);
		if ($entity instanceof ObjectEntity === false) {
			throw new InvalidArgumentException(message: 'There is no such finding.');
		}

		$data = $entity->getObject();
		if (($data['status'] ?? 'open') !== 'open') {
			throw new InvalidArgumentException(message: 'Only an open finding can be waived.');
		}

		$data['status'] = 'waived';
		$data['waivedBy'] = $userId;
		$data['waiveReason'] = $reason;
		$data['waivedAt'] = $waivedAt;
		$this->objectService->saveObject(object: $data, register: self::REGISTER, schema: self::FINDING_SCHEMA, uuid: $findingId);

		return $data + ['id' => $findingId];

	}//end waive()

	/**
	 * The stored findings of one product version.
	 *
	 * @param string $productId The product uuid.
	 * @param string $version   The product version.
	 *
	 * @return list<ObjectEntity>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	private function findingsOf(string $productId, string $version): array {
		$matching = [];
		foreach ($this->objectsOf(schema: self::FINDING_SCHEMA, filters: ['product' => $productId]) as $entity) {
			$data = $entity->getObject();
			if (($data['product'] ?? '') === $productId && (string)($data['productVersion'] ?? '') === $version) {
				$matching[] = $entity;
			}
		}

		return $matching;

	}//end findingsOf()

	/**
	 * The objects of a schema.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Extra filters.
	 *
	 * @return list<ObjectEntity>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	private function objectsOf(string $schema, array $filters): array {
		$result = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema] + $filters, 'limit' => 1000]
		);

		$entities = [];
		foreach (($result['results'] ?? $result) as $item) {
			if ($item instanceof ObjectEntity === true) {
				$entities[] = $item;
			}
		}

		return $entities;

	}//end objectsOf()

	/**
	 * What makes two findings the same: rule set, rule and path.
	 *
	 * @param array<string, mixed> $finding The finding.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	private static function matchKey(array $finding): string {
		return (string)($finding['ruleset'] ?? '') . "\n" . (string)($finding['ruleId'] ?? '') . "\n" . (string)($finding['path'] ?? '');

	}//end matchKey()
}//end class
