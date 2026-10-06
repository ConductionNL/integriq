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
 * @method string getState()
 * @method void setState(string $state)
 * @method string getChannel()
 * @method void setChannel(string $channel)
 * @method string getPurpose()
 * @method void setPurpose(string $purpose)
 * @method string getListRef()
 * @method void setListRef(string $listRef)
 * @method string getContactRef()
 * @method void setContactRef(string $contactRef)
 * @method string getLawfulBasis()
 * @method void setLawfulBasis(string $lawfulBasis)
 * @method string|null getEvidence()
 * @method void setEvidence(?string $evidence)
 * @method int|null getWithdrawnAt()
 * @method void setWithdrawnAt(?int $withdrawnAt)
 * @method string getSourceApp()
 * @method void setSourceApp(string $sourceApp)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 *
 * @SuppressWarnings(PHPMD.TooManyFields) -- one field per column of integriq_opt_outs; the consent
 * columns pipelinq's records need (opt-out-before-send design section 4) are part of the row.
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */
class OptOut extends Entity implements JsonSerializable {

	/**
	 * The person asked not to be written to.
	 *
	 * @var string
	 */
	public const STATE_OPTED_OUT = 'opted-out';

	/**
	 * The person gave consent, with a lawful basis and evidence.
	 *
	 * @var string
	 */
	public const STATE_OPTED_IN = 'opted-in';

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
	 * `opted-out` or `opted-in`.
	 *
	 * @var string
	 */
	protected $state = self::STATE_OPTED_OUT;

	/**
	 * The channel a `channel` scoped row covers; empty for every channel.
	 *
	 * @var string
	 */
	protected $channel = '';

	/**
	 * What the consent is for, for example `marketing`.
	 *
	 * @var string
	 */
	protected $purpose = '';

	/**
	 * The list a `list` scoped row covers.
	 *
	 * @var string
	 */
	protected $listRef = '';

	/**
	 * The sibling app's contact, so a changed address still matches.
	 *
	 * @var string
	 */
	protected $contactRef = '';

	/**
	 * The AVG article 6 basis of an `opted-in` row.
	 *
	 * @var string
	 */
	protected $lawfulBasis = '';

	/**
	 * What was shown when consent was given, as JSON.
	 *
	 * @var string|null
	 */
	protected $evidence = null;

	/**
	 * When an `opted-in` row was withdrawn, as a unix timestamp.
	 *
	 * @var int|null
	 */
	protected $withdrawnAt = null;

	/**
	 * The app that recorded it.
	 *
	 * @var string
	 */
	protected $sourceApp = '';

	/**
	 * When the state last changed, as a unix timestamp.
	 *
	 * @var int
	 */
	protected $updatedAt = 0;

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
		$this->addType(fieldName: 'state', type: 'string');
		$this->addType(fieldName: 'channel', type: 'string');
		$this->addType(fieldName: 'purpose', type: 'string');
		$this->addType(fieldName: 'listRef', type: 'string');
		$this->addType(fieldName: 'contactRef', type: 'string');
		$this->addType(fieldName: 'lawfulBasis', type: 'string');
		$this->addType(fieldName: 'evidence', type: 'string');
		$this->addType(fieldName: 'withdrawnAt', type: 'integer');
		$this->addType(fieldName: 'sourceApp', type: 'string');
		$this->addType(fieldName: 'updatedAt', type: 'integer');

	}//end __construct()

	/**
	 * The key that makes one opt-out one row.
	 *
	 * The channel is appended only when it is not empty, so a row from before
	 * channels existed keeps the key it has. The purpose is appended the same
	 * way, behind a label so it can never read as a channel: a marketing
	 * opt-out and a "stop everything" opt-out on one scope are two rows.
	 *
	 * @param string $address The recipient.
	 * @param string $scope   Instance, channel, case or list.
	 * @param string $ref     The case for a case scope, the list for a list scope, or empty.
	 * @param string $channel The channel, or empty.
	 * @param string $purpose The purpose, or empty for everything.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
	 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
	 */
	public static function keyFor(string $address, string $scope, string $ref, string $channel = '', string $purpose = ''): string {
		$material = strtolower(trim($address)) . "\n" . $scope . "\n" . $ref;
		if ($channel !== '') {
			$material .= "\n" . $channel;
		}

		if ($purpose !== '') {
			$material .= "\npurpose:" . $purpose;
		}

		return hash('sha256', $material);

	}//end keyFor()

	/**
	 * Set the dedupe key from this row's address, scope, ref, channel and purpose.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
	 */
	public function assignDedupeKey(): void {
		$ref = (string)$this->getCaseRef();
		if ($this->getScope() === 'list') {
			$ref = (string)$this->getListRef();
		}

		$this->setDedupeKey(
			self::keyFor(
				address: (string)$this->getAddress(),
				scope: (string)$this->getScope(),
				ref: $ref,
				channel: (string)$this->getChannel(),
				purpose: (string)$this->getPurpose()
			)
		);

	}//end assignDedupeKey()

	/**
	 * The evidence as an array.
	 *
	 * @return array<string,mixed> The evidence, empty when there is none.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-marketing-needs-recorded-consent-req-ooa-005
	 */
	public function evidenceArray(): array {
		$decoded = json_decode((string)$this->getEvidence(), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;

	}//end evidenceArray()

	/**
	 * The row as the opt-out list and the registry read it.
	 *
	 * @return array<string,mixed>
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
			'state' => (string)($this->getState() ?? self::STATE_OPTED_OUT),
			'channel' => (string)$this->getChannel(),
			'purpose' => (string)$this->getPurpose(),
			'listRef' => (string)$this->getListRef(),
			'contactRef' => (string)$this->getContactRef(),
			'lawfulBasis' => (string)$this->getLawfulBasis(),
			'withdrawnAt' => $this->formatTime(time: $this->getWithdrawnAt()),
			'sourceApp' => (string)$this->getSourceApp(),
			'updatedAt' => $this->formatTime(time: $this->getUpdatedAt()),
		];

	}//end jsonSerialize()

	/**
	 * A timestamp as ISO 8601, or null when it is not set.
	 *
	 * @param int|null $time The unix time.
	 *
	 * @return string|null The time.
	 */
	private function formatTime(?int $time): ?string {
		if ($time === null || $time === 0) {
			return null;
		}

		return gmdate('c', $time);

	}//end formatTime()

}//end class
