<?php
/**
 * OpenConnector EudiStatusListService.
 *
 * Owns the OAuth Status List Token (draft-ietf-oauth-status-list) lifecycle
 * for the EUDI wallet credential issuance adapter: bit assignment, bit
 * flips (revocation), and building/signing the published token. Single-bit
 * (`bits: 1`, `purpose: revocation`) only — see design.md D-REVOKE.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-publishes-single-bit-revocation-only-req-eudi-008
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Assigns status-list bit indices, flips bits on revocation, and
 * builds/signs the published OAuth Status List Token.
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-publishes-single-bit-revocation-only-req-eudi-008
 */
class EudiStatusListService
{

    /**
     * Default number of assignable bit indices per status list.
     *
     * @var integer
     */
    private const DEFAULT_CAPACITY = 100000;

    /**
     * Total validity window (seconds) of a signed status list token (24h).
     *
     * @var integer
     */
    public const TOKEN_TTL_SECONDS = 86400;

    /**
     * Refresh a token once less than this fraction of its total validity
     * window remains (REQ-EUDI-008b: default 25%).
     *
     * @var float
     */
    public const REFRESH_THRESHOLD_FRACTION = 0.25;

    /**
     * Constructor.
     *
     * @param OrObjectService      $orObjectService OR ObjectService used to read/write status-list rows.
     * @param EudiIssuerKeyService $keyService      Signs the published token with the issuer's active key.
     * @param LoggerInterface      $logger          Logger for refresh/rotation outcomes.
     */
    public function __construct(
        private readonly OrObjectService $orObjectService,
        private readonly EudiIssuerKeyService $keyService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Find (or lazily create) the single status list for an organisation.
     *
     * @param string|null $organisationId The organisation uuid, or null for the default scope.
     *
     * @return ObjectEntity
     */
    private function findOrCreateStatusList(?string $organisationId): ObjectEntity
    {
        $scope   = ($organisationId ?? EudiIssuerKeyService::DEFAULT_ORGANISATION_SCOPE);
        $matches = $this->orObjectService->findAll(
            config: [
                'filters' => [
                    'register'       => 'openconnector',
                    'schema'         => 'eudi_status_list',
                    'organisationId' => $scope,
                ],
            ],
            _rbac: false,
            _multitenancy: false
        );
        $results = ($matches['results'] ?? $matches);

        if (count($results) > 0) {
            return $results[0];
        }

        $uuid = self::generateUuid();
        $data = [
            'organisationId' => $scope,
            'purpose'        => 'revocation',
            'bits'           => 1,
            'capacity'       => self::DEFAULT_CAPACITY,
            'nextFreeIndex'  => 0,
            'bitstring'      => [],
        ];

        return $this->orObjectService->saveObject(
            object: $data,
            register: 'openconnector',
            schema: 'eudi_status_list',
            uuid: $uuid
        );

    }//end findOrCreateStatusList()

    /**
     * Generate a UUID v4 (no external dependency required).
     *
     * @return string
     */
    private static function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    }//end generateUuid()

    /**
     * Assign the next free bit index for a new credential offer.
     *
     * @param string|null $organisationId The organisation uuid, or null for the default scope.
     *
     * @return array{statusListId: string, index: integer}
     *
     * @throws RuntimeException When the status list has exhausted its capacity.
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-adapter-ships-as-a-catalogue-entry-backed-by-three-register-fragment-schemas-req-eudi-001
     */
    public function assignIndex(?string $organisationId): array
    {
        $entity = $this->findOrCreateStatusList(organisationId: $organisationId);
        $data   = $entity->getObject();

        $index    = (int) ($data['nextFreeIndex'] ?? 0);
        $capacity = (int) ($data['capacity'] ?? self::DEFAULT_CAPACITY);
        if ($index >= $capacity) {
            throw new RuntimeException('EUDI status list capacity exhausted for this organisation');
        }

        $bitstring         = ($data['bitstring'] ?? []);
        $bitstring[$index] = 0;
        $data['bitstring'] = $bitstring;
        $data['nextFreeIndex'] = ($index + 1);

        $this->orObjectService->saveObject(
            object: $data,
            register: 'openconnector',
            schema: 'eudi_status_list',
            uuid: $entity->getUuid()
        );

        return ['statusListId' => $entity->getUuid(), 'index' => $index];

    }//end assignIndex()

    /**
     * Flip a credential's status-list bit to revoked.
     *
     * Idempotent — flipping an already-revoked bit is a no-op success, not
     * an error (REQ-EUDI-009).
     *
     * @param string  $statusListId The status list row uuid.
     * @param integer $index        The credential's assigned bit index.
     *
     * @return boolean True when the bit was actually flipped (false when it was already 1).
     *
     * @throws DoesNotExistException When the status list row does not exist.
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-revocation-flips-one-status-list-bit-and-fires-a-signed-callback-req-eudi-009
     */
    public function revokeIndex(string $statusListId, int $index): bool
    {
        $entity = $this->orObjectService->find(
            id: $statusListId,
            register: 'openconnector',
            schema: 'eudi_status_list',
            _rbac: false,
            _multitenancy: false
        );
        $data   = $entity->getObject();

        $bitstring = ($data['bitstring'] ?? []);
        if ((int) ($bitstring[$index] ?? 0) === 1) {
            // Already revoked — idempotent no-op.
            return false;
        }

        $bitstring[$index] = 1;
        $data['bitstring'] = $bitstring;

        $this->orObjectService->saveObject(
            object: $data,
            register: 'openconnector',
            schema: 'eudi_status_list',
            uuid: $entity->getUuid()
        );

        // Re-sign immediately so a verifier fetching the endpoint right
        // after revocation observes the new bit without waiting for the
        // background refresh window.
        $refreshedEntity = $this->orObjectService->find(
            id: $statusListId,
            register: 'openconnector',
            schema: 'eudi_status_list',
            _rbac: false,
            _multitenancy: false
        );
        $this->signAndCache(entity: $refreshedEntity);

        return true;

    }//end revokeIndex()

    /**
     * Encode a bit array into the draft's compressed `lst` byte-string
     * (DEFLATE-compressed packed bits, base64url-encoded, no padding).
     *
     * @param array $bitstring Sparse/dense array of 0/1 values indexed by bit position.
     * @param int   $bits      Bits per entry (fixed at 1 in this change).
     *
     * @return string The base64url `lst` value.
     */
    private static function encodeStatusList(array $bitstring, int $bits=1): string
    {
        $highestIndex = 0;
        foreach (array_keys($bitstring) as $index) {
            $highestIndex = max($highestIndex, (int) $index);
        }

        $totalBits  = (($highestIndex + 1) * $bits);
        $totalBytes = (int) ceil($totalBits / 8);
        $bytes      = str_repeat("\0", max($totalBytes, 1));

        foreach ($bitstring as $index => $value) {
            if ((int) $value !== 1) {
                continue;
            }

            $bitPosition = ((int) $index * $bits);
            $byteIndex   = intdiv($bitPosition, 8);
            $bitOffset   = ($bitPosition % 8);
            $byte        = ord($bytes[$byteIndex]);
            $byte       |= (1 << $bitOffset);
            $bytes[$byteIndex] = chr($byte);
        }

        $compressed = gzdeflate($bytes);

        return rtrim(strtr(base64_encode($compressed), '+/', '-_'), '=');

    }//end encodeStatusList()

    /**
     * Decode a draft `lst` byte-string back into a bit array. Test/verification helper.
     *
     * @param string $lst  The base64url `lst` value.
     * @param int    $bits Bits per entry.
     *
     * @return array<int, int> Bit values indexed by position.
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-publishes-single-bit-revocation-only-req-eudi-008
     */
    public static function decodeStatusList(string $lst, int $bits=1): array
    {
        $padded     = str_pad($lst, ((int) ceil(strlen($lst) / 4) * 4), '=');
        $bytes      = base64_decode(strtr($padded, '-_', '+/'));
        $compressed = gzinflate($bytes);

        $result = [];
        $length = strlen($compressed);
        for ($bytePos = 0; $bytePos < $length; $bytePos++) {
            $byte = ord($compressed[$bytePos]);
            for ($bitOffset = 0; $bitOffset < 8; $bitOffset++) {
                $index = (((($bytePos * 8) + $bitOffset)) / $bits);
                $result[(int) $index] = (($byte >> $bitOffset) & 1);
            }
        }

        return $result;

    }//end decodeStatusList()

    /**
     * Build and sign a fresh OAuth Status List Token for a status list row,
     * caching the result on the row.
     *
     * @param ObjectEntity $entity The status list row.
     *
     * @return string The signed token.
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-publishes-single-bit-revocation-only-req-eudi-008
     */
    public function signAndCache(ObjectEntity $entity): string
    {
        $data = $entity->getObject();

        $bits = (int) ($data['bits'] ?? 1);
        $lst  = self::encodeStatusList(bitstring: ($data['bitstring'] ?? []), bits: $bits);

        $now = new DateTime();
        $exp = (clone $now)->modify('+'.self::TOKEN_TTL_SECONDS.' seconds');

        $organisationId = ($data['organisationId'] ?? null);
        if ($organisationId === EudiIssuerKeyService::DEFAULT_ORGANISATION_SCOPE) {
            $organisationId = null;
        }

        $payload = [
            'iss'         => 'openconnector',
            'sub'         => $entity->getUuid(),
            'iat'         => $now->getTimestamp(),
            'exp'         => $exp->getTimestamp(),
            'status_list' => ['bits' => $bits, 'lst' => $lst],
        ];

        $token = $this->keyService->signJwt(
            organisationId: $organisationId,
            payload: $payload,
            extraHeaderClaims: ['typ' => 'statuslist+jwt']
        );

        $activeKey = $this->keyService->resolveActiveKey($organisationId);

        $data['currentToken']    = $token;
        $data['currentTokenExp'] = $exp->format('c');
        $data['issuerKid']       = $activeKey['kid'];
        $data['refreshedAt']     = $now->format('c');

        $this->orObjectService->saveObject(
            object: $data,
            register: 'openconnector',
            schema: 'eudi_status_list',
            uuid: $entity->getUuid()
        );

        return $token;

    }//end signAndCache()

    /**
     * Get the published token for a status list, signing a fresh one if
     * none is cached yet.
     *
     * @param string $statusListId The status list row uuid.
     *
     * @return string|null The signed token, or null when the row does not exist.
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-publishes-single-bit-revocation-only-req-eudi-008
     */
    public function getPublishedToken(string $statusListId): ?string
    {
        try {
            $entity = $this->orObjectService->find(
                id: $statusListId,
                register: 'openconnector',
                schema: 'eudi_status_list',
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $exception) {
            return null;
        }

        $data = $entity->getObject();
        if (empty($data['currentToken'] ?? '') === true) {
            return $this->signAndCache(entity: $entity);
        }

        return $data['currentToken'];

    }//end getPublishedToken()

    /**
     * Sweep every status list row and re-sign any token nearing its own
     * expiry (REQ-EUDI-008b). Called by {@see \OCA\OpenConnector\Cron\EudiStatusListRefreshJob}.
     *
     * @return integer Number of tokens refreshed.
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-refresh-keeps-the-published-token-ahead-of-its-own-expiry-req-eudi-008b
     */
    public function refreshNearExpiry(): int
    {
        $matches = $this->orObjectService->findAll(
            config: [
                'filters' => [
                    'register' => 'openconnector',
                    'schema'   => 'eudi_status_list',
                ],
            ],
            _rbac: false,
            _multitenancy: false
        );
        $results = ($matches['results'] ?? $matches);

        $now = new DateTime();
        $refreshThreshold = (self::TOKEN_TTL_SECONDS * self::REFRESH_THRESHOLD_FRACTION);
        $refreshed        = 0;

        foreach ($results as $entity) {
            $data = $entity->getObject();
            $exp  = ($data['currentTokenExp'] ?? null);

            $needsRefresh = true;
            if (empty($exp) === false) {
                $expDate          = new DateTime($exp);
                $remainingSeconds = ($expDate->getTimestamp() - $now->getTimestamp());
                $needsRefresh     = ($remainingSeconds < $refreshThreshold);
            }

            if ($needsRefresh === true) {
                $this->signAndCache(entity: $entity);
                $refreshed++;
            }
        }//end foreach

        if ($refreshed > 0) {
            $this->logger->info('EudiStatusListService: refresh sweep complete', ['refreshed' => $refreshed]);
        }

        return $refreshed;

    }//end refreshNearExpiry()
}//end class
