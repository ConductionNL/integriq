<?php

/**
 * S3-compatible object-storage data-infra adapter.
 *
 * Reference adapter proving REQ-DIC-001 for the data-infra-connectors
 * category.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Adapter\DataInfra
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Adapter\DataInfra;

use OCA\OpenConnector\Service\Adapter\AbstractCategoryAdapterProvider;
use OCA\OpenConnector\Util\SafeXmlParser;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Reference `data-infra-connectors` adapter: an S3-compatible object-storage
 * bucket, addressed via path-style HTTP requests
 * (`{method} /{bucket}[/{key}]`).
 *
 * Capability: `object-read`, `object-write`, `object-list`.
 *
 * KNOWN LIMITATION (documented per the "don't silently patch a spec/reality
 * mismatch" rule, not silently worked around): OR's
 * `CredentialBrokerService::injectAuth()` only supports a single templated
 * header (`{header: 'Authorization', template: 'Bearer {secret}'}` style) —
 * it does NOT compute an AWS Signature Version 4 canonical-request signature,
 * which is what native AWS S3 requires for every request (a signature over
 * method + path + headers + payload hash + date, computed with the secret
 * key — fundamentally incompatible with "inject a static header value").
 * This adapter therefore targets S3-COMPATIBLE endpoints that accept simple
 * bearer/API-key auth in front of the bucket API (several self-hosted
 * S3-compatible gateways support this), NOT unmodified AWS S3. Wiring true
 * SigV4 support would require either (a) computing the signature INSIDE
 * `CredentialBrokerService` (a broker capability that does not exist today)
 * or (b) a new broker `authScheme: 'aws-sigv4'` that computes the signature
 * from the stored secret before injecting it — both are broker-side changes
 * out of scope for an openconnector-side adapter change. Tracked as a
 * follow-up, not implemented here.
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
 */
class S3Adapter extends AbstractCategoryAdapterProvider
{
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
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     */
    public function getId(): string
    {
        return 'data-infra-s3';

    }//end getId()

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     */
    public function getLabel(): string
    {
        return $this->l10n->t('S3-compatible object storage');

    }//end getLabel()

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     */
    public function getIcon(): string
    {
        return 'Database';

    }//end getIcon()

    /**
     * {@inheritDoc}
     *
     * @return string|null
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
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
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     */
    public function getCapabilities(): array
    {
        return ['object-read', 'object-write', 'object-list'];

    }//end getCapabilities()

    /**
     * List objects in a bucket under an optional key prefix.
     *
     * @param string $bucket Bucket name (the broker's configured credential
     *                       host-locks which endpoint this actually reaches).
     * @param string $prefix Optional key prefix filter.
     *
     * @return array<int,array<string,mixed>> Normalised object summaries.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     */
    public function listObjects(string $bucket, string $prefix=''): array
    {
        $path = sprintf('/%s?list-type=2', rawurlencode($bucket));
        if ($prefix !== '') {
            $path .= '&prefix='.rawurlencode($prefix);
        }

        $response = $this->brokeredRequest(method: 'GET', path: $path);
        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return [];
        }

        return $this->parseListObjectsXml(xml: (string) $response['body']);

    }//end listObjects()

    /**
     * Read an object's raw content.
     *
     * @param string $bucket Bucket name.
     * @param string $key    Object key.
     *
     * @return string|null The raw object bytes, or null when unconfigured/not found/error.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     */
    public function readObject(string $bucket, string $key): ?string
    {
        $path     = sprintf('/%s/%s', rawurlencode($bucket), $this->encodeKey(key: $key));
        $response = $this->brokeredRequest(method: 'GET', path: $path);
        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        return $response['body'];

    }//end readObject()

    /**
     * Write an object's raw content.
     *
     * @param string $bucket  Bucket name.
     * @param string $key     Object key.
     * @param string $content Raw bytes to write.
     *
     * @return array{status: int}|null The upstream status, or null on failure.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     */
    public function writeObject(string $bucket, string $key, string $content): ?array
    {
        $path     = sprintf('/%s/%s', rawurlencode($bucket), $this->encodeKey(key: $key));
        $response = $this->brokeredRequest(method: 'PUT', path: $path, body: $content);
        if ($response === null) {
            return null;
        }

        return ['status' => $response['status']];

    }//end writeObject()

    /**
     * Parse an S3 `ListObjectsV2` XML response into normalised summaries.
     *
     * Dependency-free XML parsing (no external XML library needed for this
     * flat `<Contents>` shape) — matches the pattern already used elsewhere
     * in this app for STAM/StUF XML handling.
     *
     * @param string $xml The raw `ListObjectsV2` XML response body.
     *
     * @return array<int,array<string,mixed>> Normalised object summaries.
     */
    private function parseListObjectsXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            // The listing comes back from a configured S3 endpoint, so it is
            // untrusted input: parse it through SafeXmlParser rather than
            // simplexml_load_string directly.
            $doc = SafeXmlParser::parse($xml);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($doc === false) {
            return [];
        }

        $objects = [];
        // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- External S3 ListObjectsV2 XML element names.
        foreach ($doc->Contents as $entry) {
            $objects[] = [
                // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- External S3 XML element name.
                'key'          => (string) $entry->Key,
                // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- External S3 XML element name.
                'size'         => (int) $entry->Size,
                // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- External S3 XML element name.
                'lastModified' => (string) $entry->LastModified,
                'etag'         => trim((string) $entry->ETag, '"'),
            ];
        }

        return $objects;

    }//end parseListObjectsXml()

    /**
     * Percent-encode an object key for a path-style S3 URL, preserving `/`
     * (S3 keys commonly contain slashes that represent a "folder" prefix and
     * must remain literal path separators, not be escaped to `%2F`).
     *
     * @param string $key The raw object key.
     *
     * @return string The path-encoded key.
     */
    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));

    }//end encodeKey()

    /**
     * {@inheritDoc}
     *
     * `register`/`schema`/`objectId` are ignored — this adapter is
     * instance-scoped; `$filters['bucket']` (required) and
     * `$filters['prefix']` (optional) select what to list.
     *
     * @param string              $register Ignored (instance-scoped adapter).
     * @param string              $schema   Ignored (instance-scoped adapter).
     * @param string              $objectId Ignored (instance-scoped adapter).
     * @param array<string,mixed> $filters  `bucket` (required), `prefix` (optional).
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId are mandated by
     *   IntegrationProvider but this adapter is instance-scoped, not object-scoped.
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if (isset($filters['bucket']) === false) {
            return [];
        }

        return $this->listObjects(
            bucket: (string) $filters['bucket'],
            prefix: (string) ($filters['prefix'] ?? '')
        );

    }//end list()

    /**
     * {@inheritDoc}
     *
     * Reads a single object by key (`$entityId`) from the given bucket
     * register/schema/objectId are ignored, matching {@see list()}.
     *
     * @param string $register Ignored (instance-scoped adapter).
     * @param string $schema   Ignored (instance-scoped adapter).
     * @param string $objectId Ignored (instance-scoped adapter).
     * @param string $entityId Expected shape `"bucket/key"`.
     *
     * @return array<string,mixed>
     *
     * @throws DoesNotExistException When `$entityId` is malformed or the object is not found.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId are mandated by
     *   IntegrationProvider but this adapter is instance-scoped, not object-scoped.
     */
    public function get(string $register, string $schema, string $objectId, string $entityId): array
    {
        // $entityId is expected in "bucket/key" shape for this generic
        // interface method; the richer typed methods (readObject) are
        // preferred for direct programmatic use.
        $parts = explode('/', $entityId, 2);
        if (count($parts) !== 2) {
            throw new DoesNotExistException('Expected entityId shape "bucket/key", got: '.$entityId);
        }

        [$bucket, $key] = $parts;
        $content        = $this->readObject(bucket: $bucket, key: $key);
        if ($content === null) {
            throw new DoesNotExistException(sprintf('Object "%s" not found in bucket "%s".', $key, $bucket));
        }

        return [
            'bucket'  => $bucket,
            'key'     => $key,
            'content' => $content,
        ];

    }//end get()

    /**
     * {@inheritDoc}
     *
     * Writes a new object into a bucket. This is the seam that makes the
     * advertised `object-write` capability reachable: `getCapabilities()` has
     * listed `object-write` since this adapter was scaffolded and
     * {@see writeObject()} has been implemented all along, but neither
     * `create()` nor `update()` was overridden — so every write arriving
     * through the `IntegrationProvider` interface hit
     * `AbstractIntegrationProvider`'s default and threw
     * `NotImplementedException`. The capability was advertised to the admin UI
     * and the OCS capabilities response while being structurally unreachable
     * (openconnector#1191).
     *
     * `register`/`schema`/`objectId` are ignored — this adapter is
     * instance-scoped, matching {@see list()} and {@see get()}.
     *
     * @param string              $register Ignored (instance-scoped adapter).
     * @param string              $schema   Ignored (instance-scoped adapter).
     * @param string              $objectId Ignored (instance-scoped adapter).
     * @param array<string,mixed> $payload  `bucket` (required), `key` (required), `content` (optional, defaults to '').
     *
     * @return array<string,mixed> The written object's `bucket`, `key` and upstream `status`.
     *
     * @throws DoesNotExistException When `bucket` or `key` is missing from the payload.
     * @throws \RuntimeException     When the upstream write fails.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId are mandated by
     *   IntegrationProvider but this adapter is instance-scoped, not object-scoped.
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $bucket = (string) ($payload['bucket'] ?? '');
        $key    = (string) ($payload['key'] ?? '');
        if ($bucket === '' || $key === '') {
            throw new DoesNotExistException(
                'S3 create() requires both "bucket" and "key" in the payload.'
            );
        }

        return $this->putObject(
            bucket: $bucket,
            key: $key,
            content: (string) ($payload['content'] ?? '')
        );

    }//end create()

    /**
     * {@inheritDoc}
     *
     * Overwrites an existing object. S3 has no distinct update verb — a PUT to
     * an existing key replaces it — so this shares {@see putObject()} with
     * {@see create()}. The `$entityId` carries the target in the same
     * `"bucket/key"` shape {@see get()} accepts, so a caller that read an
     * object can write it back without re-deriving the address.
     *
     * @param string              $register Ignored (instance-scoped adapter).
     * @param string              $schema   Ignored (instance-scoped adapter).
     * @param string              $objectId Ignored (instance-scoped adapter).
     * @param string              $entityId Expected shape `"bucket/key"`.
     * @param array<string,mixed> $payload  `content` (optional, defaults to '').
     *
     * @return array<string,mixed> The written object's `bucket`, `key` and upstream `status`.
     *
     * @throws DoesNotExistException When `$entityId` is malformed.
     * @throws \RuntimeException     When the upstream write fails.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId are mandated by
     *   IntegrationProvider but this adapter is instance-scoped, not object-scoped.
     */
    public function update(
        string $register,
        string $schema,
        string $objectId,
        string $entityId,
        array $payload
    ): array {
        $parts = explode('/', $entityId, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new DoesNotExistException('Expected entityId shape "bucket/key", got: '.$entityId);
        }

        [$bucket, $key] = $parts;

        return $this->putObject(
            bucket: $bucket,
            key: $key,
            content: (string) ($payload['content'] ?? '')
        );

    }//end update()

    /**
     * Shared write path for {@see create()} and {@see update()}.
     *
     * Turns {@see writeObject()}'s nullable/status-bearing return into the
     * array-or-throw contract the `IntegrationProvider` interface specifies.
     * A null return means the brokered request never completed (unconfigured
     * credential, host-lock denial, transport error); a non-2xx status means
     * the bucket rejected the write. Both are failures, and neither may be
     * reported as a successful write.
     *
     * @param string $bucket  Bucket name.
     * @param string $key     Object key.
     * @param string $content Raw bytes to write.
     *
     * @return array<string,mixed> The written object's `bucket`, `key` and upstream `status`.
     *
     * @throws \RuntimeException When the write did not complete with a 2xx status.
     *
     * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
     */
    private function putObject(string $bucket, string $key, string $content): array
    {
        $result = $this->writeObject(bucket: $bucket, key: $key, content: $content);
        if ($result === null) {
            throw new \RuntimeException(
                sprintf('S3 write of "%s" to bucket "%s" did not complete.', $key, $bucket)
            );
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new \RuntimeException(
                sprintf(
                    'S3 write of "%s" to bucket "%s" was rejected upstream (status %d).',
                    $key,
                    $bucket,
                    $result['status']
                )
            );
        }

        return [
            'bucket' => $bucket,
            'key'    => $key,
            'status' => $result['status'],
        ];

    }//end putObject()
}//end class
