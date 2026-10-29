<?php

/**
 * Contract of the PromotionService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Public operations of {@see PromotionService} that other components call.
 *
 * The method signatures repeat those of PromotionService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
interface PromotionServiceInterface {
	/**
	 * Export a configuration group unchanged (REQ-002).
	 *
	 * @param string $configurationId The configuration group id.
	 *
	 * @return array<string,mixed> The exported OAS document, verbatim.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-exports-locally-unchanged-and-dispatches-to-the-targets-existing-import-endpoints-req-002
	 */
	public function export(string $configurationId): array;

	/**
	 * Resolve a target environment's connectivity Source, or throw an
	 * actionable error naming the missing reference (REQ-001 scenario 2).
	 * Called BEFORE any export or remote call, so an unresolvable target
	 * never triggers either.
	 *
	 * @param string $targetEnvironmentSlug The target environment's slug.
	 *
	 * @return ObjectEntity The target environment's connectivity Source object.
	 *
	 * @throws InvalidArgumentException When the environment or its sourceRef does not resolve.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-an-environment-without-a-resolvable-sourceref-cannot-be-used-as-a-promotion-target
	 */
	public function resolveTargetSource(string $targetEnvironmentSlug): ObjectEntity;

	/**
	 * Detect every `{"credentialRef": {...}}` placeholder under each
	 * exported Source's `configuration.authentication` (REQ-004) — the same
	 * shape {@see BrokeredCallService::isPlaceholder()} detects, applied to
	 * the already-exported document rather than a live Source object.
	 *
	 * @param array<string,mixed> $document The exported OAS document.
	 *
	 * @return array<int,array{type:string,slug:string,field:string}> Flagged placeholders.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-credentialref-placeholders-are-re-bound-per-target-environment-never-resolved-to-a-secret-req-004
	 */
	public function scanCredentialRefs(array $document): array;

	/**
	 * Rewrite flagged `credentialRef` placeholders in-process using
	 * operator-supplied replacements. A Source with no matching binding is
	 * sent verbatim — never dropped or defaulted (REQ-004). This method only
	 * ever reads/writes reference strings (`credentialId`/`credentialName`);
	 * it never calls a credential-broker resolution method.
	 *
	 * @param array<string,mixed> $document The exported OAS document.
	 * @param array<int,array<string,string>> $credentialBindings Operator-supplied rebindings, each
	 *                                                            `{sourceSlug, field?, credentialId?, credentialName?}` (see class docblock).
	 *
	 * @return array<string,mixed> The document with matched placeholders rewritten.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-an-operator-supplied-rebinding-replaces-the-reference-before-the-target-ever-sees-the-original
	 */
	public function applyCredentialBindings(array $document, array $credentialBindings): array;

	/**
	 * Compute the merged promotion preview: the target's own REQ-007
	 * classification plus the locally-scanned `credentialRefsNeedingRebind`
	 * bucket (REQ-003). Non-mutating — nothing is written on either side.
	 *
	 * @param string $configurationId The configuration group id.
	 * @param string $targetEnvironmentSlug The target environment's slug.
	 * @param array<int,array<string,string>> $credentialBindings Operator-supplied rebindings (see class docblock for shape).
	 *
	 * @return array<string,mixed> The merged preview response.
	 *
	 * @throws InvalidArgumentException When the target environment does not resolve.
	 * @throws RuntimeException When the target's preview call fails or returns a non-2xx response.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003
	 */
	public function preview(string $configurationId, string $targetEnvironmentSlug, array $credentialBindings = []): array;

	/**
	 * Confirmed promotion (REQ-002/REQ-005/REQ-006): exports locally,
	 * rewrites rebindings, dispatches the confirmed import to the target,
	 * and writes exactly one `promotion_audit` object — success or failure.
	 *
	 * @param string $configurationId The configuration group id.
	 * @param string $targetEnvironmentSlug The target environment's slug.
	 * @param array<int,array<string,string>> $credentialBindings Operator-supplied rebindings (see class docblock for shape).
	 * @param string $actorUid The confirming operator's Nextcloud user id.
	 * @param string $fromEnvironmentSlug Origin slug (default "local", the seeded local-instance row).
	 *
	 * @return array<string,mixed> The target's post-import summary plus `auditId`/`callLogId`.
	 *
	 * @throws InvalidArgumentException When the target environment does not resolve (still audited as `failed`).
	 * @throws RuntimeException When the target's import call fails (still audited as `failed`).
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-every-promotion-attempt-is-recorded-in-an-append-only-promotion-audit-log-req-006
	 */
	public function promote(
		string $configurationId,
		string $targetEnvironmentSlug,
		array $credentialBindings,
		string $actorUid,
		string $fromEnvironmentSlug = 'local',
	): array;
}
