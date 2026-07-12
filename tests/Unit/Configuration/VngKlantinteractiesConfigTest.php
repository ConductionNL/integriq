<?php
/**
 * Structural tests for the packaged VNG Klantinteracties configuration set.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the packaged `configuration/vng-klantinteracties.oas.json`
 * ADR-015 document is well-formed and every cross-entity reference resolves
 * to a slug present in the same document.
 *
 * @spec openspec/changes/vng-klantinteracties-adapter/specs/vng-klantinteracties-adapter/spec.md#req-001
 */
class VngKlantinteractiesConfigTest extends TestCase
{

    /**
     * The decoded OAS document, loaded once for all tests.
     *
     * @var array
     */
    private array $document;


    /**
     * Load and decode the packaged configuration document.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $path           = __DIR__.'/../../../configuration/vng-klantinteracties.oas.json';
        $this->document = json_decode(file_get_contents($path), true);
    }//end setUp()


    /**
     * The document is valid JSON carrying a top-level `components` key.
     *
     * @return void
     */
    public function testDocumentHasComponentsKey(): void
    {
        $this->assertIsArray($this->document);
        $this->assertArrayHasKey('components', $this->document);
        $this->assertArrayHasKey('endpoints', $this->document['components']);
        $this->assertArrayHasKey('mappings', $this->document['components']);
        $this->assertArrayHasKey('rules', $this->document['components']);
    }//end testDocumentHasComponentsKey()


    /**
     * Every endpoint's inputMapping/outputMapping slug resolves to a packaged mapping.
     *
     * @return void
     */
    public function testEndpointMappingReferencesResolve(): void
    {
        $mappingSlugs = array_keys($this->document['components']['mappings']);

        foreach ($this->document['components']['endpoints'] as $slug => $endpoint) {
            foreach (['inputMapping', 'outputMapping'] as $field) {
                if (isset($endpoint[$field]) === true) {
                    $this->assertContains(
                        $endpoint[$field],
                        $mappingSlugs,
                        sprintf('Endpoint "%s" %s "%s" does not resolve to a packaged mapping.', $slug, $field, $endpoint[$field])
                    );
                }
            }
        }
    }//end testEndpointMappingReferencesResolve()


    /**
     * Every endpoint's rules[] slugs resolve to a packaged rule.
     *
     * @return void
     */
    public function testEndpointRuleReferencesResolve(): void
    {
        $ruleSlugs = array_keys($this->document['components']['rules']);

        foreach ($this->document['components']['endpoints'] as $slug => $endpoint) {
            foreach (($endpoint['rules'] ?? []) as $ruleSlug) {
                $this->assertContains(
                    $ruleSlug,
                    $ruleSlugs,
                    sprintf('Endpoint "%s" references unresolved rule slug "%s".', $slug, $ruleSlug)
                );
            }
        }
    }//end testEndpointRuleReferencesResolve()


    /**
     * The composite fan-out rule's child parentRef values resolve to sibling bodyKeys or "parent".
     *
     * @return void
     */
    public function testCompositeFanoutParentRefsResolve(): void
    {
        $rule       = $this->document['components']['rules']['vng-maak-klantcontact-composite'];
        $bodyKeys   = ['parent'];
        foreach ($rule['configuration']['compositeFanout']['children'] as $child) {
            $bodyKeys[] = $child['bodyKey'];
        }

        foreach ($rule['configuration']['compositeFanout']['children'] as $child) {
            $parentRef = $child['parentRef'] ?? 'parent';
            $this->assertContains($parentRef, $bodyKeys);
        }
    }//end testCompositeFanoutParentRefsResolve()


    /**
     * No live credential values leak into the packaged document (placeholders only).
     *
     * @return void
     */
    public function testDocumentCarriesNoLiveCredentials(): void
    {
        $raw = json_encode($this->document);

        $this->assertStringNotContainsString('"apikey"', $raw);
        $this->assertStringNotContainsString('"secret"', $raw);
        $this->assertStringNotContainsString('Bearer ', $raw);
    }//end testDocumentCarriesNoLiveCredentials()


    /**
     * The separate consumer seed file documents the ADR-015 gap and carries placeholder credentials only.
     *
     * @return void
     */
    public function testConsumerSeedDocumentsAdr015GapAndPlaceholders(): void
    {
        $path     = __DIR__.'/../../../configuration/vng-klantinteracties-consumer.seed.json';
        $consumer = json_decode(file_get_contents($path), true);

        $this->assertArrayHasKey('_deviation', $consumer);
        $this->assertSame('YOUR_PUBLIC_KEY_HERE', $consumer['consumer']['authorizationConfiguration']['publicKey']);
        $this->assertSame('00000000-0000-0000-0000-000000000000', $consumer['consumer']['authorizationConfiguration']['userId']);
    }//end testConsumerSeedDocumentsAdr015GapAndPlaceholders()


}//end class
