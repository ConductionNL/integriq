<?php

/**
 * Integriq promotion service.
 *
 * Promotes a configuration group from this ("local") instance to a
 * registered target environment. Built entirely on already-existing,
 * unmodified primitives (environments-and-promotion proposal.md "Approach"):
 * {@see ConfigurationService::exportConfiguration()} for the local export,
 * {@see CallService::call()} (and, transitively, {@see BrokeredCallService})
 * for reaching the target environment's own `/api/configurations/import*`
 * endpoints, and the target's own {@see ConfigurationImportPreviewService}
 * response SHAPE (not its code — the actual preview call happens ON the
 * target, per design.md Decision 4). This class never calls
 * `CredentialBrokerService::resolveInjectable()` or any method that returns
 * a plaintext secret — `credentialRef` re-binding operates on reference
 * strings only (design.md Decision 3).
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/environments-and-promotion/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use RuntimeException;
use Throwable;

/**
 * Orchestrates promotion: local export -> credentialRef scan/rebind ->
 * remote preview/import dispatch via the target environment's Source ->
 * append-only audit.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 *
 * @spec openspec/specs/environments-and-promotion/spec.md
 */
class PromotionService implements PromotionServiceInterface {
	// Frozen on the old id: this is the OpenRegister REGISTER SLUG, not the app id.
	// OpenRegister matches registers by slug; renaming it orphans every stored object.
	private const REGISTER = 'integriq';

	private const AUDIT_SCHEMA = 'promotion_audit';

	/**
	 * App-relative path (after the target Source's `location`) to the
	 * target's REQ-007 preview endpoint — reused unmodified.
	 *
	 * @var string
	 */
	private const IMPORT_PREVIEW_PATH = '/index.php/apps/integriq/api/configurations/import/preview';

	/**
	 * App-relative path (after the target Source's `location`) to the
	 * target's REQ-008 confirmed import endpoint — reused unmodified.
	 *
	 * @var string
	 */
	private const IMPORT_PATH = '/index.php/apps/integriq/api/configurations/import';

	/**
	 * Constructor.
	 *
	 * @param ConfigurationService $configurationService The existing, unmodified export/import service.
	 * @param EnvironmentService $environmentService Resolves target environments and their connectivity Source.
	 * @param CallService $callService The existing outbound dispatch pipeline (reused unchanged).
	 * @param OrObjectService $orObjectService Used only to write `promotion_audit` objects.
	 */
	public function __construct(
		private readonly ConfigurationService $configurationService,
		private readonly EnvironmentService $environmentService,
		private readonly CallService $callService,
		private readonly OrObjectService $orObjectService,
	) {

	}//end __construct()

	/**
	 * Export a configuration group unchanged (REQ-002).
	 *
	 * @param string $configurationId The configuration group id.
	 *
	 * @return array<string,mixed> The exported OAS document, verbatim.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-exports-locally-unchanged-and-dispatches-to-the-targets-existing-import-endpoints-req-002
	 */
	public function export(string $configurationId): array {
		return $this->configurationService->exportConfiguration(configurationId: $configurationId);
	}//end export()

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
	public function resolveTargetSource(string $targetEnvironmentSlug): ObjectEntity {
		$environment = $this->environmentService->findBySlug(slug: $targetEnvironmentSlug);
		if ($environment === null) {
			throw new InvalidArgumentException("Target environment '{$targetEnvironmentSlug}' does not exist.");
		}

		$sourceRef = ($environment->getObject()['sourceRef'] ?? null);
		if (is_string($sourceRef) === false || $sourceRef === '') {
			throw new InvalidArgumentException("Environment '{$targetEnvironmentSlug}' has no sourceRef configured.");
		}

		$source = $this->environmentService->resolveSource(sourceRef: $sourceRef);
		if ($source === null) {
			throw new InvalidArgumentException(
				"Environment '{$targetEnvironmentSlug}' references sourceRef '{$sourceRef}', which does not "
				. 'resolve to an existing Source object. No export or remote call was attempted.'
			);
		}

		return $source;
	}//end resolveTargetSource()

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
	public function scanCredentialRefs(array $document): array {
		$flagged = [];
		foreach ($this->sourceAuthentications(document: $document) as $found) {
			$refs = new SourceCredentialRefs(slug: $found['slug']);
			$refs->collect(node: $found['authentication'], parentPath: 'configuration.authentication');
			$flagged = array_merge($flagged, $refs->flagged);
		}

		return $flagged;
	}//end scanCredentialRefs()

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
	public function applyCredentialBindings(array $document, array $credentialBindings): array {
		$index = $this->buildBindingIndex(credentialBindings: $credentialBindings);
		if (empty($index['bySlugField']) === true && empty($index['bySlug']) === true) {
			return $document;
		}

		foreach ($this->sourceAuthentications(document: $document) as $found) {
			$refs = new SourceCredentialRefs(slug: $found['slug'], index: $index);
			$document['components']['sources'][$found['group']][$found['index']]['configuration']['authentication'] = $refs->rewrite(
				node: $found['authentication'],
				parentPath: 'configuration.authentication'
			);
		}

		return $document;
	}//end applyCredentialBindings()

	/**
	 * Every exported Source that carries a `configuration.authentication`
	 * array, with where it sits in the document. Groups and entries that are
	 * not arrays are passed over.
	 *
	 * @param array<string,mixed> $document The exported OAS document.
	 *
	 * @return list<array{group:int|string,index:int|string,slug:string,authentication:array<string,mixed>}>
	 */
	private function sourceAuthentications(array $document): array {
		$sourceGroups = ($document['components']['sources'] ?? []);
		if (is_array($sourceGroups) === false) {
			return [];
		}

		$found = [];
		foreach ($sourceGroups as $group => $entities) {
			if (is_array($entities) === true) {
				$found = array_merge($found, $this->groupAuthentications(group: $group, entities: $entities));
			}
		}

		return $found;
	}//end sourceAuthentications()

	/**
	 * The Sources of one component group that carry a `configuration.authentication` array.
	 *
	 * @param int|string $group The component group key.
	 * @param array<int|string,mixed> $entities The group's entries.
	 *
	 * @return list<array{group:int|string,index:int|string,slug:string,authentication:array<string,mixed>}>
	 */
	private function groupAuthentications(int|string $group, array $entities): array {
		$found = [];
		foreach ($entities as $index => $sourceData) {
			$authentication = $this->authenticationOf(sourceData: $sourceData);
			if ($authentication !== null) {
				$found[] = ['group' => $group, 'index' => $index, 'slug' => (string)($sourceData['slug'] ?? ''), 'authentication' => $authentication];
			}
		}

		return $found;
	}//end groupAuthentications()

	/**
	 * A Source entry's `configuration.authentication`, when the entry and it are arrays.
	 *
	 * @param mixed $sourceData One entry of a component group.
	 *
	 * @return array<string,mixed>|null The authentication subtree, or null.
	 */
	private function authenticationOf(mixed $sourceData): ?array {
		if (is_array($sourceData) === false) {
			return null;
		}

		$authentication = ($sourceData['configuration']['authentication'] ?? null);
		if (is_array($authentication) === false) {
			return null;
		}

		return $authentication;
	}//end authenticationOf()

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
	public function preview(string $configurationId, string $targetEnvironmentSlug, array $credentialBindings = []): array {
		// REQ-001 scenario 2: resolved BEFORE any export or remote call.
		$targetSource = $this->resolveTargetSource(targetEnvironmentSlug: $targetEnvironmentSlug);

		$document = $this->export(configurationId: $configurationId);
		$flagged = $this->scanCredentialRefs(document: $document);
		$index = $this->buildBindingIndex(credentialBindings: $credentialBindings);
		$flagged = $this->markRebound(flagged: $flagged, index: $index);

		$rewritten = $this->applyCredentialBindings(document: $document, credentialBindings: $credentialBindings);
		$callLog = $this->dispatchToTarget(source: $targetSource, path: self::IMPORT_PREVIEW_PATH, payload: ['document' => $rewritten]);
		$targetPreview = $this->decodeCallLogBody(callLog: $callLog);

		return array_merge($targetPreview, ['credentialRefsNeedingRebind' => $flagged]);
	}//end preview()

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
	): array {
		$run = new PromotionRun(configurationId: $configurationId, targetEnvironmentSlug: $targetEnvironmentSlug);
		$run->credentialBindings = $credentialBindings;
		$run->actorUid = $actorUid;
		$run->fromEnvironmentSlug = $fromEnvironmentSlug;

		return $this->promoteRun(run: $run);
	}//end promote()

	/**
	 * Promote, and audit the attempt either way: a failure is audited and rethrown.
	 *
	 * @param PromotionRun $run The promotion.
	 *
	 * @return array<string,mixed> The target's post-import summary plus `auditId`/`callLogId`.
	 *
	 * @throws Throwable Whatever stopped the promotion, after its `failed` audit.
	 */
	private function promoteRun(PromotionRun $run): array {
		$index = $this->buildBindingIndex(credentialBindings: $run->credentialBindings);

		try {
			return $this->promoteDocument(run: $run, index: $index);
		} catch (Throwable $e) {
			$this->auditFailure(run: $run, exception: $e);
			throw $e;
		}

	}//end promoteRun()

	/**
	 * Export, rebind, import on the target, and audit the success.
	 *
	 * @param PromotionRun $run The promotion.
	 * @param array<string,array<string,array<string,string>>> $index Binding index (see {@see buildBindingIndex()}).
	 *
	 * @return array<string,mixed> The target's post-import summary plus `auditId`/`callLogId`.
	 */
	private function promoteDocument(PromotionRun $run, array $index): array {
		$targetSource = $this->resolveTargetSource(targetEnvironmentSlug: $run->targetEnvironmentSlug);

		$document = $this->export(configurationId: $run->configurationId);
		$run->rebindCount = $this->countRebound(flagged: $this->scanCredentialRefs(document: $document), index: $index);
		$rewritten = $this->applyCredentialBindings(document: $document, credentialBindings: $run->credentialBindings);

		$callLog = $this->dispatchToTarget(
			source: $targetSource,
			path: self::IMPORT_PATH,
			payload: ['document' => $rewritten, 'confirmed' => true]
		);
		$run->callLogId = $callLog->getUuid();
		$result = $this->decodeCallLogBody(callLog: $callLog);

		$audit = $this->auditSuccess(run: $run, result: $result);

		return array_merge($result, ['auditId' => $audit->getUuid(), 'callLogId' => $run->callLogId]);
	}//end promoteDocument()

	/**
	 * Write the `success` audit: counts only, never the entities themselves.
	 *
	 * @param PromotionRun $run The promotion.
	 * @param array<string,mixed> $result The target's post-import summary.
	 *
	 * @return ObjectEntity The audit record.
	 */
	private function auditSuccess(PromotionRun $run, array $result): ObjectEntity {
		return $this->writeAudit(
			configurationId: $run->configurationId,
			fromEnvironmentSlug: $run->fromEnvironmentSlug,
			toEnvironmentSlug: $run->targetEnvironmentSlug,
			actorUid: $run->actorUid,
			startedAt: $run->startedAt,
			outcome: 'success',
			message: '',
			previewSummary: $this->previewSummary(result: $result),
			credentialRebindCount: $run->rebindCount,
			callLogId: $run->callLogId
		);
	}//end auditSuccess()

	/**
	 * Write the `failed` audit: the reason, no summary, the call log when the import was sent.
	 *
	 * @param PromotionRun $run The promotion.
	 * @param Throwable $exception What stopped it.
	 *
	 * @return void
	 */
	private function auditFailure(PromotionRun $run, Throwable $exception): void {
		$this->writeAudit(
			configurationId: $run->configurationId,
			fromEnvironmentSlug: $run->fromEnvironmentSlug,
			toEnvironmentSlug: $run->targetEnvironmentSlug,
			actorUid: $run->actorUid,
			startedAt: $run->startedAt,
			outcome: 'failed',
			message: $exception->getMessage(),
			previewSummary: [],
			credentialRebindCount: 0,
			callLogId: $run->callLogId
		);
	}//end auditFailure()

	/**
	 * The counts-only summary of the target's answer.
	 *
	 * @param array<string,mixed> $result The target's post-import summary.
	 *
	 * @return array{creates:int,updates:int,collisions:int,written:mixed}
	 */
	private function previewSummary(array $result): array {
		return [
			'creates' => count($result['creates'] ?? []),
			'updates' => count($result['updates'] ?? []),
			'collisions' => count($result['collisions'] ?? []),
			'written' => ($result['written'] ?? []),
		];
	}//end previewSummary()

	/**
	 * Dispatch a preview/import call to the target environment's Source via
	 * the existing, unmodified {@see CallService::call()} pipeline (REQ-002
	 * scenario 2) — CallLog auditing, retry, and BrokeredCallService
	 * credentialRef resolution FOR REACHING THE TARGET all apply unchanged.
	 *
	 * @param ObjectEntity $source The target environment's connectivity Source.
	 * @param string $path The app-relative REQ-007/REQ-008 endpoint path.
	 * @param array<string,mixed> $payload The JSON body (`document` [+ `confirmed`]).
	 *
	 * @return ObjectEntity The resulting CallLog object.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-promotion-dispatch-reuses-callservice-against-the-targets-environment-source
	 */
	private function dispatchToTarget(ObjectEntity $source, string $path, array $payload): ObjectEntity {
		return $this->callService->call(
			source: $source,
			endpoint: $path,
			method: 'POST',
			config: ['json' => $payload]
		);

	}//end dispatchToTarget()

	/**
	 * Decode a CallLog's response body into the target's JSON response,
	 * throwing an actionable error on a non-2xx or undecodable response
	 * (proposal.md Risk 1 — a target without the import routes returns 404).
	 *
	 * @param ObjectEntity $callLog The entity returned by {@see CallService::call()}.
	 *
	 * @return array<string,mixed> The decoded response body.
	 *
	 * @throws RuntimeException When the response is missing, non-2xx, or not valid JSON.
	 */
	private function decodeCallLogBody(ObjectEntity $callLog): array {
		$data = $callLog->getObject();
		$statusCode = (int)($data['statusCode'] ?? 0);
		$body = ($data['response']['body'] ?? null);

		$decoded = null;
		if (is_string($body) === true) {
			$decoded = json_decode($body, true);
		} elseif (is_array($body) === true) {
			$decoded = $body;
		}

		if ($statusCode < 200 || $statusCode >= 300) {
			$detail = '';
			if (is_array($decoded) === true) {
				$detail = (string)($decoded['error'] ?? '');
			}

			$message = "Target environment call failed with HTTP {$statusCode}.";
			if ($detail !== '') {
				$message = "Target environment call failed with HTTP {$statusCode}: {$detail}";
			}

			throw new RuntimeException($message);
		}

		if (is_array($decoded) === false) {
			throw new RuntimeException('Target environment returned a non-JSON response.');
		}

		return $decoded;
	}//end decodeCallLogBody()

	/**
	 * Write one `promotion_audit` object (REQ-006) — counts and slugs only,
	 * never entity payloads or credential values.
	 *
	 * @param string $configurationId The configuration group id.
	 * @param string $fromEnvironmentSlug Origin environment slug.
	 * @param string $toEnvironmentSlug Target environment slug.
	 * @param string $actorUid The confirming operator's Nextcloud user id.
	 * @param DateTime $startedAt When the promotion attempt started.
	 * @param string $outcome `success`|`failed`|`rejected`.
	 * @param string $message Actionable failure detail (empty on success).
	 * @param array<string,mixed> $previewSummary Counts-only summary (empty on failure).
	 * @param int $credentialRebindCount Number of credentialRef placeholders rewritten.
	 * @param string|null $callLogId UUID of the underlying CallLog, if dispatched.
	 *
	 * @return ObjectEntity The created `promotion_audit` object.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-a-successful-promotion-is-audited
	 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-a-failed-promotion-is-still-audited
	 */
	private function writeAudit(
		string $configurationId,
		string $fromEnvironmentSlug,
		string $toEnvironmentSlug,
		string $actorUid,
		DateTime $startedAt,
		string $outcome,
		string $message,
		array $previewSummary,
		int $credentialRebindCount,
		?string $callLogId,
	): ObjectEntity {
		$payload = [
			'actorUid' => $actorUid,
			'configurationId' => $configurationId,
			'fromEnvironmentSlug' => $fromEnvironmentSlug,
			'toEnvironmentSlug' => $toEnvironmentSlug,
			'startedAt' => $startedAt->format('c'),
			'completedAt' => (new DateTime())->format('c'),
			'outcome' => $outcome,
			'message' => $message,
			'previewSummary' => $previewSummary,
			'credentialRebindCount' => $credentialRebindCount,
			'callLogId' => ($callLogId ?? ''),
		];

		return $this->orObjectService->saveObject(object: $payload, register: self::REGISTER, schema: self::AUDIT_SCHEMA);
	}//end writeAudit()

	/**
	 * Build a reference-only replacement (`credentialId`/`credentialName`)
	 * from an operator-supplied binding entry. Never reads a plaintext
	 * secret — only the two reference-shape keys are consulted.
	 *
	 * @param array<string,mixed> $binding One `credentialBindings[]` entry.
	 *
	 * @return array<string,string>|null The replacement reference, or null when the entry is unusable.
	 */
	private function extractReplacement(array $binding): ?array {
		$credentialId = ($binding['credentialId'] ?? null);
		if (is_string($credentialId) === true && $credentialId !== '') {
			return ['credentialId' => $credentialId];
		}

		$credentialName = ($binding['credentialName'] ?? null);
		if (is_string($credentialName) === true && $credentialName !== '') {
			return ['credentialName' => $credentialName];
		}

		return null;
	}//end extractReplacement()

	/**
	 * Index `credentialBindings[]` by `slug|field` (exact match) and by
	 * `slug` alone (field-agnostic fallback, for the common one-credentialRef-
	 * per-Source case).
	 *
	 * @param array<int,mixed> $credentialBindings Operator-supplied rebindings (unvalidated request input — each
	 *                                             entry is only trusted to be an array after the runtime `is_array()` guard below).
	 *
	 * @return array{bySlugField: array<string,array<string,string>>, bySlug: array<string,array<string,string>>}
	 */
	private function buildBindingIndex(array $credentialBindings): array {
		$bySlugField = [];
		$bySlug = [];

		foreach ($credentialBindings as $binding) {
			if (is_array($binding) === false) {
				continue;
			}

			$slug = ($binding['sourceSlug'] ?? null);
			if (is_string($slug) === false || $slug === '') {
				continue;
			}

			$replacement = $this->extractReplacement(binding: $binding);
			if ($replacement === null) {
				continue;
			}

			$field = ($binding['field'] ?? null);
			if (is_string($field) === true && $field !== '') {
				$bySlugField[$slug . '|' . $field] = $replacement;
				continue;
			}

			$bySlug[$slug] = $replacement;
		}//end foreach

		return ['bySlugField' => $bySlugField, 'bySlug' => $bySlug];
	}//end buildBindingIndex()

	/**
	 * Mark each flagged placeholder with whether an operator-supplied
	 * binding matches it.
	 *
	 * @param array<int,array{type:string,slug:string,field:string}> $flagged Scanned placeholders.
	 * @param array<string,array<string,array<string,string>>> $index Binding index (see {@see buildBindingIndex()}).
	 *
	 * @return array<int,array{type:string,slug:string,field:string,rebound:bool}>
	 */
	private function markRebound(array $flagged, array $index): array {
		$marked = [];
		foreach ($flagged as $entry) {
			$entry['rebound'] = $this->isBound(entry: $entry, index: $index);
			$marked[] = $entry;
		}

		return $marked;
	}//end markRebound()

	/**
	 * Count how many flagged placeholders an operator-supplied binding matches.
	 *
	 * @param array<int,array{type:string,slug:string,field:string}> $flagged Scanned placeholders.
	 * @param array<string,array<string,array<string,string>>> $index Binding index (see {@see buildBindingIndex()}).
	 *
	 * @return integer The number of rebound entries.
	 */
	private function countRebound(array $flagged, array $index): int {
		$count = 0;
		foreach ($flagged as $entry) {
			if ($this->isBound(entry: $entry, index: $index) === true) {
				$count++;
			}
		}

		return $count;
	}//end countRebound()

	/**
	 * Whether a flagged placeholder is matched by an operator-supplied binding.
	 *
	 * @param array{type:string,slug:string,field:string} $entry One flagged placeholder.
	 * @param array<string,array<string,array<string,string>>> $index Binding index (see {@see buildBindingIndex()}).
	 *
	 * @return boolean Whether the entry is bound.
	 */
	private function isBound(array $entry, array $index): bool {
		$key = $entry['slug'] . '|' . $entry['field'];

		return (isset($index['bySlugField'][$key]) === true || isset($index['bySlug'][$entry['slug']]) === true);
	}//end isBound()
}//end class
