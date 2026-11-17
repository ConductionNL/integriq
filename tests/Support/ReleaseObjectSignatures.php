<?php

/**
 * The released object-service signatures, for recorders that name arguments by reflection.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Support;

/**
 * Parameter names and defaults of OpenRegister's released ObjectService methods, in order.
 */
interface ReleaseObjectSignatures {

	/**
	 * @return mixed
	 */
	public function find(mixed $id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true, bool $_audit = true, ?array $_extend = []): mixed;

	/**
	 * @return mixed
	 */
	public function findSilent(mixed $id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true, bool $_audit = true, ?array $_extend = []): mixed;

	/**
	 * @return mixed
	 */
	public function saveObject(mixed $object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, bool $silent = false, bool $_validation = true): mixed;

	/**
	 * @return mixed
	 */
	public function deleteObject(string $uuid, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false, mixed $currentUser = null, bool $permanent = false): mixed;

	/**
	 * @return mixed
	 */
	public function patchObject(string $objectId, array $data, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true, mixed $currentUser = null): mixed;
}//end interface
