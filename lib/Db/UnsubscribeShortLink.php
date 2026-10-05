<?php

/**
 * Integriq UnsubscribeShortLink entity.
 *
 * A ten-character id that stands for a version 3 unsubscribe token. A full
 * token is far longer than an SMS can carry, so the SMS carries the id and the
 * server keeps the token (approved by Ruben on 2026-10-05).
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One short link.
 *
 * @method string getShortId()
 * @method void setShortId(string $shortId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method int getExpiresAt()
 * @method void setExpiresAt(int $expiresAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
 */
class UnsubscribeShortLink extends Entity {

	/**
	 * The id in the SMS.
	 *
	 * @var string
	 */
	protected $shortId = '';

	/**
	 * The version 3 token it stands for.
	 *
	 * @var string
	 */
	protected $token = '';

	/**
	 * When the link stops working, as a unix timestamp.
	 *
	 * @var int
	 */
	protected $expiresAt = 0;

	/**
	 * When it was made, as a unix timestamp.
	 *
	 * @var int
	 */
	protected $createdAt = 0;

	/**
	 * Declare the column types.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function __construct() {
		$this->addType(fieldName: 'shortId', type: 'string');
		$this->addType(fieldName: 'token', type: 'string');
		$this->addType(fieldName: 'expiresAt', type: 'integer');
		$this->addType(fieldName: 'createdAt', type: 'integer');

	}//end __construct()

}//end class
