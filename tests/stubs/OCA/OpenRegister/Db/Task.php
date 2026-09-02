<?php

/**
 * Stub for OCA\OpenRegister\Db\Task.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the
 * standalone composer dev-environment. This stub satisfies `use` statements
 * and mock-builder calls for the shared task entity so unit tests can run
 * without the peer app. It uses the real Entity base so the magic
 * __call-based accessors (`getUuid()`, `getOnTimeout()`, ...) resolve the
 * way they do in production; a hand-rolled stub with only declared methods
 * would fatal on exactly the accessor a test forgot (a fake that agrees
 * with the caller cannot fail).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Minimal stub for OCA\OpenRegister\Db\Task.
 */
class Task extends Entity {
	/** @var string|null */
	protected $uuid = null;

	/** @var string|null */
	protected $state = null;

	/** @var string|null */
	protected $outcome = null;

	/** @var string|null */
	protected $onTimeout = null;

	/** @var string|null */
	protected $onReject = null;

	/** @var string|null */
	protected $requester = null;

	/** @var string|null */
	protected $appId = null;

	/** @var array|null */
	protected $metadata = null;

	/** @var array|null */
	protected $candidateGroups = null;
}
