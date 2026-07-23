<?php
/**
 * Operator-gated removal of the vestigial `source.authenticationConfig` (ocon#232).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS CLASS DELETES CREDENTIAL DATA. THE GATING IS THE POINT.
 * ─────────────────────────────────────────────────────────────────────────────
 * `authenticationConfig` is vestigial — no PHP code authenticates from it (the
 * full evidence, including the commit that orphaned it, is on
 * {@see AuthenticationConfigAuditor}). The correct treatment is removal, not a
 * credentialRef migration: a ref minted for it would never be resolved by
 * anything. But "no consumer" does not make deletion casual — the value is real
 * plaintext credential material, and a delete is irreversible.
 *
 * So removal is gated FOUR independent ways:
 *
 *  1. NOT A REPAIR STEP. This class is reachable only from
 *     {@see \OCA\OpenConnector\Command\AuthenticationConfig}, an occ command. It is
 *     NOT registered in `appinfo/info.xml`'s `<repair-steps>`, so
 *     `occ maintenance:repair` and an app upgrade CANNOT reach it. Contrast
 *     {@see \OCA\OpenConnector\Repair\RemoveMigratedSourceSecretFields}, which IS a
 *     repair step — and which this change deliberately does NOT extend (see the
 *     command's class docblock for the justification).
 *  2. AN EXPLICIT FLAG. The command writes nothing unless a human passes
 *     `--remove-authentication-config`; its default action is the read-only audit.
 *  3. THIS METHOD'S OWN OPT-IN. {@see removeAll()} throws unless `$optIn === true`,
 *     so the gate survives even if a future caller forgets it. Defence in depth:
 *     the command's flag check and this check are independent.
 *  4. A TWIG-REFERENCE REFUSAL. A source whose `configuration` references
 *     `source.authenticationConfig` from a Twig template is REFUSED — see below.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A REFERENCED SOURCE IS REFUSED
 * ─────────────────────────────────────────────────────────────────────────────
 * No PHP reads the field, but {@see \OCA\OpenConnector\Service\CallService::renderValue()}
 * renders `configuration` values as Twig with the RAW source as context
 * (`_render: false` since ocon#215). So
 * `{{ source.authenticationConfig.client_secret }}` in an operator's header DOES
 * resolve to a live secret. Clearing the value under such a source would break its
 * outbound authentication. Those sources are reported `blocked` and left EXACTLY as
 * they are, for a human to re-point at `configuration.authentication` first.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Security;

use LogicException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Clears `authenticationConfig` from source objects — only under an explicit opt-in.
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-removal
 */
class AuthenticationConfigRemover
{

    /**
     * Outcome: the value was cleared and the save persisted.
     *
     * @var string
     */
    public const OUTCOME_REMOVED = 'removed';

    /**
     * Outcome: the source was already clear — nothing to do (idempotency).
     *
     * @var string
     */
    public const OUTCOME_SKIPPED = 'skipped';

    /**
     * Outcome: a live Twig template references the field — REFUSED, value left intact.
     *
     * @var string
     */
    public const OUTCOME_BLOCKED = 'blocked';

    /**
     * Outcome: the read or the save threw — value left intact, batch continues.
     *
     * @var string
     */
    public const OUTCOME_FAILED = 'failed';

    /**
     * Constructor.
     *
     * @param AuthenticationConfigAuditor $auditor       The read-only audit (states + Twig refs).
     * @param OrObjectService             $objectService The OpenRegister object service.
     * @param LoggerInterface             $logger        Secret-free logging only.
     */
    public function __construct(
        private readonly AuthenticationConfigAuditor $auditor,
        private readonly OrObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Clear `authenticationConfig` across the fleet — ONLY when explicitly opted in.
     *
     * Per-source isolation: every source is read, decided and saved independently
     * inside its own try/catch, so one failure can neither abort the batch nor leave
     * another source half-written.
     *
     * @param integer $limit Maximum number of sources to inspect.
     * @param boolean $optIn MUST be true. The caller has to say so explicitly.
     *
     * @return array<string, mixed> The per-source outcome report (no values, ever).
     *
     * @throws LogicException When $optIn is not true — the deletion gate.
     *
     * PHPMD's BooleanArgumentFlag rule normally has a point: a boolean argument usually
     * means one method is doing two jobs and should be split. Not here. `$optIn` does not
     * select a BEHAVIOUR — there is only one behaviour, and the flag is the CONSENT to
     * perform it. Splitting it into `removeAll()` / `removeAllWithoutOptIn()` would create
     * exactly the ungated entry point this design exists to prevent, and defaulting it to
     * false is what makes "forgot to opt in" fail loudly instead of deleting credentials.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-removal
     */
    public function removeAll(int $limit=1000, bool $optIn=false): array
    {
        if ($optIn !== true) {
            // GATE (defence in depth). This is deliberately a throw, not a silent
            // no-op: a caller that reached here without opting in is a BUG, and a
            // bug that deletes credentials must be loud.
            throw new LogicException(
                'AuthenticationConfigRemover::removeAll() refuses to run without an explicit opt-in. '
                .'This method DELETES credential data and is reachable only from '
                .'`occ openconnector:authentication-config --remove-authentication-config`.'
            );
        }

        $audit = $this->auditor->auditAll(limit: $limit);

        $sources = [];
        $counts  = [
            self::OUTCOME_REMOVED => 0,
            self::OUTCOME_SKIPPED => 0,
            self::OUTCOME_BLOCKED => 0,
            self::OUTCOME_FAILED  => 0,
        ];

        foreach ((array) ($audit['sources'] ?? []) as $record) {
            $outcome   = $this->removeOne(record: $record);
            $sources[] = $outcome;
            $counts[$outcome['outcome']]++;
        }

        return [
            'field'        => AuthenticationConfigAuditor::FIELD,
            'totalSources' => (int) ($audit['totalSources'] ?? 0),
            'removed'      => $counts[self::OUTCOME_REMOVED],
            'skipped'      => $counts[self::OUTCOME_SKIPPED],
            'blocked'      => $counts[self::OUTCOME_BLOCKED],
            'failed'       => $counts[self::OUTCOME_FAILED],
            'sources'      => $sources,
        ];
    }//end removeAll()

    /**
     * Decide and apply the removal for ONE audited source.
     *
     * @param array<string, mixed> $record One {@see AuthenticationConfigAuditor::auditSource()} record.
     *
     * @return array<string, mixed> The per-source outcome.
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-removal
     */
    private function removeOne(array $record): array
    {
        $uuid = (string) ($record['uuid'] ?? '');
        $name = (string) ($record['name'] ?? '');

        // A live Twig template still reads the field — refuse. Never delete a value
        // something can still resolve.
        if (($record['referenced'] ?? false) === true) {
            return $this->outcome(
                uuid: $uuid,
                name: $name,
                outcome: self::OUTCOME_BLOCKED,
                reason: 'a Twig template in `configuration` references source.authenticationConfig'
            );
        }

        // Unknown state (raw read failed) — fail closed, never blind-write.
        if (($record['state'] ?? '') === AuthenticationConfigAuditor::STATE_UNREADABLE) {
            return $this->outcome(uuid: $uuid, name: $name, outcome: self::OUTCOME_FAILED, reason: 'raw read failed');
        }

        // Idempotency: already clear ⇒ no write at all, so a second run is a true no-op.
        if (($record['state'] ?? '') === AuthenticationConfigAuditor::STATE_CLEAR) {
            return $this->outcome(uuid: $uuid, name: $name, outcome: self::OUTCOME_SKIPPED, reason: 'already clear');
        }

        try {
            $entity = $this->readRawEntity(uuid: $uuid);
            if ($entity instanceof ObjectEntity === false) {
                return $this->outcome(uuid: $uuid, name: $name, outcome: self::OUTCOME_FAILED, reason: 'raw read failed');
            }

            $data = $entity->getObject();
            if (is_array($data) === false) {
                return $this->outcome(uuid: $uuid, name: $name, outcome: self::OUTCOME_FAILED, reason: 'raw read failed');
            }

            // NULL, not unset: OpenRegister's saveObject is PUT-semantic and merges,
            // so an omitted key could silently retain the old plaintext. An explicit
            // null is the only shape that deterministically clears the field.
            $data[AuthenticationConfigAuditor::FIELD] = null;

            $this->objectService->saveObject(
                object: $data,
                register: InlineSecretMigrationPlanner::REGISTER,
                schema: InlineSecretMigrationPlanner::SCHEMA,
                uuid: $uuid,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            // Isolation: the value is still persisted intact, the batch carries on.
            // Secret-free: uuid + exception CLASS only, never the message (an upstream
            // driver may interpolate the offending row into it).
            $this->logger->warning(
                '[openconnector] authenticationConfig removal: source left untouched',
                ['uuid' => $uuid, 'errorClass' => get_class($e)]
            );
            return $this->outcome(uuid: $uuid, name: $name, outcome: self::OUTCOME_FAILED, reason: 'save failed');
        }//end try

        // Secret-free by construction: key names were already reported by the audit.
        $this->logger->info(
            '[openconnector] authenticationConfig removed from source (vestigial field, ocon#232)',
            ['uuid' => $uuid, 'keysCleared' => count((array) ($record['keys'] ?? []))]
        );

        return $this->outcome(
            uuid: $uuid,
            name: $name,
            outcome: self::OUTCOME_REMOVED,
            reason: sprintf('cleared %d key(s)', count((array) ($record['keys'] ?? [])))
        );
    }//end removeOne()

    /**
     * Read a source's raw ENTITY — secrets intact.
     *
     * `_render: false` is LOAD-BEARING; see {@see InlineSecretMigrationPlanner::readRawSource()}.
     * Reading rendered here would hand `saveObject()` a credential-free body and
     * PUT-merge the OTHER four writeOnly secrets away — a data-loss bug far worse
     * than the one this command fixes.
     *
     * @param string $uuid The source uuid.
     *
     * @return ObjectEntity|null The raw entity, or null when unreadable.
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-raw-secret-read
     */
    private function readRawEntity(string $uuid): ?ObjectEntity
    {
        $entity = $this->objectService->find(
            id: $uuid,
            register: InlineSecretMigrationPlanner::REGISTER,
            schema: InlineSecretMigrationPlanner::SCHEMA,
            _rbac: false,
            _multitenancy: false,
            _render: false
        );

        if ($entity instanceof ObjectEntity === false) {
            return null;
        }

        return $entity;
    }//end readRawEntity()

    /**
     * Build a per-source outcome record.
     *
     * @param string $uuid    The source uuid.
     * @param string $name    The source name.
     * @param string $outcome One of the OUTCOME_* constants.
     * @param string $reason  A secret-free explanation.
     *
     * @return array<string, string> The outcome record.
     *
     * @spec exclude Record-shape helper; no domain behavior.
     */
    private function outcome(string $uuid, string $name, string $outcome, string $reason): array
    {
        return [
            'uuid'    => $uuid,
            'name'    => $name,
            'outcome' => $outcome,
            'reason'  => $reason,
        ];
    }//end outcome()
}//end class
