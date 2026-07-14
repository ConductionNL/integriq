<?php
/**
 * Unit tests for JobHandler export redaction (secret-hygiene).
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

use OCA\OpenConnector\Service\ConfigurationHandlers\JobHandler;
use OCA\OpenConnector\Service\Security\SensitiveFieldRegistry;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * TC-7 (Job half) — JobHandler::export() redacts configuration secrets.
 */
class JobHandlerTest extends TestCase
{

    /**
     * @var JobHandler
     */
    private JobHandler $handler;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new JobHandler(
            ObjectServiceMockBuilder::make($this),
            new SensitiveFieldRegistry(),
        );
    }//end setUp()


    /**
     * TC-7 — `configuration.password` is masked to ***REDACTED***, while the
     * `arguments` field (distinct from `configuration`; carries id/slug
     * references, not raw secrets — see design Open Questions) keeps its
     * existing slug-translation behaviour untouched.
     *
     * @return void
     *
     * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
     */
    public function testExportRedactsConfigurationPasswordAndLeavesArgumentsAlone(): void
    {
        $job = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'slug'          => 'my-job',
                'configuration' => [
                    'password' => 'live-job-password-123',
                    'interval' => 3600,
                ],
                'arguments'     => ['sourceId' => 'source-id-42'],
            ],
            'job-uuid-1'
        );

        $mappings = [
            'source' => [
                'idToSlug' => ['source-id-42' => 'my-source-slug'],
                'slugToId' => ['my-source-slug' => 'source-id-42'],
            ],
        ];

        $export = $this->handler->export($job, $mappings);

        $this->assertSame('***REDACTED***', $export['configuration']['password']);
        $this->assertSame(3600, $export['configuration']['interval']);

        // arguments keeps the pre-existing id->slug translation, no redaction pass.
        $this->assertSame('my-source-slug', $export['arguments']['sourceId']);

        $this->assertStringNotContainsString('live-job-password-123', json_encode($export));
    }//end testExportRedactsConfigurationPasswordAndLeavesArgumentsAlone()
}//end class
