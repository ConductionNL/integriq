<?php

/**
 * Shared base for connector-category vendor adapters.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Adapter;

use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Common registration + credential-broker scaffolding for every
 * "connector-category" adapter (endpoint/workspace, document/CMS, SaaS
 * productivity, data-infra — see the four `*-connectors` specs).
 *
 * A concrete adapter (e.g. `EndpointWorkspace\AzureVirtualDesktopAdapter`)
 * only needs to:
 *   - implement the `IntegrationProvider` metadata methods (getId, getLabel,
 *     getIcon, getRequiredApp) per its vendor,
 *   - declare its fixed capability vocabulary via {@see getCapabilities()},
 *   - call {@see brokeredRequest()} for every outbound call to the vendor
 *     API, so credentials NEVER live in the adapter — only a `credential`
 *     object UUID (resolved from app config) is known here, and the actual
 *     secret is injected by OR's {@see CredentialBrokerService} per
 *     `project_credential-broker`.
 *
 * Storage strategy: every category adapter is `'query-time'` — these are
 * live external systems, not something openconnector persists a local copy
 * of. `list()`/`get()` therefore proxy directly to the vendor via the
 * broker on every call; `create()`/`update()`/`delete()` are NOT overridden
 * here so the `AbstractIntegrationProvider` default (`NotImplementedException`)
 * applies unless a concrete adapter's vendor API genuinely supports writes
 * (the S3 adapter is the one category adapter that does, and overrides
 * accordingly).
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-1
 */
abstract class AbstractCategoryAdapterProvider extends AbstractIntegrationProvider
{
    /**
     * Constructor.
     *
     * @param CredentialBrokerService $credentialBroker OR's constrained outbound credential broker.
     * @param IAppConfig              $appConfig        App config — resolves the configured `credential` UUID.
     * @param LoggerInterface         $logger           Logger for secret-free diagnostics.
     */
    public function __construct(
        protected readonly CredentialBrokerService $credentialBroker,
        protected readonly IAppConfig $appConfig,
        protected readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Fixed capability vocabulary this adapter's category requires.
     *
     * Each category spec (REQ-EWC-002, REQ-DCC-001, REQ-SPC-001,
     * REQ-DIC-001) fixes a vocabulary of capability slugs (e.g.
     * `session-enumeration`, `document-fetch`, `calendar-read`,
     * `object-read`/`object-write`/`object-list`). Concrete adapters return
     * the slugs they actually implement so the admin UI / OCS capabilities
     * response can show what a given vendor integration supports without
     * calling every method speculatively.
     *
     * @return array<int,string> Capability slugs.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-1
     */
    abstract public function getCapabilities(): array;

    /**
     * App-config key holding this adapter's configured `credential` UUID.
     *
     * Convention: `<id>_credential_id`, e.g. `azure-virtual-desktop_credential_id`.
     * Kept as a single method so every adapter resolves its credential the
     * same way and a future admin-settings UI has one place to write to.
     *
     * @return string The app-config key.
     *
     * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    protected function credentialConfigKey(): string
    {
        return $this->getId().'_credential_id';

    }//end credentialConfigKey()

    /**
     * The configured `credential` object UUID for this adapter, or null when
     * the admin has not yet configured one.
     *
     * @return string|null The credential UUID.
     *
     * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    protected function getCredentialId(): ?string
    {
        $value = $this->appConfig->getValueString(
            'openconnector',
            $this->credentialConfigKey(),
            ''
        );

        if ($value === '') {
            return null;
        }

        return $value;

    }//end getCredentialId()

    /**
     * Perform a constrained, secret-free outbound call through OR's credential broker.
     *
     * The adapter NEVER sees the vendor secret — only the `credential` UUID
     * (resolved from app config) and a relative path/method are supplied;
     * {@see CredentialBrokerService::request()} enforces its four guards
     * (owner, allowed-app, allow-rules, host-lock) and injects the secret
     * per the credential's configured auth scheme.
     *
     * @param string                $method  HTTP method (e.g. `GET`).
     * @param string                $path    Vendor-relative path (never a full URL — the broker host-locks).
     * @param array<string, string> $headers Optional extra request headers.
     * @param string|null           $body    Optional raw request body.
     *
     * @return array{status: int, headers: array<string, mixed>, body: string}|null The
     *         upstream response, or null when no credential is configured yet
     *         (callers treat this as "integration not configured", not an error).
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-1
     */
    protected function brokeredRequest(string $method, string $path, array $headers=[], ?string $body=null): ?array
    {
        $credentialId = $this->getCredentialId();
        if ($credentialId === null) {
            return null;
        }

        try {
            return $this->credentialBroker->request(
                credentialId: $credentialId,
                appId: 'openconnector',
                method: $method,
                path: $path,
                headers: $headers,
                body: $body
            );
        } catch (CredentialAccessDeniedException | CredentialUpstreamException $e) {
            $this->logger->warning(
                sprintf('%s: brokered request failed — %s', $this->getId(), $e->getMessage()),
                ['adapter' => $this->getId()]
            );
            return null;
        }

    }//end brokeredRequest()

    /**
     * Storage strategy — every category adapter is a live, query-time proxy.
     *
     * @return string Always `'query-time'`.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-1
     */
    public function getStorageStrategy(): string
    {
        return 'query-time';

    }//end getStorageStrategy()

    /**
     * Credential requirement descriptor.
     *
     * Every category adapter authenticates via an OR-brokered credential
     * (never a hardcoded secret); concrete adapters MAY override with a
     * more specific `configSchema` describing their vendor's exact auth
     * shape.
     *
     * @return array<string,mixed> Auth-requirements descriptor.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-1
     */
    public function authRequirements(): array
    {
        return [
            'type'         => 'credential-broker',
            'configSchema' => ['credentialId' => 'string (OR credential-broker object UUID)'],
        ];

    }//end authRequirements()

    /**
     * Whether the integration is currently usable on this instance.
     *
     * Default: enabled once an admin has configured a `credential` UUID.
     * Concrete adapters MAY override to add an installed-app check via
     * {@see getRequiredApp()} first (the base `IntegrationRegistry` already
     * gates on that separately, per AD-5, so this default intentionally
     * only adds the credential-configured condition on top).
     *
     * @return bool True once a credential UUID is configured.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-1
     */
    public function isEnabled(): bool
    {
        return $this->getCredentialId() !== null;

    }//end isEnabled()

    /**
     * Health descriptor — unconfigured until an admin sets a credential UUID.
     *
     * Concrete adapters override to add a real upstream reachability probe
     * once configured (e.g. a lightweight `GET` against a vendor health/me
     * endpoint via {@see brokeredRequest()}).
     *
     * @return array<string,mixed> Health + auth descriptor.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-1
     */
    public function health(): array
    {
        if ($this->getCredentialId() === null) {
            return [
                'status'     => 'unavailable',
                'authStatus' => 'missing',
                'message'    => sprintf(
                    'No credential configured for %s (set app config key "%s" to a credential-broker object UUID).',
                    $this->getId(),
                    $this->credentialConfigKey()
                ),
            ];
        }

        return [
            'status'     => 'ok',
            'authStatus' => 'configured',
            'message'    => null,
        ];

    }//end health()
}//end class
