<?php

/**
 * OpenConnector mTLS Transport Options Builder.
 *
 * Materialises an {@see MtlsCertificateBundle}'s PEM strings to transient,
 * `0600`-permission temp files (Guzzle/cURL requires file paths for `cert`/
 * `ssl_key`/`verify`, not raw PEM bytes) and builds the corresponding Guzzle
 * request-options array. Mirrors {@see \OCA\OpenConnector\Service\CallService::writeFile()}'s
 * already-audited hygiene (#1012(a)): `tempnam()` in the system temp dir for
 * unpredictable names, `chmod 0600` re-asserted before AND after the write
 * so the exposure window is never wider than necessary.
 *
 * Cleanup is the caller's responsibility ({@see MtlsTransportService}
 * guarantees it via a `finally` block) — this class only tracks which paths
 * it created via {@see MaterializedFiles} return value.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Mtls
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
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-certificate-material-is-materialised-to-disk-only-transiently-with-guaranteed-cleanup-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Mtls;

/**
 * Materialises mTLS certificate material to secure temp files and builds
 * the corresponding Guzzle TLS request options.
 *
 * `@`-silenced `chmod`/`unlink` calls are DELIBERATE and mirror
 * {@see \OCA\OpenConnector\Service\CallService::writeFile()}/`removeFile()`'s
 * identical, already-baselined hygiene: the `0600` re-assertion must not
 * raise on a filesystem that reports it unsupported, and cleanup must never
 * raise when the file is already gone — a cleanup that throws would mask
 * the real transport error AND leave key material behind.
 *
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-certificate-material-is-materialised-to-disk-only-transiently-with-guaranteed-cleanup-req-002
 */
class MtlsTransportOptionsBuilder
{
    /**
     * Write the bundle's PEM material to `0600` temp files.
     *
     * @param MtlsCertificateBundle $bundle The validated, decrypted certificate material.
     *
     * @return MtlsMaterializedFiles The on-disk paths, tracked for cleanup.
     *
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-certificate-material-is-materialised-to-disk-only-transiently-with-guaranteed-cleanup-req-002
     */
    public function materialize(MtlsCertificateBundle $bundle): MtlsMaterializedFiles
    {
        $certificatePath = $this->writeSecureTempFile(baseFileName: 'mtls_certificate', contents: $bundle->certificatePem);
        $privateKeyPath  = $this->writeSecureTempFile(baseFileName: 'mtls_privateKey', contents: $bundle->privateKeyPem);

        $caBundlePath = null;
        if ($bundle->caBundlePem !== null && $bundle->caBundlePem !== '') {
            $caBundlePath = $this->writeSecureTempFile(baseFileName: 'mtls_caBundle', contents: $bundle->caBundlePem);
        }

        return new MtlsMaterializedFiles(
            certificatePath: $certificatePath,
            privateKeyPath: $privateKeyPath,
            caBundlePath: $caBundlePath
        );

    }//end materialize()

    /**
     * Build the Guzzle request-options fragment (`cert`/`ssl_key`/`verify`)
     * for a set of materialized files.
     *
     * @param MtlsMaterializedFiles $files      The materialized temp-file paths.
     * @param string|null           $passphrase The private key passphrase, or null when unprotected.
     *
     * @return array<string, mixed> Guzzle request-options fragment to merge into the outbound call.
     *
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-certificate-material-is-materialised-to-disk-only-transiently-with-guaranteed-cleanup-req-002
     */
    public function toGuzzleOptions(MtlsMaterializedFiles $files, ?string $passphrase): array
    {
        $options = [
            'cert'    => $files->certificatePath,
            'ssl_key' => $files->privateKeyPath,
        ];

        if ($passphrase !== null && $passphrase !== '') {
            $options['ssl_key'] = [$files->privateKeyPath, $passphrase];
        }

        if ($files->caBundlePath !== null) {
            $options['verify'] = $files->caBundlePath;
        }

        return $options;

    }//end toGuzzleOptions()

    /**
     * Remove every temp file this instance materialized. Silenced — cleanup
     * must never raise, mirrors `CallService::removeFile()`'s hygiene.
     *
     * @param MtlsMaterializedFiles $files The materialized temp-file paths to remove.
     *
     * @return void
     *
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-temp-files-are-removed-even-when-the-dispatch-throws
     */
    public function cleanup(MtlsMaterializedFiles $files): void
    {
        foreach ($files->allPaths() as $path) {
            if ($path !== null && $path !== '' && file_exists($path) === true) {
                @unlink($path);
            }
        }

    }//end cleanup()

    /**
     * Write one temp file with `0600` permissions.
     *
     * @param string $baseFileName The base filename used as filename prefix.
     * @param string $contents     File contents to write.
     *
     * @return string File location on disk.
     */
    private function writeSecureTempFile(string $baseFileName, string $contents): string
    {
        $prefix       = 'oc_'.$baseFileName.'_';
        $tempDir      = sys_get_temp_dir();
        $tempLocation = tempnam($tempDir, $prefix);
        if ($tempLocation === false) {
            $stamp        = (microtime().getmypid());
            $tempLocation = sys_get_temp_dir().DIRECTORY_SEPARATOR.$baseFileName.'-'.$stamp;
        }

        // Chmod BEFORE the contents land so the create→chmod race window is
        // empty (tempnam creates with 0600 on Linux but we re-assert), then
        // again after the write for good measure.
        @chmod($tempLocation, 0600);
        file_put_contents($tempLocation, $contents);
        @chmod($tempLocation, 0600);

        return $tempLocation;

    }//end writeSecureTempFile()
}//end class
