<?php

/**
 * Stub for OCA\OpenRegister\Service\ObjectService.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub satisfies PHPUnit mock-builder calls for
 * ObjectService so unit tests can run without a full Nextcloud server.
 *
 * Only the methods actually called by openconnector's lib/ are declared here.
 * PHPUnit's getMockBuilder() + disableOriginalConstructor() will then be able
 * to stub them via ->method('find')->willReturn(...) etc.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Minimal stub for OCA\OpenRegister\Service\ObjectService.
 */
class ObjectService {
	/**
	 * Find a single object by id/uuid.
	 *
	 * The real OpenRegister ObjectService throws DoesNotExistException when the
	 * object is not found. PHPUnit mocks may stub ->willReturn(null) for tests
	 * that verify the "not found" path; the nullable return type preserves that
	 * compatibility. PHPStan sees the @throws annotation and treats any catch
	 * of DoesNotExistException as live.
	 *
	 * ─────────────────────────────────────────────────────────────────────────
	 * `$_render` PARITY (ocon#215 / ADR-060 test-reality) — READ BEFORE EDITING
	 * ─────────────────────────────────────────────────────────────────────────
	 * `$_render` was ABSENT from this stub until ocon#151 phase C. That absence
	 * was not cosmetic: it made it structurally IMPOSSIBLE for any unit test to
	 * express — or to catch the loss of — the `_render: false` raw-read
	 * contract. That blind spot is exactly how ocon#215 shipped.
	 *
	 * Why it matters: a `source`'s credential fields are `writeOnly: true`, and
	 * OpenRegister's render boundary strips `writeOnly` UNCONDITIONALLY — admins
	 * included, `_rbac: false` included, and the `@self.relations` mirror
	 * included (openregister#389/#429). `_render: false` is the ONLY read that
	 * still carries a secret. Code that needs a raw secret and forgets it gets a
	 * credential-free object and dispatches unauthenticated, silently.
	 *
	 * KNOWN, DELIBERATE REMAINING DRIFT from the real OpenRegister signature
	 * (`origin/development` lib/Service/ObjectService.php::find()), which is:
	 *
	 *     find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render)
	 *
	 * This stub omits `$_extend` and `$files` and therefore orders its
	 * parameters differently. That is TOLERATED, not endorsed: every
	 * openconnector caller invokes find() with NAMED arguments, so parameter
	 * ORDER is not load-bearing for them, whereas a dozen existing tests use
	 * POSITIONAL `willReturnCallback(function ($id, $register, $schema) {...})`
	 * closures written against this shape. Restoring true parity would break
	 * those 38 assertions and is a separate, mechanical change — filed rather
	 * than smuggled into a credential-migration PR. `$_render` is appended LAST
	 * so it is reachable by name without disturbing any existing positional
	 * callback.
	 *
	 * ─────────────────────────────────────────────────────────────────────────
	 * WHY THIS DRIFT IS NOT FREE — IT BROKE 20 TESTS ON 2026-08-13
	 * ─────────────────────────────────────────────────────────────────────────
	 * The note above tolerates the missing `$_extend` and `$files` on the
	 * grounds that ORDER is not load-bearing for named callers. True — but it
	 * reads the risk one step short. A named argument does not need the right
	 * POSITION; it needs the parameter to EXIST. So the moment openregister
	 * added `$_audit` and openconnector started passing `_audit: false`, PHP
	 * raised `Error: Unknown named parameter $_audit` at the call site and 20
	 * tests across five suites went red at once — every one of them reporting a
	 * type or exception mismatch that named nothing about a stub.
	 *
	 * 🔑 THE FAILURE DOES NOT LOOK LIKE A STUB PROBLEM. It surfaces as
	 * `Failed asserting that exception of type "Error" matches expected
	 * exception "DoesNotExistException"` — a message about the test's subject,
	 * pointing at production line numbers, with the actual cause four frames
	 * down in a file under tests/stubs/. That is why it sat red across at least
	 * three CI runs while being read as "the sync tests are flaky".
	 *
	 * So parameters are APPENDED LAST as they are needed — the same technique
	 * `$_render` used — which keeps every positional `willReturnCallback`
	 * closure working while making the name resolvable. What is added is driven
	 * by what openconnector's lib/ actually passes BY NAME, not by mirroring the
	 * whole signature: `_audit` (3 call sites), `_extend` (2), and on
	 * saveObject() `silent` (2) and `_validation` (2).
	 *
	 * ⚠️ This will happen again on the next parameter openregister adds, and
	 * nothing here will warn anyone. A stub of a peer app's API is a copy that
	 * drifts silently, and the only thing that would catch it is a check that
	 * compares this file against the real signature. Filed as ocon#1241.
	 *
	 * @param string|int $id
	 * @param string|null $register
	 * @param string|null $schema
	 * @param bool $_rbac Apply RBAC filters when true.
	 * @param bool $_multitenancy Apply multitenancy filters when true.
	 * @param bool $_render Render before returning; false yields the RAW entity (secrets intact).
	 * @param bool $_audit Record an audit-trail row for the read; false for machine-to-machine reads.
	 * @param array|null $_extend Properties to expand on the returned object.
	 * @return ObjectEntity|null
	 *
	 * @throws DoesNotExistException When the object is not found.
	 */
	public function find(
		$id,
		?string $register = null,
		?string $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
		bool $_audit = true,
<<<<<<< HEAD
=======
		?array $_extend = [],
>>>>>>> origin/development
	): ?ObjectEntity {
		return new ObjectEntity();
	}

	/**
	 * Find all objects matching the given config/filters.
	 *
	 * @param array $config
	 * @param bool $_rbac Apply RBAC filters when true.
	 * @param bool $_multitenancy Apply multitenancy filters when true.
	 * @return array{results: ObjectEntity[], total: int}
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		return ['results' => [], 'total' => 0];
	}

	/**
	 * Get a mapper for the given register/schema (ADR-008 register/schema
	 * targetType dispatch, e.g. NRPS roster reads).
	 *
	 * Loosely typed (no strict return type) so unit tests can substitute any
	 * double exposing the subset of the real
	 * `OCA\OpenRegister\Service\ObjectServiceMapperAdapter` API a caller
	 * actually uses (typically `findAllPaginated()`), without needing to
	 * replicate that class's full surface here.
	 *
	 * @param int|string|null $register
	 * @param int|string|null $schema
	 * @return mixed
	 */
	public function getMapper($register = null, $schema = null) {
		return null;
	}

	/**
	 * Save (create or update) an object.
	 *
	 * `$_rbac`/`$_multitenancy` appended after `$uuid` — mirrors the real
	 * OpenRegister `ObjectService::saveObject()`'s trust-bypass params
	 * (needed by `LtiIdentityLinkService::createLink()`, which — like this
	 * same service's `findLink()`/`findPlatformData()` reads — writes with
	 * no interactive NC session in play, since the caller is a consuming
	 * app resolving an already-validated external LTI launch).
	 *
	 * IMPORTANT for anyone adding a `willReturnCallback()` closure against
	 * this mocked method: PHPUnit forwards the FULL positionally-resolved
	 * parameter list of THIS signature (explicit values + defaults for
	 * anything not touched at the real call site) to the closure — it does
	 * NOT preserve the real call's named-argument shape. A closure with
	 * fewer or differently-ordered parameters than declared here will
	 * silently receive shifted values. Every closure mocking this method
	 * MUST either declare parameters in this exact order
	 * (`$object, $register, $schema, $uuid, $_rbac, $_multitenancy`) or
	 * capture the tail with `...$rest`.
	 *
	 * @param array|ObjectEntity $object
	 * @param string|null $register
	 * @param string|null $schema
	 * @param string|null $uuid
	 * @param bool $_rbac Apply RBAC checks when true.
	 * @param bool $_multitenancy Apply multitenancy scoping when true.
	 * @return ObjectEntity
	 */
	public function saveObject(
		$object,
		?string $register = null,
		?string $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
	): ObjectEntity {
		return new ObjectEntity();
	}

	/**
<<<<<<< HEAD
	 * Bulk-save objects (OpenRegister's `ultraFastBulkSave` path).
	 *
	 * Present here because openconnector calls it — `SynchronizationContractService`
	 * flushes buffered contracts through it — and a stub that omits a method the
	 * caller uses fails at the call with "Unknown named parameter", which reads
	 * like a bug in the caller rather than a gap in the double.
	 *
	 * `_audit` in particular must be here. The bulk path always wrote audit rows
	 * where the single path's `silent` skipped them, so it was added to let a
	 * caller decline; a stub without it turns that correct call into an error.
	 *
	 * @param array $objects
	 * @param string|null $register
	 * @param string|null $schema
	 * @param bool $_rbac
	 * @param bool $_multitenancy
	 * @param bool $validation
	 * @param bool $events
	 * @param bool $deduplicateIds
	 * @param bool $enrich
	 * @param bool $_audit
	 *
	 * @return array
=======
	 * Bulk-save objects in one round trip.
	 *
	 * Added because `perf(sync)` moved the sync engine off one `saveObject()`
	 * per record and onto this, and the stub did not have it — so two
	 * EndoflifeDateSyncTest cases died on
	 * `Call to undefined method MockObject_ObjectService::saveObjects()`.
	 *
	 * Returns an empty array rather than a fabricated result set: every caller
	 * that cares stubs the return with `->willReturn(...)`, and inventing rows
	 * here would let a test that forgot to stub it assert against data this file
	 * made up.
	 *
	 * @param array $objects The objects to save.
	 * @param string|null $register The register.
	 * @param string|null $schema The schema.
	 * @param bool $_rbac Apply RBAC filters when true.
	 * @param bool $_multitenancy Apply multitenancy filters when true.
	 * @param bool $validation Validate each object against its schema.
	 * @param bool $events Dispatch object events.
	 * @param bool $deduplicateIds Drop duplicate ids within the batch.
	 * @param bool $enrich Enrich the saved objects before returning them.
	 * @param bool $_audit Record audit-trail rows for the batch.
	 *
	 * @return array The saved objects.
>>>>>>> origin/development
	 */
	public function saveObjects(
		array $objects,
		?string $register = null,
		?string $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $validation = false,
		bool $events = false,
		bool $deduplicateIds = true,
		bool $enrich = true,
		bool $_audit = true,
	): array {
<<<<<<< HEAD
		return ['saved' => [], 'errors' => [], 'statistics' => []];
=======
		return [];
>>>>>>> origin/development
	}

	/**
	 * Delete an object by uuid.
	 *
	 * @param string|null $uuid
	 * @param string|null $register
	 * @param string|null $schema
	 * @return bool
	 */
	public function deleteObject(?string $uuid = null, ?string $register = null, ?string $schema = null): bool {
		return true;
	}

	/**
	 * Set the active register context (fluent interface).
	 *
	 * @param string $register
	 * @return static
	 */
	public function setRegister(string $register): static {
		return $this;
	}

	/**
	 * Set the active schema context (fluent interface).
	 *
	 * @param string $schema
	 * @return static
	 */
	public function setSchema(string $schema): static {
		return $this;
	}

	/**
	 * Lock an object by identifier.
	 *
	 * @param string $identifier Object identifier.
	 * @param string|null $process Lock process identifier.
	 * @param int|null $duration Lock duration in seconds.
	 * @return array<string, mixed>
	 */
	public function lockObject(string $identifier, ?string $process = null, ?int $duration = null): array {
		return ['id' => $identifier, 'process' => $process, 'duration' => $duration];
	}

	/**
	 * Unlock an object by identifier.
	 *
	 * @param string|int $identifier Object identifier.
	 * @return bool
	 */
	public function unlockObject(string|int $identifier): bool {
		return true;
	}
}
