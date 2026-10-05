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
 * The opt-outs live in integriq's own table (OptOutMapper), not in
 * OpenRegister. The unsubscribe link writes one from a public request with no
 * session, which OpenRegister refuses, and the read that decides whether to
 * send must not be one a permission check can empty (integriq#2114). The
 * `recipient_opt_out` schema is read-only history: MigrateOptOutsToTable copies
 * it into the table and nothing writes it any more.
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

use OCA\Integriq\Db\OptOut;
use OCA\Integriq\Db\OptOutMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Holds and honours recipient opt-outs.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class OptOutRegistry {

	/**
	 * The OpenRegister schema opt-outs were stored under before the table.
	 * Read-only history: only MigrateOptOutsToTable reads it.
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
	 * @param OptOutMapper $mapper Reads and writes the opt-out table.
	 * @param IAppConfig $appConfig Holds the protected categories for this instance.
	 * @param ITimeFactory $time Stamps a new opt-out.
	 */
	public function __construct(
		private readonly OptOutMapper $mapper,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
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
	 * @return OptOut The stored opt-out. Adding the same one twice returns the first.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function add(
		string $address,
		string $scope = self::SCOPE_INSTANCE,
		?string $caseRef = null,
		string $source = 'unsubscribe-link',
	): OptOut {
		$address = strtolower(trim($address));
		$caseRef = (string)$caseRef;
		if ($scope === self::SCOPE_INSTANCE) {
			$caseRef = '';
		}

		$optOut = new OptOut();
		$optOut->setAddress($address);
		$optOut->setScope($scope);
		$optOut->setCaseRef($caseRef);
		$optOut->setSource($source);
		$optOut->setCreatedAt($this->time->getTime());
		$optOut->setDedupeKey(OptOut::keyFor(address: $address, scope: $scope, caseRef: $caseRef));

		return $this->mapper->insertIfAbsent($optOut)['optOut'];

	}//end add()

	/**
	 * One page of the opt-out list, newest first.
	 *
	 * @param int $limit  At most this many rows.
	 * @param int $offset Skip this many.
	 *
	 * @return array{results:list<array<string,mixed>>,total:int} The page.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function page(int $limit = 50, int $offset = 0): array {
		$rows = array_map(
			static fn (OptOut $optOut): array => $optOut->jsonSerialize(),
			$this->mapper->findPage(limit: $limit, offset: $offset)
		);

		return ['results' => $rows, 'total' => $this->mapper->countAll()];

	}//end page()

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
		$caseMatch = null;
		foreach ($this->mapper->findForAddress(address: $address) as $row) {
			$optOut = $row->jsonSerialize();
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
