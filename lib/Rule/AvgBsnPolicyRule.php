<?php
/**
 * AVG BSN policy rule.
 *
 * Generic gateway mechanic added by the vng-klantinteracties-adapter change:
 * on inbound `partijIdentificator` payloads carrying a BSN (Dutch citizen
 * service number), validate the number (11-proef) and SHA-256-hash it before
 * any storage; on outbound rendering, strip any value that still looks like a
 * raw BSN so a raw citizen service number is never reconstructed or exposed.
 * This is a documented, intentional deviation from VNG's raw-BSN expectation
 * (see design.md D5 / proposal.md Risk 1).
 *
 * Full BSN verification against the Dutch BRP (Basisregistratie Personen) is
 * a pipelinq-side (leaf change) concern; this producer-side Rule performs the
 * 11-proef checksum + hashing that is dialect-agnostic gateway policy and does
 * not require a BRP lookup.
 *
 * @category Rule
 * @package  OCA\OpenConnector\Rule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Rule;

use Adbar\Dot;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * AVG BSN policy rule handler.
 *
 * @spec openspec/specs/vng-klantinteracties-adapter/spec.md
 */
class AvgBsnPolicyRule
{

    /**
     * Default dot-path (within the rule's data envelope) at which the
     * `partijIdentificator`-shaped object is found.
     *
     * @var string
     */
    private const DEFAULT_PATH = 'body.partijIdentificator';

    /**
     * Default field name carrying the identity type code (e.g. `bsn`).
     *
     * @var string
     */
    private const DEFAULT_CODE_FIELD = 'codeSoortObjectId';

    /**
     * Default field name carrying the identity value (raw BSN inbound, hash outbound).
     *
     * @var string
     */
    private const DEFAULT_VALUE_FIELD = 'objectId';

    /**
     * Apply the AVG BSN policy for the given pipeline timing.
     *
     * "before": validates (11-proef) and SHA-256-hashes an inbound BSN value
     * before it reaches storage; an invalid BSN throws (rejecting the write).
     * "after": defence-in-depth — if the value at the configured path still
     * looks like a raw, valid BSN (i.e. it was never hashed, for instance
     * because a mapping bug copied a stored raw value through), the raw value
     * is stripped from the outbound representation rather than rendered.
     *
     * @param ObjectEntity $rule   The AVG BSN policy rule configuration object.
     * @param array        $data   The current rule data envelope (body/headers/parameters).
     * @param string       $timing Either "before" (inbound hash) or "after" (outbound guard).
     *
     * @return array The updated $data.
     *
     * @throws Exception When an inbound BSN fails the 11-proef checksum.
     *
     * @spec openspec/specs/vng-klantinteracties-adapter/spec.md
     */
    public function apply(ObjectEntity $rule, array $data, string $timing='before'): array
    {
        $configuration = $rule->getObject()['configuration']['avgBsnPolicy'] ?? [];
        $path          = $configuration['path'] ?? self::DEFAULT_PATH;
        $codeField     = $configuration['codeSoortField'] ?? self::DEFAULT_CODE_FIELD;
        $valueField    = $configuration['valueField'] ?? self::DEFAULT_VALUE_FIELD;

        $dot        = new Dot($data);
        $identifier = $dot->get($path);

        if (is_array($identifier) === false
            || isset($identifier[$codeField]) === false
            || strtolower((string) $identifier[$codeField]) !== 'bsn'
            || isset($identifier[$valueField]) === false
        ) {
            // Nothing BSN-shaped at this path — the rule is a no-op.
            return $data;
        }

        if ($timing === 'after') {
            if ($this->isValidBsn(bsn: (string) $identifier[$valueField]) === true) {
                // A raw, checksum-valid BSN survived to the outbound path —
                // never render it; omit the identity value entirely.
                unset($identifier[$valueField]);
                $dot->set($path, $identifier);
                return $dot->all();
            }

            return $data;
        }

        $rawBsn = (string) $identifier[$valueField];
        if ($this->isValidBsn(bsn: $rawBsn) === false) {
            throw new Exception('Invalid BSN: failed 11-proef checksum.');
        }

        $identifier[$valueField] = $this->hash(bsn: $rawBsn);
        $dot->set($path, $identifier);

        return $dot->all();
    }//end apply()

    /**
     * SHA-256-hash a validated BSN value.
     *
     * @param string $bsn A checksum-valid BSN.
     *
     * @return string The hash-backed identity value; never the raw BSN.
     *
     * @spec openspec/specs/vng-klantinteracties-adapter/spec.md
     */
    public function hash(string $bsn): string
    {
        return hash('sha256', $bsn);
    }//end hash()

    /**
     * Validate a BSN via the Dutch 11-proef (modulo-11) checksum.
     *
     * The BSN is 8 or 9 digits. Each digit (from the left, excluding the
     * last) is weighted 9, 8, 7 ... 2; the last digit is weighted -1. The sum
     * of the weighted digits must be a multiple of 11, and the BSN must not
     * be all zeroes.
     *
     * @param string $bsn The candidate BSN string.
     *
     * @return bool True when the BSN passes the 11-proef checksum.
     *
     * @spec openspec/specs/vng-klantinteracties-adapter/spec.md
     */
    public function isValidBsn(string $bsn): bool
    {
        if (preg_match('/^\d{8,9}$/', $bsn) !== 1 || (int) $bsn === 0) {
            return false;
        }

        // Left-pad an 8-digit BSN to 9 digits (leading zero) so the fixed
        // 9/8/7/6/5/4/3/2/-1 weighting always applies.
        $digits = str_pad($bsn, 9, '0', STR_PAD_LEFT);

        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += ((int) $digits[$i]) * (9 - $i);
        }

        $sum += ((int) $digits[8]) * -1;

        return ($sum % 11) === 0;
    }//end isValidBsn()
}//end class
