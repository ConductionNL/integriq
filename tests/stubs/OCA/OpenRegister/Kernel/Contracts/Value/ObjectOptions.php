<?php

/**
 * Stubs of openregister main's object option values: ObjectFindOptions,
 * ObjectFindTarget, ObjectSaveOptions, SavePolicy and SaveActor.
 *
 * Constructors as on openregister main (lib/Kernel/Contracts/Value), so lib/
 * code written against main's object API runs in pure-unit mode. The
 * register and schema accept any id or entity; only the shape matters here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

final class ObjectFindOptions {
	public function __construct(
		public readonly object|string|int|null $register=null,
		public readonly object|string|int|null $schema=null,
		public readonly ?array $extend=[],
		public readonly bool $files=false,
		public readonly bool $render=true,
		public readonly bool $audit=true,
	) {
	}
}

final class ObjectFindTarget {
	public function __construct(
		public readonly object|string|int|null $register=null,
		public readonly object|string|int|null $schema=null,
	) {
	}
}

final class SavePolicy {
	public function __construct(
		public readonly bool $silent=false,
		public readonly bool $validation=true,
		public readonly bool $failIfExists=false,
		public readonly bool $dedupOverride=false,
	) {
	}
}

final class SaveActor {
	public function __construct(
		public readonly ?object $currentUser=null,
		public readonly ?string $owner=null,
		public readonly bool $unowned=false,
	) {
	}
}

final class ObjectSaveOptions {
	public function __construct(
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?string $uuid=null,
		public readonly ?array $extend=[],
		public readonly ?array $uploadedFiles=null,
		public readonly SavePolicy $policy=new SavePolicy(),
		public readonly SaveActor $actor=new SaveActor(),
	) {
	}
}
