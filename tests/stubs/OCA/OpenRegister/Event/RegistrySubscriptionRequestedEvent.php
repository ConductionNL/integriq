<?php

/**
 * Stub for OCA\OpenRegister\Event\RegistrySubscriptionRequestedEvent.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub mirrors the real class's shape, verified
 * against openregister/lib/Event/RegistrySubscriptionRequestedEvent.php on
 * 2026-09-18 (five constructor arguments in this order, five getters and
 * `getPayload()` returning exactly these five keys).
 *
 * 🔴 A STUB THAT DRIFTS FROM THE REAL CLASS CAN ONLY PASS. If `getPayload()`
 * here returned keys the real event does not, the listener test would go green
 * against a payload production never sends, which is the same defect as a
 * double that adds a method the real class lacks. The key names are the whole
 * contract between the two apps, so they are copied rather than invented, and
 * the listener test asserts them by name.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Minimal stub for OCA\OpenRegister\Event\RegistrySubscriptionRequestedEvent.
 */
class RegistrySubscriptionRequestedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $objectUuid    The object the subscription is about.
	 * @param string $register      The register it lives in.
	 * @param string $schema        The schema it belongs to.
	 * @param string $registry      The registry to subscribe at.
	 * @param string $identityValue The identity to follow.
	 */
	public function __construct(
		private readonly string $objectUuid,
		private readonly string $register,
		private readonly string $schema,
		private readonly string $registry,
		private readonly string $identityValue,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The object the subscription is about.
	 *
	 * @return string The uuid.
	 */
	public function getObjectUuid(): string {
		return $this->objectUuid;
	}//end getObjectUuid()

	/**
	 * The register it lives in.
	 *
	 * @return string The register.
	 */
	public function getRegister(): string {
		return $this->register;
	}//end getRegister()

	/**
	 * The schema it belongs to.
	 *
	 * @return string The schema.
	 */
	public function getSchema(): string {
		return $this->schema;
	}//end getSchema()

	/**
	 * The registry to subscribe at.
	 *
	 * @return string The registry.
	 */
	public function getRegistry(): string {
		return $this->registry;
	}//end getRegistry()

	/**
	 * The identity to follow.
	 *
	 * @return string The identity.
	 */
	public function getIdentityValue(): string {
		return $this->identityValue;
	}//end getIdentityValue()

	/**
	 * The request as a payload.
	 *
	 * @return array<string, string> The payload.
	 */
	public function getPayload(): array {
		return [
			'objectUuid' => $this->objectUuid,
			'register' => $this->register,
			'schema' => $this->schema,
			'registry' => $this->registry,
			'identityValue' => $this->identityValue,
		];
	}//end getPayload()
}//end class
