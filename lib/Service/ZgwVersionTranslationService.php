<?php

/**
 * OpenConnector ZGW Version Translation Service.
 *
 * Core of the zgw-version-translation change: resolves the resource
 * translator by slug, short-circuits to passthrough when `fromVersion ===
 * toVersion`, invokes the correct translator direction, and persists a
 * `zgw_version_translation_log` record for every attempt — mirrors
 * {@see FscCallService} (resolve-then-dispatch + per-attempt persistence).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateTime;
use OCA\OpenConnector\Exception\ZgwUnknownResourceException;
use OCA\OpenConnector\Exception\ZgwVersionTranslationException;
use OCA\OpenConnector\Service\ZgwVersion\BesluitTranslator;
use OCA\OpenConnector\Service\ZgwVersion\InformatieObjectTranslator;
use OCA\OpenConnector\Service\ZgwVersion\ResultaatTranslator;
use OCA\OpenConnector\Service\ZgwVersion\RolTranslator;
use OCA\OpenConnector\Service\ZgwVersion\StatusTranslator;
use OCA\OpenConnector\Service\ZgwVersion\ZaakTranslator;
use OCA\OpenConnector\Service\ZgwVersion\ZaakTypeTranslator;
use OCA\OpenConnector\Service\ZgwVersion\ZgwResourceTranslatorInterface;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * Orchestrates resource+version resolution, translator dispatch, and
 * translation-log persistence.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.LongVariable)
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md
 */
class ZgwVersionTranslationService
{

    /**
     * OpenRegister register slug holding the translation log.
     *
     * @var string
     */
    public const REGISTER = 'openconnector';

    /**
     * OR schema slug for a `zgw_version_translation_log` record.
     *
     * @var string
     */
    public const SCHEMA_LOG = 'zgw_version_translation_log';

    /**
     * Resolved translators keyed by resource slug.
     *
     * @var array<string, ZgwResourceTranslatorInterface>
     */
    private array $translators;

    /**
     * Constructor.
     *
     * @param ZgwVersionNegotiationService $negotiationService         The version negotiation service.
     * @param ORObjectService              $objectService              OR object service for log persistence.
     * @param ZaakTranslator               $zaakTranslator             The `zaak` translator.
     * @param ZaakTypeTranslator           $zaakTypeTranslator         The `zaaktype` translator.
     * @param InformatieObjectTranslator   $informatieObjectTranslator The `enkelvoudiginformatieobject` translator.
     * @param BesluitTranslator            $besluitTranslator          The `besluit` translator.
     * @param RolTranslator                $rolTranslator              The `rol` translator.
     * @param StatusTranslator             $statusTranslator           The `status` translator.
     * @param ResultaatTranslator          $resultaatTranslator        The `resultaat` translator.
     */
    public function __construct(
        private readonly ZgwVersionNegotiationService $negotiationService,
        private readonly ORObjectService $objectService,
        ZaakTranslator $zaakTranslator,
        ZaakTypeTranslator $zaakTypeTranslator,
        InformatieObjectTranslator $informatieObjectTranslator,
        BesluitTranslator $besluitTranslator,
        RolTranslator $rolTranslator,
        StatusTranslator $statusTranslator,
        ResultaatTranslator $resultaatTranslator,
    ) {
        $this->translators = [];
        foreach ([
            $zaakTranslator,
            $zaakTypeTranslator,
            $informatieObjectTranslator,
            $besluitTranslator,
            $rolTranslator,
            $statusTranslator,
            $resultaatTranslator,
        ] as $translator
        ) {
            $this->translators[$translator->getResource()] = $translator;
        }

    }//end __construct()

    /**
     * The resource slugs this service can translate.
     *
     * @return string[]
     *
     * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
     */
    public function getSupportedResources(): array
    {
        return array_keys($this->translators);

    }//end getSupportedResources()

    /**
     * Translate one resource payload from `$fromVersion` to `$toVersion`.
     *
     * @param string               $resource    The ZGW resource slug.
     * @param string               $fromVersion The source version.
     * @param string               $toVersion   The target version.
     * @param array<string, mixed> $payload     The payload to translate.
     *
     * @return array<string, mixed> The translated (or, on passthrough, unchanged) payload.
     *
     * @throws ZgwUnknownResourceException     When `$resource` has no registered translator.
     * @throws ZgwVersionTranslationException  When negotiation or translation fails
     *                                         (a `status: failed` log record IS persisted first).
     *
     * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-persistence-and-observability-zgw_version_translation_log-req-004
     */
    public function translate(string $resource, string $fromVersion, string $toVersion, array $payload): array
    {
        $this->negotiationService->assertKnownVersion(version: $fromVersion);
        $this->negotiationService->assertKnownVersion(version: $toVersion);

        if (isset($this->translators[$resource]) === false) {
            throw new ZgwUnknownResourceException(
                message: 'Unknown ZGW resource "'.$resource.'" — supported resources are: '
                    .implode(', ', $this->getSupportedResources()).'.'
            );
        }

        if ($fromVersion === $toVersion) {
            $this->persistLog(resource: $resource, fromVersion: $fromVersion, toVersion: $toVersion, status: 'passthrough', error: null);

            return $payload;
        }

        $this->negotiationService->assertImplementedVersion(version: $fromVersion);
        $this->negotiationService->assertImplementedVersion(version: $toVersion);

        $translator = $this->translators[$resource];

        try {
            $translated = $this->dispatch(translator: $translator, fromVersion: $fromVersion, toVersion: $toVersion, payload: $payload);
        } catch (ZgwVersionTranslationException $exception) {
            $this->persistLog(
                resource: $resource,
                fromVersion: $fromVersion,
                toVersion: $toVersion,
                status: 'failed',
                error: $exception->getMessage()
            );
            throw $exception;
        }

        $this->persistLog(resource: $resource, fromVersion: $fromVersion, toVersion: $toVersion, status: 'success', error: null);

        return $translated;

    }//end translate()

    /**
     * Invoke the correct translator direction for a `1.0`↔`1.6` pair.
     *
     * @param ZgwResourceTranslatorInterface $translator  The resolved translator.
     * @param string                         $fromVersion The source version.
     * @param string                         $toVersion   The target version.
     * @param array<string, mixed>           $payload     The payload to translate.
     *
     * @return array<string, mixed> The translated payload.
     *
     * @throws ZgwVersionTranslationException When `$fromVersion`/`$toVersion` is not a
     *                                        supported `1.0`↔`1.6` pair (should be
     *                                        unreachable once both are asserted
     *                                        implemented, kept as a defensive guard).
     */
    private function dispatch(
        ZgwResourceTranslatorInterface $translator,
        string $fromVersion,
        string $toVersion,
        array $payload
    ): array {
        if ($fromVersion === ZgwVersionNegotiationService::VERSION_CANONICAL
            && $toVersion === ZgwVersionNegotiationService::VERSION_STABILITY
        ) {
            return $translator->translateToV16(payload: $payload);
        }

        if ($fromVersion === ZgwVersionNegotiationService::VERSION_STABILITY
            && $toVersion === ZgwVersionNegotiationService::VERSION_CANONICAL
        ) {
            return $translator->translateToV1x(payload: $payload);
        }

        throw new ZgwVersionTranslationException(
            message: 'Unsupported translation direction "'.$fromVersion.'" -> "'.$toVersion.'".'
        );

    }//end dispatch()

    /**
     * Persist one `zgw_version_translation_log` record.
     *
     * @param string      $resource    The ZGW resource slug.
     * @param string      $fromVersion The source version.
     * @param string      $toVersion   The target version.
     * @param string      $status      One of `success`, `passthrough`, `failed`.
     * @param string|null $error       The failure detail, when `$status === 'failed'`.
     *
     * @return void
     *
     * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-persistence-and-observability-zgw_version_translation_log-req-004
     */
    private function persistLog(string $resource, string $fromVersion, string $toVersion, string $status, ?string $error): void
    {
        $this->objectService->saveObject(
            object: [
                'resource'     => $resource,
                'fromVersion'  => $fromVersion,
                'toVersion'    => $toVersion,
                'status'       => $status,
                'error'        => $error,
                'translatedAt' => (new DateTime())->format('c'),
            ],
            register: self::REGISTER,
            schema: self::SCHEMA_LOG
        );

    }//end persistLog()
}//end class
