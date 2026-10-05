<?php

/**
 * Integriq OptOut entity.
 *
 * One recipient's request not to be written to, in integriq's own table. It
 * lives here and not in OpenRegister because the unsubscribe link writes it
 * from a public request with no session, and OpenRegister refuses that write
 * (anonymous writes fail closed, and runAsSystem() may not be reached from a
 * request path, ADR-099 section 9).
 *
 * @category Db
 * @package  OCA\Integriq\Db
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
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One opt-out row.
 *
 * @method string getAddress()
 * @method void setAddress(string $address)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string getCaseRef()
 * @method void setCaseRef(string $caseRef)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string getDedupeKey()
 * @method void setDedupeKey(string $dedupeKey)
 * @method string|null getLegacyUuid()
 * @method void setLegacyUuid(?string $legacyUuid)
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */
class OptOut extends Entity implements JsonSerializable {

	/**
	 * The recipient address, lower case.
	 *
	 * @var string
	 */
	protected $address = '';

	/**
	 * `instance` or `case`.
	 *
	 * @var string
	 */
	protected $scope = '';

	/**
	 * The case, for a case scoped opt-out; empty otherwise.
	 *
	 * @var string
	 */
	protected $caseRef = '';

	/**
	 * Who or what added it.
	 *
	 * @var string
	 */
	protected $source = '';

	/**
	 * When it was added, as a unix timestamp.
	 *
	 * @var int
	 */
	protected $createdAt = 0;

	/**
	 * sha256 over address, scope and case: one row per opt-out.
	 *
	 * @var string
	 */
	protected $dedupeKey = '';

	/**
	 * The uuid of the OpenRegister object this row was copied from, if any.
	 *
	 * @var string|null
	 */
	protected $legacyUuid = null;

	/**
	 * Declare the column types.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function __construct() {
		$this->addType(fieldName: 'address', type: 'string');
		$this->addType(fieldName: 'scope', type: 'string');
		$this->addType(fieldName: 'caseRef', type: 'string');
		$this->addType(fieldName: 'source', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'integer');
		$this->addType(fieldName: 'dedupeKey', type: 'string');
		$this->addType(fieldName: 'legacyUuid', type: 'string');

	}//end __construct()

	/**
	 * The key that makes one opt-out one row.
	 *
	 * @param string $address The recipient.
	 * @param string $scope   Instance wide or one case.
	 * @param string $caseRef The case, or empty.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public static function keyFor(string $address, string $scope, string $caseRef): string {
		return hash('sha256', strtolower(trim($address)) . "\n" . $scope . "\n" . $caseRef);

	}//end keyFor()

	/**
	 * Set the dedupe key from this row's address, scope and case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function assignDedupeKey(): void {
		$this->setDedupeKey(
			self::keyFor(address: (string)$this->getAddress(), scope: (string)$this->getScope(), caseRef: (string)$this->getCaseRef())
		);

	}//end assignDedupeKey()

	/**
	 * The row as the opt-out list and the registry read it.
	 *
	 * @return array{id:int|null,address:string,scope:string,caseRef:string,source:string,createdAt:string}
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'address' => (string)$this->getAddress(),
			'scope' => (string)$this->getScope(),
			'caseRef' => (string)$this->getCaseRef(),
			'source' => (string)$this->getSource(),
			'createdAt' => gmdate('c', (int)$this->getCreatedAt()),
		];

	}//end jsonSerialize()

}//end class
