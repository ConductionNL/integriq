<?php

/**
 * Microsoft 365 SaaS-productivity adapter.
 *
 * Reference adapter proving REQ-SPC-001 for the saas-productivity-connectors
 * category.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Adapter\Saas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Adapter\Saas;

use OCA\OpenConnector\Service\Adapter\AbstractCategoryAdapterProvider;
use OCP\IL10N;

/**
 * Reference `saas-productivity-connectors` adapter: Microsoft 365, via
 * Microsoft Graph's `me` calendar/mail surface.
 *
 * Scoped, per this change's proposal, to calendar + mail METADATA read
 * (`calendar-read`, `mail-metadata-read`) — no send/write capability.
 *
 * Graph endpoints called (via {@see brokeredRequest()}):
 *   `GET /v1.0/me/events`
 *   `GET /v1.0/me/messages?$select=id,subject,from,receivedDateTime,hasAttachments`
 *
 * The `$select` restricting the mail read to metadata fields is deliberate —
 * this adapter never reads message bodies.
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
 */
class Microsoft365Adapter extends AbstractCategoryAdapterProvider
{

    /**
     * Graph `$select` fields for the mail-metadata-only read.
     *
     * @var string
     */
    private const MAIL_METADATA_SELECT = 'id,subject,from,receivedDateTime,hasAttachments';

    /**
     * Constructor.
     *
     * @param \OCA\OpenRegister\Service\Credential\CredentialBrokerService $credentialBroker OR's credential broker.
     * @param \OCP\IAppConfig                                              $appConfig        App config.
     * @param \Psr\Log\LoggerInterface                                     $logger           Logger.
     * @param IL10N                                                        $l10n             Translator for labels.
     */
    public function __construct(
        \OCA\OpenRegister\Service\Credential\CredentialBrokerService $credentialBroker,
        \OCP\IAppConfig $appConfig,
        \Psr\Log\LoggerInterface $logger,
        private readonly IL10N $l10n,
    ) {
        parent::__construct(credentialBroker: $credentialBroker, appConfig: $appConfig, logger: $logger);

    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
     */
    public function getId(): string
    {
        return 'microsoft-365';

    }//end getId()

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
     */
    public function getLabel(): string
    {
        return $this->l10n->t('Microsoft 365');

    }//end getLabel()

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
     */
    public function getIcon(): string
    {
        return 'Microsoft';

    }//end getIcon()

    /**
     * {@inheritDoc}
     *
     * @return string|null
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
     */
    public function getRequiredApp(): ?string
    {
        return null;

    }//end getRequiredApp()

    /**
     * {@inheritDoc}
     *
     * @return array<int,string>
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
     */
    public function getCapabilities(): array
    {
        return ['calendar-read', 'mail-metadata-read'];

    }//end getCapabilities()

    /**
     * List the signed-in user's calendar events.
     *
     * @return array<int,array<string,mixed>> Normalised event summaries.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
     */
    public function listCalendarEvents(): array
    {
        $response = $this->brokeredRequest(method: 'GET', path: '/v1.0/me/events');
        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return [];
        }

        $decoded = json_decode($response['body'], true);
        if (is_array($decoded) === false || isset($decoded['value']) === false || is_array($decoded['value']) === false) {
            return [];
        }

        return array_map(
            static function (array $event): array {
                return [
                    'id'        => ($event['id'] ?? null),
                    'subject'   => ($event['subject'] ?? null),
                    'start'     => ($event['start']['dateTime'] ?? null),
                    'end'       => ($event['end']['dateTime'] ?? null),
                    'organizer' => ($event['organizer']['emailAddress']['address'] ?? null),
                ];
            },
            $decoded['value']
        );

    }//end listCalendarEvents()

    /**
     * List the signed-in user's mail METADATA (never message bodies).
     *
     * @return array<int,array<string,mixed>> Normalised mail-metadata rows.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
     */
    public function listMailMetadata(): array
    {
        $path     = '/v1.0/me/messages?$select='.self::MAIL_METADATA_SELECT;
        $response = $this->brokeredRequest(method: 'GET', path: $path);
        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return [];
        }

        $decoded = json_decode($response['body'], true);
        if (is_array($decoded) === false || isset($decoded['value']) === false || is_array($decoded['value']) === false) {
            return [];
        }

        return array_map(
            static function (array $message): array {
                return [
                    'id'               => ($message['id'] ?? null),
                    'subject'          => ($message['subject'] ?? null),
                    'from'             => ($message['from']['emailAddress']['address'] ?? null),
                    'receivedDateTime' => ($message['receivedDateTime'] ?? null),
                    'hasAttachments'   => ($message['hasAttachments'] ?? false),
                ];
            },
            $decoded['value']
        );

    }//end listMailMetadata()

    /**
     * {@inheritDoc}
     *
     * `register`/`schema`/`objectId` are ignored — this adapter is
     * instance-scoped (the signed-in user's own calendar/mail, per the
     * credential's owning user). `$filters['resource'] === 'mail'` switches
     * from calendar (default) to mail-metadata.
     *
     * @param string              $register Ignored (instance-scoped adapter).
     * @param string              $schema   Ignored (instance-scoped adapter).
     * @param string              $objectId Ignored (instance-scoped adapter).
     * @param array<string,mixed> $filters  `resource` (`'calendar'` default, or `'mail'`).
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId are mandated by
     *   IntegrationProvider but this adapter is instance-scoped, not object-scoped.
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if (($filters['resource'] ?? 'calendar') === 'mail') {
            return $this->listMailMetadata();
        }

        return $this->listCalendarEvents();

    }//end list()
}//end class
