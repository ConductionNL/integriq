<?php

/**
 * Hermiq ApprovalVerdictSigner.
 *
 * TEST COPY: ConductionNL/hermiq development on 1 Oct 2026 (after PR #1048), unchanged but for
 * its spec tags, so integriq's verifier is tested against the real producer.
 *
 * Signs an approval verdict with Hermiq's Ed25519 key so another app (integriq) can
 * trust the answer without reading Hermiq's approval objects. The key pair is made on
 * first use; the secret key is a sensitive app value and the public key is published
 * as the app value `approval_verdict_public_key` (base64).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Approval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Approval;

use OCP\IAppConfig;

/**
 * Holds the verdict key pair and signs the canonical JSON of a verdict.
 *
 */
class ApprovalVerdictSigner
{

    /**
     * App value holding the base64 Ed25519 public key (published, not sensitive).
     *
     * @var string
     */
    public const PUBLIC_KEY = 'approval_verdict_public_key';

    /**
     * App value holding the base64 Ed25519 secret key (sensitive).
     *
     * @var string
     */
    public const SECRET_KEY = 'approval_verdict_secret_key';

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig Where the key pair lives.
     *
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * The canonical JSON of a verdict: keys sorted, slashes and unicode unescaped.
     *
     * @param array<string, mixed> $verdict The verdict.
     *
     * @return string
     *
     */
    public static function canonical(array $verdict): string
    {
        ksort($verdict);
        return (string) json_encode($verdict, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    }//end canonical()

    /**
     * Sign a verdict and return the base64 detached signature.
     *
     * @param array<string, mixed> $verdict The verdict.
     *
     * @return string
     *
     */
    public function sign(array $verdict): string
    {
        return base64_encode(sodium_crypto_sign_detached(self::canonical(verdict: $verdict), $this->secretKey()));

    }//end sign()

    /**
     * The stored secret key, or a new pair when either half is missing or unreadable.
     *
     * @return string The raw secret key.
     *
     */
    private function secretKey(): string
    {
        $secret = base64_decode($this->appConfig->getValueString('hermiq', self::SECRET_KEY, ''), true);
        $public = base64_decode($this->appConfig->getValueString('hermiq', self::PUBLIC_KEY, ''), true);
        if (is_string($secret) === true
            && is_string($public) === true
            && strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && sodium_crypto_sign_publickey_from_secretkey($secret) === $public
        ) {
            return $secret;
        }

        $pair   = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $this->appConfig->setValueString('hermiq', self::SECRET_KEY, base64_encode($secret), lazy: true, sensitive: true);
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->appConfig->setValueString('hermiq', self::PUBLIC_KEY, $public, lazy: false, sensitive: false);

        return $secret;

    }//end secretKey()
}//end class
