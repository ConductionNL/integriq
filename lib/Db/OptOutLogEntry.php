<?php

/**
 * Integriq OptOutLogEntry entity.
 *
 * One line of the opt-out decision log: a recorded change, a suppressed
 * recipient, a send that went out despite an opt-out, or the count of allowed
 * recipients in one batch. Append-only: nothing updates a row, and the
 * retention job deletes rows older than seven years.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One log row.
 *
 * @method int getAt()
 * @method void setAt(int $at)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getAddress()
 * @method void setAddress(string $address)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method string getChannel()
 * @method void setChannel(string $channel)
 * @method string getSourceApp()
 * @method void setSourceApp(string $sourceApp)
 * @method string getCorrelationId()
 * @method void setCorrelationId(string $correlationId)
 * @method string|null getDetail()
 * @method void setDetail(?string $detail)
 *
 * @SuppressWarnings(PHPMD.ShortVariable) -- `$at` mirrors the `at` column the design names.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
 */
class OptOutLogEntry extends Entity implements JsonSerializable {

	/**
	 * A recorded change of a person's wish.
	 *
	 * @var string
	 */
	public const KIND_CHANGE = 'change';

	/**
	 * A recipient that was not sent to.
	 *
	 * @var string
	 */
	public const KIND_SUPPRESSED = 'suppressed';

	/**
	 * A send that went out despite an opt-out, because its category is exempt.
	 *
	 * @var string
	 */
	public const KIND_OVERRIDE = 'override';

	/**
	 * How many recipients of one batch were allowed.
	 *
	 * @var string
	 */
	public const KIND_ALLOWED_COUNT = 'allowed-count';

	/**
	 * When, as a unix timestamp.
	 *
	 * @var int
	 */
	protected $at = 0;

	/**
	 * One of the KIND_ constants.
	 *
	 * @var string
	 */
	protected $kind = '';

	/**
	 * The recipient key, never a plain BSN; empty on a count row.
	 *
	 * @var string
	 */
	protected $address = '';

	/**
	 * The category the send was decided as.
	 *
	 * @var string
	 */
	protected $category = '';

	/**
	 * The channel.
	 *
	 * @var string
	 */
	protected $channel = '';

	/**
	 * The app that asked or recorded.
	 *
	 * @var string
	 */
	protected $sourceApp = '';

	/**
	 * The sender's correlation id.
	 *
	 * @var string
	 */
	protected $correlationId = '';

	/**
	 * What else there is to say, as JSON.
	 *
	 * @var string|null
	 */
	protected $detail = null;

	/**
	 * Declare the column types.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function __construct() {
		$this->addType(fieldName: 'at', type: 'integer');
		$this->addType(fieldName: 'kind', type: 'string');
		$this->addType(fieldName: 'address', type: 'string');
		$this->addType(fieldName: 'category', type: 'string');
		$this->addType(fieldName: 'channel', type: 'string');
		$this->addType(fieldName: 'sourceApp', type: 'string');
		$this->addType(fieldName: 'correlationId', type: 'string');
		$this->addType(fieldName: 'detail', type: 'string');

	}//end __construct()

	/**
	 * The detail as an array.
	 *
	 * @return array<string,mixed> The detail.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function detailArray(): array {
		$decoded = json_decode((string)$this->getDetail(), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;

	}//end detailArray()

	/**
	 * The row as the admin log reads it.
	 *
	 * @return array<string,mixed> The row.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'at' => gmdate('c', (int)$this->getAt()),
			'kind' => (string)$this->getKind(),
			'address' => (string)$this->getAddress(),
			'category' => (string)$this->getCategory(),
			'channel' => (string)$this->getChannel(),
			'sourceApp' => (string)$this->getSourceApp(),
			'correlationId' => (string)$this->getCorrelationId(),
			'detail' => $this->detailArray(),
		];

	}//end jsonSerialize()

}//end class
