<?php

/**
 * Contract of the KissSyncService.
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

use OCA\Integriq\Exception\CallEventNotFoundException;
use OCA\Integriq\Exception\KissProviderException;
use OCA\Integriq\Service\Kiss\KlantinteractiesProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see KissSyncService} that other components call.
 */
interface KissSyncServiceInterface {
	/**
	 * Run one pull sweep over every configured KISS source.
	 *
	 * A source without an active KISS configuration (none configured at all,
	 * or the resolved source is misconfigured) is a clean no-op, never a
	 * thrown exception out of this method — the cron job calls this
	 * unconditionally on every sweep (REQ: pull job no-ops when unconfigured).
	 *
	 * @return integer The total number of klantcontacten upserted across every source in this sweep.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function pullAll(): int;

	/**
	 * Pull one source's new/changed klantcontacten and upsert them.
	 *
	 * Per-record isolation: a single malformed/unpersistable klantcontact is
	 * logged and skipped — it does NOT abort the rest of the page and does
	 * NOT block the cursor from advancing (see design.md "Cursor semantics"
	 * for why availability of newer records is favoured over perfect retry
	 * of a persistently failing one).
	 *
	 * @param ObjectEntity $source The KISS source to pull.
	 *
	 * @return array{processed: integer, skipped: integer, cursor: string|null} The sweep outcome.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function pullSource(ObjectEntity $source): array;

	/**
	 * Register a klantcontact in KISS on behalf of a sibling app and link it
	 * to a case (onderwerpobject), persisting a local mirror record.
	 *
	 * @param array $input The push payload: `onderwerp`, `kanaal`, `tekst`, `plaatsgevondenOp`,
	 *                     `indicatieContactGelukt`, `taal`, `betrokkene` (optional),
	 *                     `sourceApp`, `caseReference` (optional), `caseObjectType` (optional,
	 *                     default `zaak`), `callId` and `callSourceId` (optional: record
	 *                     the contact moment for that ended CTI call).
	 *
	 * @return array{id: string, localUuid: string} The KISS-assigned klantcontact id and local record uuid.
	 *
	 * @throws KissProviderException When no active KISS source is configured, or KISS rejects the request.
	 * @throws CallEventNotFoundException When `callId` names no single ended call.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
	 */
	public function pushCustomerContact(array $input): array;

	/**
	 * Resolve the single active KISS source (`type=kiss`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws KissProviderException When no active KISS source is configured.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function resolveActiveSource(): ObjectEntity;

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The KISS source's `configuration` object.
	 *
	 * @return KlantinteractiesProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function resolveProvider(array $configuration): KlantinteractiesProviderInterface;
}
