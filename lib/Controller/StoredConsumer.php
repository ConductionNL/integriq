<?php

/**
 * StoredConsumer
 *
 * The trust configuration and account an intake connection's consumer holds,
 * read the same way by every connection settings form.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A consumer's `authorizationConfiguration` and `userId`, empty when there is no consumer.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
 */
final class StoredConsumer {

	/**
	 * The stored trust configuration, or [] when it is absent or no array.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $trust;

	/**
	 * The stored account, or ''.
	 *
	 * @var string
	 */
	public readonly string $userId;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity|null $consumer The consumer, or null when there is none.
	 */
	public function __construct(?ObjectEntity $consumer) {
		$data = [];
		if ($consumer !== null) {
			$data = $consumer->getObject();
		}

		$trust = ($data['authorizationConfiguration'] ?? []);
		if (is_array($trust) === false) {
			$trust = [];
		}

		$this->trust = $trust;
		$this->userId = (string)($data['userId'] ?? '');

	}//end __construct()
}//end class
