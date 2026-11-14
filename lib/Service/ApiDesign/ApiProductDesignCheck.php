<?php

/**
 * Integriq API Product Design Check.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Checks an API product's published description against every enabled rule set and keeps the findings.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class ApiProductDesignCheck {

	/**
	 * The service that writes a product's OpenAPI description (gateway-openapi-import-and-publish).
	 *
	 * Resolved by name, so this check does not depend on the order the two changes land in.
	 *
	 * @var string
	 */
	public const PUBLISH_SERVICE = 'OCA\\Integriq\\Service\\OpenApi\\OpenApiPublishService';

	/**
	 * The order findings are listed in.
	 *
	 * @var array<string, int>
	 */
	private const SEVERITY_ORDER = ['error' => 0, 'warning' => 1, 'info' => 2];

	/**
	 * Constructor.
	 *
	 * @param ApiDesignRuleServiceInterface $rules         Runs the rules.
	 * @param ApiDesignFindingStore         $store         Keeps the findings and the custom rule sets.
	 * @param OrObjectService               $objectService Reads the product.
	 * @param ContainerInterface            $container     Resolves the publish service lazily.
	 * @param IURLGenerator                 $urlGenerator  The server URL the description is written for.
	 * @param ITimeFactory                  $time          When a check ran.
	 */
	public function __construct(
		private readonly ApiDesignRuleServiceInterface $rules,
		private readonly ApiDesignFindingStore $store,
		private readonly OrObjectService $objectService,
		private readonly ContainerInterface $container,
		private readonly IURLGenerator $urlGenerator,
		private readonly ITimeFactory $time,
	) {

	}//end __construct()

	/**
	 * Check the product with this id.
	 *
	 * @param string $productId The api_product uuid.
	 *
	 * @return array{findings: list<array<string, mixed>>, manual: list<array<string, mixed>>}
	 *
	 * @throws RuntimeException When the product is unknown or its description cannot be written.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function checkProduct(string $productId): array {
		$product = $this->objectService->find(id: $productId, register: ApiDesignFindingStore::REGISTER, schema: 'api_product');
		if ($product instanceof ObjectEntity === false) {
			throw new RuntimeException(message: 'There is no such API product.');
		}

		return $this->check(product: $product);

	}//end checkProduct()

	/**
	 * Check a product and replace its open findings.
	 *
	 * @param ObjectEntity $product The api_product.
	 *
	 * @return array{findings: list<array<string, mixed>>, manual: list<array<string, mixed>>} Findings by severity, errors first.
	 *
	 * @throws RuntimeException When the product's description cannot be written.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function check(ObjectEntity $product): array {
		$result = $this->rules->check(
			document: $this->documentOf(product: $product),
			ruleSets: array_merge($this->rules->builtInRuleSets(), $this->store->storedRuleSets())
		);

		$findings = $this->store->replace(
			productId: (string)$product->getUuid(),
			version: (string)($product->getObject()['version'] ?? ''),
			findings: $result['findings'],
			checkedAt: $this->time->getDateTime()->format(DATE_ATOM)
		);

		usort(
			$findings,
			static fn (array $one, array $other): int => self::rank(finding: $one) <=> self::rank(finding: $other)
		);

		return ['findings' => $findings, 'manual' => $result['manual']];

	}//end check()

	/**
	 * Where a finding's severity sorts: errors first, unknown last.
	 *
	 * @param array<string, mixed> $finding The finding.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	private static function rank(array $finding): int {
		return (self::SEVERITY_ORDER[(string)($finding['severity'] ?? '')] ?? 3);

	}//end rank()

	/**
	 * The findings that refuse making this product active: a fresh check's open errors.
	 *
	 * @param ObjectEntity $product The api_product.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RuntimeException When the product's description cannot be written.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
	 */
	public function blockingFindings(ObjectEntity $product): array {
		return $this->rules->blockingFindings(findings: $this->check(product: $product)['findings']);

	}//end blockingFindings()

	/**
	 * The product's OpenAPI description, as the gateway publishes it.
	 *
	 * @param ObjectEntity $product The api_product.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the publish service is absent or fails.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	private function documentOf(ObjectEntity $product): array {
		try {
			$publisher = $this->container->get(self::PUBLISH_SERVICE);
		} catch (Throwable $e) {
			throw new RuntimeException(message: 'The OpenAPI description of an API product cannot be written on this installation.', previous: $e);
		}

		if (is_object($publisher) === false || method_exists($publisher, 'productDocument') === false) {
			throw new RuntimeException(message: 'The OpenAPI description of an API product cannot be written on this installation.');
		}

		$document = $publisher->productDocument($product, $this->urlGenerator->getAbsoluteURL('/apps/integriq/api'));
		if (is_array($document) === false) {
			throw new RuntimeException(message: 'The OpenAPI description of the API product is empty.');
		}

		return $document;

	}//end documentOf()
}//end class
