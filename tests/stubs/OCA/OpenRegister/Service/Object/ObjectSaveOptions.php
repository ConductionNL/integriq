<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\ObjectSaveOptions.
 *
 * Same constructor as the OpenRegister value object (with the defaults
 * openregister #9123 gives it), so a test builds and reads it the way
 * production does when the peer app is not installed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Where and how one object is saved.
 */
final class ObjectSaveOptions {
	/**
	 * @param ObjectFindTarget $target The register and schema.
	 * @param string|null $uuid The uuid to save under.
	 * @param array|null $extend The properties to expand on the returned object.
	 * @param array|null $uploadedFiles Files uploaded with the object.
	 * @param SavePolicy $policy Silent, validation, create-only and deduplication flags.
	 * @param SaveActor $actor The acting user and the owner.
	 */
	public function __construct(
		public readonly ObjectFindTarget $target = new ObjectFindTarget(),
		public readonly ?string $uuid = null,
		public readonly ?array $extend = [],
		public readonly ?array $uploadedFiles = null,
		public readonly SavePolicy $policy = new SavePolicy(),
		public readonly SaveActor $actor = new SaveActor(),
	) {
	}
}
