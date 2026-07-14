<?php
/**
 * Unit tests for SourceHandler export redaction (secret-hygiene).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\ConfigurationHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\ConfigurationHandlers;

use OCA\OpenConnector\Service\ConfigurationHandlers\SourceHandler;
use OCA\OpenConnector\Service\Security\SensitiveFieldRegistry;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * TC-3 — SourceHandler::export() redacts nested configuration secrets via the
 * shared registry while keeping its unchanged top-level unset() behaviour.
 */
class SourceHandlerTest extends TestCase
{

    /**
     * @var SourceHandler
     */
    private SourceHandler $handler;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new SourceHandler(
            ObjectServiceMockBuilder::make($this),
            new SensitiveFieldRegistry(),
        );
    }//end setUp()


    /**
     * TC-3 — top-level `apikey`/`secret` stay absent (unchanged unset()
     * behaviour) while the nested `configuration.headers.Authorization` is
     * MASKED to ***REDACTED*** by the shared registry (replacing the old ad
     * hoc str_contains substring check that unset the key entirely).
     *
     * @return void
     *
     * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
     */
    public function testExportUnsetsTopLevelSecretsAndMasksNestedConfiguration(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'slug'          => 'my-source',
                'name'          => 'My Source',
                'apikey'        => 'live_xyz',
                'secret'        => 's3cr3t',
                'configuration' => [
                    'headers.Authorization' => 'Bearer abc',
                    'headers.Accept'        => 'application/json',
                ],
            ],
            'source-uuid-1'
        );

        $export = $this->handler->export($source, []);

        // Top-level exact-match fields removed entirely (unchanged behaviour).
        $this->assertArrayNotHasKey('apikey', $export);
        $this->assertArrayNotHasKey('secret', $export);

        // Nested configuration secret masked, not omitted (new registry behaviour).
        $this->assertSame('***REDACTED***', $export['configuration']['headers.Authorization']);

        // No plaintext secret survives anywhere in the export.
        $this->assertStringNotContainsString('live_xyz', json_encode($export));
        $this->assertStringNotContainsString('s3cr3t', json_encode($export));
        $this->assertStringNotContainsString('Bearer abc', json_encode($export));
    }//end testExportUnsetsTopLevelSecretsAndMasksNestedConfiguration()


    /**
     * TC-3 — a non-sensitive configuration header (`headers.Accept`) is
     * retained unmodified.
     *
     * @return void
     *
     * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
     */
    public function testExportRetainsNonSensitiveHeaders(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'slug'          => 'my-source',
                'configuration' => ['headers.Accept' => 'application/json'],
            ],
            'source-uuid-2'
        );

        $export = $this->handler->export($source, []);

        $this->assertSame('application/json', $export['configuration']['headers.Accept']);
    }//end testExportRetainsNonSensitiveHeaders()
}//end class
