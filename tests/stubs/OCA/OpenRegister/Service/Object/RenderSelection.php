<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\RenderSelection.
 *
 * Same constructor as the OpenRegister value object, so a test builds and
 * reads it the way production does when the peer app is not installed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The extend, filter, fields and unset lists of one render.
 */
final class RenderSelection {
	/**
	 * @param array|string|null $extend The properties to expand.
	 * @param array|null $filter The properties to keep.
	 * @param array|null $fields The fields to return.
	 * @param array|null $unset The properties to drop.
	 */
	public function __construct(
		public readonly array|string|null $extend,
		public readonly ?array $filter,
		public readonly ?array $fields,
		public readonly ?array $unset,
	) {
	}
}
