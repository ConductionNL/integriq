<?php

/**
 * Integriq OptOutRegistry.
 *
 * One opt-out per recipient address per instance, checked by every sender in
 * the product. A recipient who asked not to be written to should not have to
 * ask each app separately, and the AVG duty is the sender's, not the app's.
 *
 * Some things cannot be stopped. A besluit, an ontvangstbevestiging and
 * anything else with a statutory delivery duty is in a protected category:
 * the send proceeds and the override is recorded, so it can be shown
 * afterwards.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use DateTimeImmutable;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;

/**
 * Holds and honours recipient opt-outs.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class OptOutRegistry {

	/**
	 * The schema one opt-out is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'recipient_opt_out';

	/**
	 * An opt-out that stops everything but the protected categories.
	 *
	 * @var string
	 */
	public const SCOPE_INSTANCE = 'instance';

	/**
	 * An opt-out that stops the updates on one case.
	 *
	 * @var string
	 */
	public const SCOPE_CASE = 'case';

	/**
	 * The app-config key holding the protected categories.
	 *
	 * @var string
	 */
	public const CONFIG_PROTECTED = 'outbound.protected_categories';

	/**
	 * The categories an opt-out never stops, until an instance says otherwise.
	 *
	 * @var array<int,string>
	 */
	public const DEFAULT_PROTECTED = ['besluit', 'ontvangstbevestiging', 'statutory', 'invordering'];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads and writes the opt-outs.
	 * @param IAppConfig $appConfig Holds the protected categories for this instance.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Whether a message may be sent, and whether sending it overrides an opt-out.
	 *
	 * @param string $address The recipient.
	 * @param string $category What kind of message this is.
	 * @param string|null $caseRef The case, when the message is about one.
	 *
	 * @return array{send:bool,overridden:bool,reason:string} The decision.
	 */
	public function decide(string $address, string $category, ?string $caseRef = null): array {
		$optOut = $this->find(address: $address, caseRef: $caseRef);
		if ($optOut === null) {
			return ['send' => true, 'overridden' => false, 'reason' => ''];
		}

		$scope = (string)($optOut['scope'] ?? self::SCOPE_INSTANCE);
		if ($this->isProtected(category: $category) === true) {
			return [
				'send' => true,
				'overridden' => true,
				'reason' => 'Category "' . $category . '" cannot be stopped by an opt-out.',
			];
		}

		return [
			'send' => false,
			'overridden' => false,
			'reason' => 'This address opted out (' . $scope . ').',
		];

	}//end decide()

	/**
	 * Add an opt-out.
	 *
	 * @param string $address The recipient.
	 * @param string $scope Instance wide or one case.
	 * @param string|null $caseRef The case, for a case scoped opt-out.
	 * @param string $source Who or what added it.
	 *
	 * @return ObjectEntity The stored opt-out.
	 */
	public function add(
		string $address,
		string $scope = self::SCOPE_INSTANCE,
		?string $caseRef = null,
		string $source = 'unsubscribe-link',
	): ObjectEntity {
		return $this->objectService->saveObject(
			object: [
				'address' => strtolower(trim($address)),
				'scope' => $scope,
				'caseRef' => (string)$caseRef,
				'source' => $source,
				'createdAt' => (new DateTimeImmutable())->format('c'),
			],
			register: MessageRecorder::REGISTER,
			schema: self::SCHEMA,
		);

	}//end add()

	/**
	 * Whether a category may never be stopped.
	 *
	 * @param string $category The category.
	 *
	 * @return bool True when it is protected.
	 */
	public function isProtected(string $category): bool {
		return in_array(strtolower(trim($category)), $this->protectedCategories(), true);

	}//end isProtected()

	/**
	 * The protected categories on this instance.
	 *
	 * @return array<int,string> The categories, lower case.
	 */
	public function protectedCategories(): array {
		$raw = $this->appConfig->getValueString('integriq', self::CONFIG_PROTECTED, '');
		if (trim($raw) === '') {
			return self::DEFAULT_PROTECTED;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false || $decoded === []) {
			return self::DEFAULT_PROTECTED;
		}

		return array_map(
			static fn (mixed $category): string => strtolower(trim((string)$category)),
			$decoded
		);

	}//end protectedCategories()

	/**
	 * The opt-out that applies to this address, if any.
	 *
	 * The address is the filter and the case is checked in the reading,
	 * because an instance wide opt-out and a case opt-out are two rows that
	 * both match on address.
	 *
	 * @param string $address The recipient.
	 * @param string|null $caseRef The case.
	 *
	 * @return array<string,mixed>|null The opt-out, or null.
	 */
	private function find(string $address, ?string $caseRef): ?array {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => MessageRecorder::REGISTER,
					'schema' => self::SCHEMA,
					'address' => strtolower(trim($address)),
				],
			]
		);

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return null;
		}

		$caseMatch = null;
		foreach ($results as $row) {
			if (($row instanceof ObjectEntity) === false) {
				continue;
			}

			$optOut = $row->getObject();
			if (strtolower((string)($optOut['address'] ?? '')) !== strtolower(trim($address))) {
				continue;
			}

			$scope = (string)($optOut['scope'] ?? self::SCOPE_INSTANCE);
			if ($scope === self::SCOPE_INSTANCE) {
				return $optOut;
			}

			if ($caseRef !== null && (string)($optOut['caseRef'] ?? '') === $caseRef) {
				$caseMatch = $optOut;
			}
		}

		return $caseMatch;

	}//end find()

}//end class
