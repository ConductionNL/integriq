<?php

/**
 * Source-secret write-only opt-in regression test (ocon#147 phase 2, openregister#380).
 *
 * Phase 1 (#152) locked the `source` schema to admin-only, which stopped a non-admin
 * reading it. This is phase 2: the plaintext credential fields are marked
 * `writeOnly: true`, so that OpenRegister's render boundary strips them from EVERY API
 * response — even an admin's, even the generic object API, even the audit-trail /
 * relations index copy. A field the server never returns cannot leak, no matter who reads
 * it or through which path.
 *
 * The engine is unaffected: it reads a source's credential via `getObject()` in system
 * context (`_rbac: false`), which the redaction never touches (see
 * `CallService::$source->getObject()`).
 *
 * These tests pin the flag on each secret so a future register edit cannot silently drop
 * it and reopen the leak.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

class SourceSecretsWriteOnlyTest extends TestCase
{
    /**
     * The `source` schema's EFFECTIVE property map: the base register deep-merged with its
     * register.d fragments, replicating `InitializeRegister::deepMergeConfig()`.
     *
     * The `writeOnly` flags ship as a fragment (a base-register edit trips a pre-existing
     * SchemaMapper $ref bug), and they deep-merge INTO each property object — so this test
     * must use the same recursive merge the app does, not a shallow array_merge that would
     * replace the property.
     *
     * @return array<string, mixed>
     */
    private function sourceProperties(): array
    {
        $root       = dirname(__DIR__, 3);
        $descriptor = json_decode((string) file_get_contents($root.'/lib/Settings/openconnector_register.json'), true);

        $fragments = glob($root.'/lib/Settings/register.d/*.json');
        sort($fragments);
        foreach ($fragments as $fragmentPath) {
            $fragment = json_decode((string) file_get_contents($fragmentPath), true);
            if (is_array($fragment) === true) {
                $descriptor = $this->deepMerge($descriptor, $fragment);
            }
        }

        return $descriptor['components']['schemas']['source']['properties'];
    }

    /**
     * Recursive deep merge — mirrors InitializeRegister::deepMergeConfig (lists append,
     * associative arrays recurse, scalars overwrite).
     *
     * @param array<mixed> $base    The base.
     * @param array<mixed> $overlay The overlay.
     *
     * @return array<mixed>
     */
    private function deepMerge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) === true && isset($base[$key]) === true && is_array($base[$key]) === true) {
                $baseIsList    = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
                $overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
                if ($baseIsList === true && $overlayIsList === true) {
                    $base[$key] = array_merge($base[$key], $value);
                } else {
                    $base[$key] = $this->deepMerge($base[$key], $value);
                }
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Every secret-bearing property is write-only.
     *
     * @return void
     */
    public function testEverySourceSecretIsWriteOnly(): void
    {
        $properties = $this->sourceProperties();

        foreach (['apikey', 'secret', 'password', 'jwt', 'authenticationConfig'] as $secret) {
            $this->assertArrayHasKey($secret, $properties, "The source schema must declare `$secret`");
            $this->assertTrue(
                ($properties[$secret]['writeOnly'] ?? false),
                "`source.$secret` must be writeOnly — otherwise the API returns the credential in cleartext (ocon#147)"
            );
        }
    }

    /**
     * A basic-auth username is not a secret and stays readable — the admin UI needs it to
     * show how a source is configured. Over-redacting would hurt without helping.
     *
     * @return void
     */
    public function testTheUsernameIsNotRedacted(): void
    {
        $properties = $this->sourceProperties();

        $this->assertArrayHasKey('username', $properties);
        $this->assertFalse(
            ($properties['username']['writeOnly'] ?? false),
            'username is not a secret; it should remain readable'
        );
    }

    /**
     * The deep-merge preserves the property's other attributes — the fragment adds
     * `writeOnly` to `apikey` without dropping its type or title. This guards against a
     * shallow merge that would replace the whole property object.
     *
     * @return void
     */
    public function testTheWriteOnlyFlagDoesNotClobberTheRestOfTheProperty(): void
    {
        $apikey = $this->sourceProperties()['apikey'];

        $this->assertSame('string', $apikey['type'], 'The property type must survive the fragment merge');
        $this->assertArrayHasKey('title', $apikey, 'The property title must survive the fragment merge');
        $this->assertTrue($apikey['writeOnly']);
    }
}
