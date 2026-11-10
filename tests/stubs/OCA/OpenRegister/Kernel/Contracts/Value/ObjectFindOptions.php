<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions.
 *
 * Same constructor as the OpenRegister value object, so a test builds and
 * reads it the way production does when the peer app is not installed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;

/**
 * How one object is read: its register and schema, what to extend, and whether to render and audit.
 */
final class ObjectFindOptions {
	/**
	 * @param RegisterInterface|string|int|null $register The register.
	 * @param SchemaInterface|string|int|null $schema The schema.
	 * @param array|null $extend The properties to expand.
	 * @param bool $files Whether to load the object's files.
	 * @param bool $render Render before returning; false yields the raw entity (secrets intact).
	 * @param bool $audit Record an audit-trail row for the read.
	 */
	public function __construct(
		public readonly RegisterInterface|string|int|null $register = null,
		public readonly SchemaInterface|string|int|null $schema = null,
		public readonly ?array $extend = [],
		public readonly bool $files = false,
		public readonly bool $render = true,
		public readonly bool $audit = true,
	) {
	}
}
