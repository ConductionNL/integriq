<?php

/**
 * Unit tests for MtlsTransportOptionsBuilder.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Mtls
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Mtls;

use OCA\OpenConnector\Service\Mtls\MtlsCertificateBundle;
use OCA\OpenConnector\Service\Mtls\MtlsTransportOptionsBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for temp-file materialization, Guzzle option building, and cleanup.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-certificate-material-is-materialised-to-disk-only-transiently-with-guaranteed-cleanup-req-002
 */
class MtlsTransportOptionsBuilderTest extends TestCase
{

    /**
     * @var MtlsTransportOptionsBuilder
     */
    private MtlsTransportOptionsBuilder $builder;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new MtlsTransportOptionsBuilder();

    }//end setUp()

    /**
     * materialize() writes the certificate and key to 0600 temp files with the expected contents.
     *
     * @return void
     */
    public function testMaterializeWritesFilesWith0600Perms(): void
    {
        $bundle = new MtlsCertificateBundle(certificatePem: 'CERT-CONTENTS', privateKeyPem: 'KEY-CONTENTS');

        $files = $this->builder->materialize(bundle: $bundle);

        $this->assertFileExists($files->certificatePath);
        $this->assertFileExists($files->privateKeyPath);
        $this->assertNull($files->caBundlePath);

        $this->assertSame('CERT-CONTENTS', file_get_contents($files->certificatePath));
        $this->assertSame('KEY-CONTENTS', file_get_contents($files->privateKeyPath));

        $this->assertSame('0600', substr(sprintf('%o', fileperms($files->certificatePath)), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($files->privateKeyPath)), -4));

        $this->builder->cleanup(files: $files);

    }//end testMaterializeWritesFilesWith0600Perms()

    /**
     * materialize() also writes a CA bundle when the bundle carries one.
     *
     * @return void
     */
    public function testMaterializeWritesCaBundleWhenPresent(): void
    {
        $bundle = new MtlsCertificateBundle(
            certificatePem: 'CERT-CONTENTS',
            privateKeyPem: 'KEY-CONTENTS',
            passphrase: null,
            caBundlePem: 'CA-CONTENTS'
        );

        $files = $this->builder->materialize(bundle: $bundle);

        $this->assertNotNull($files->caBundlePath);
        $this->assertFileExists($files->caBundlePath);
        $this->assertSame('CA-CONTENTS', file_get_contents($files->caBundlePath));

        $this->builder->cleanup(files: $files);

    }//end testMaterializeWritesCaBundleWhenPresent()

    /**
     * toGuzzleOptions() without a passphrase returns bare-string cert/ssl_key paths.
     *
     * @return void
     */
    public function testToGuzzleOptionsWithoutPassphrase(): void
    {
        $bundle = new MtlsCertificateBundle(certificatePem: 'CERT-CONTENTS', privateKeyPem: 'KEY-CONTENTS');
        $files  = $this->builder->materialize(bundle: $bundle);

        $options = $this->builder->toGuzzleOptions(files: $files, passphrase: null);

        $this->assertSame($files->certificatePath, $options['cert']);
        $this->assertSame($files->privateKeyPath, $options['ssl_key']);
        $this->assertArrayNotHasKey('verify', $options);

        $this->builder->cleanup(files: $files);

    }//end testToGuzzleOptionsWithoutPassphrase()

    /**
     * toGuzzleOptions() with a passphrase returns the array-form `[path, passphrase]` for `ssl_key`.
     *
     * @return void
     */
    public function testToGuzzleOptionsWithPassphrase(): void
    {
        $bundle = new MtlsCertificateBundle(certificatePem: 'CERT-CONTENTS', privateKeyPem: 'KEY-CONTENTS');
        $files  = $this->builder->materialize(bundle: $bundle);

        $options = $this->builder->toGuzzleOptions(files: $files, passphrase: 'secret-pass');

        $this->assertSame([$files->privateKeyPath, 'secret-pass'], $options['ssl_key']);

        $this->builder->cleanup(files: $files);

    }//end testToGuzzleOptionsWithPassphrase()

    /**
     * toGuzzleOptions() sets `verify` to the CA bundle path when one was materialized.
     *
     * @return void
     */
    public function testToGuzzleOptionsWithCaBundleSetsVerify(): void
    {
        $bundle = new MtlsCertificateBundle(
            certificatePem: 'CERT-CONTENTS',
            privateKeyPem: 'KEY-CONTENTS',
            passphrase: null,
            caBundlePem: 'CA-CONTENTS'
        );
        $files  = $this->builder->materialize(bundle: $bundle);

        $options = $this->builder->toGuzzleOptions(files: $files, passphrase: null);

        $this->assertSame($files->caBundlePath, $options['verify']);

        $this->builder->cleanup(files: $files);

    }//end testToGuzzleOptionsWithCaBundleSetsVerify()

    /**
     * cleanup() removes every materialized file.
     *
     * @return void
     *
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-temp-files-are-removed-after-a-successful-dispatch
     */
    public function testCleanupRemovesAllFiles(): void
    {
        $bundle = new MtlsCertificateBundle(
            certificatePem: 'CERT-CONTENTS',
            privateKeyPem: 'KEY-CONTENTS',
            passphrase: null,
            caBundlePem: 'CA-CONTENTS'
        );
        $files  = $this->builder->materialize(bundle: $bundle);

        $this->assertFileExists($files->certificatePath);
        $this->assertFileExists($files->privateKeyPath);
        $this->assertFileExists($files->caBundlePath);

        $this->builder->cleanup(files: $files);

        $this->assertFileDoesNotExist($files->certificatePath);
        $this->assertFileDoesNotExist($files->privateKeyPath);
        $this->assertFileDoesNotExist($files->caBundlePath);

    }//end testCleanupRemovesAllFiles()

    /**
     * cleanup() does not raise when called twice (files already gone).
     *
     * @return void
     */
    public function testCleanupIsIdempotent(): void
    {
        $bundle = new MtlsCertificateBundle(certificatePem: 'CERT-CONTENTS', privateKeyPem: 'KEY-CONTENTS');
        $files  = $this->builder->materialize(bundle: $bundle);

        $this->builder->cleanup(files: $files);
        $this->builder->cleanup(files: $files);

        $this->assertFileDoesNotExist($files->certificatePath);

    }//end testCleanupIsIdempotent()
}//end class
