<?php

/**
 * Remaining plaintext-secret disclosure regression test (ocon#147 phase C, #214, openregister#380).
 *
 * Phases 1/2/B closed the leaks on `source`, `consumer` and `event_subscription`. This is
 * phase C — the remaining schemas ocon#147 names:
 *
 *  - `rule` — an `authentication`-type rule stores its inbound API keys at
 *    `configuration.authentication.keys` (a map of apiKey => nextcloud userId; a caller
 *    presenting one authenticates AS that user). The secret is NESTED inside the untyped
 *    `configuration` object. Phase C first shipped a schema-level admin-only lockdown, which
 *    closed the disclosure to NON-admins but left an admin-readable residual (blanket-hiding
 *    `configuration` breaks the editor, and OpenRegister then resolved writeOnly from TOP-LEVEL
 *    properties only). The ocon#147 LAST residual closes that gap: openregister#459 added
 *    `x-openregister-writeonly-paths`, so 99-rule-nested-auth-writeonly.json declares
 *    `configuration.authentication.keys` write-only — stripped from EVERY rendered read (admins
 *    included). The engine is unaffected because EndpointService::getRuleById() re-reads the rule
 *    with `_render: false`, and the editor omits the keys on save so openregister#463 preserves
 *    them (see RuleNestedAuthWriteOnlyTest). The admin-only lockdown remains in place on top.
 *  - `lti_platform` / `lti_tool` — `signingKeys[].privateKeySecret` is PEM private-key
 *    material. Nested in an array, so the whole `signingKeys` array is marked writeOnly
 *    (the public JWKS is served via `_rbac: false`, unaffected). Plus an admin-only lockdown,
 *    since a registration is an admin-owned trust anchor.
 *  - `eudi_credential_offer.callbackSigningSecret` — a top-level whsec_ HMAC secret →
 *    writeOnly. `eudi_status_list.currentToken` — a published-by-design token, marked
 *    writeOnly as surface reduction (its actual signing key lives encrypted in IAppConfig).
 *
 * These tests pin each control so a future register edit cannot silently reopen the leak.
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

class RemainingSecretLeaksTest extends TestCase
{
    /**
     * The EFFECTIVE schema for $name: base register deep-merged with every register.d
     * fragment, replicating InitializeRegister::deepMergeConfig().
     *
     * @param string $name The schema key.
     *
     * @return array<string, mixed>
     */
    private function effectiveSchema(string $name): array
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

        return $descriptor['components']['schemas'][$name];
    }//end effectiveSchema()

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
    }//end deepMerge()

    /**
     * Assert every verb on a schema's authorization block is admin-only, and no
     * public/authenticated grant reopens the hole.
     *
     * @param string $name The schema key.
     *
     * @return void
     */
    private function assertAdminOnly(string $name): void
    {
        $schema = $this->effectiveSchema($name);

        $this->assertArrayHasKey(
            'authorization',
            $schema,
            "The `$name` schema MUST declare authorization — with none, OpenRegister defaults to "
            .'readable-by-any-authenticated-user (the ocon#147 condition).'
        );

        foreach (['create', 'read', 'update', 'delete'] as $verb) {
            $this->assertSame(
                ['admin'],
                ($schema['authorization'][$verb] ?? null),
                "The `$verb` verb on `$name` must be admin-only"
            );
        }

        $serialised = (string) json_encode($schema['authorization']);
        $this->assertStringNotContainsString('"public"', $serialised, "`$name` must not grant public");
        $this->assertStringNotContainsString('"authenticated"', $serialised, "`$name` must not grant authenticated");
    }//end assertAdminOnly()

    /**
     * `rule` carries inbound impersonation API keys nested in `configuration.authentication.keys`;
     * the nested secret cannot be writeOnly-stripped, so the schema is locked to admin-only.
     *
     * @return void
     */
    public function testRuleSchemaIsAdminOnly(): void
    {
        $this->assertAdminOnly('rule');
    }//end testRuleSchemaIsAdminOnly()

    /**
     * `lti_platform` and `lti_tool` are admin-owned trust anchors carrying private signing keys.
     *
     * @return void
     */
    public function testLtiRegistrationSchemasAreAdminOnly(): void
    {
        $this->assertAdminOnly('lti_platform');
        $this->assertAdminOnly('lti_tool');
    }//end testLtiRegistrationSchemasAreAdminOnly()

    /**
     * The private signing-key material must never be returned: the whole `signingKeys`
     * array is writeOnly (privateKeySecret is nested, so per-field stripping is impossible).
     *
     * @return void
     */
    public function testLtiSigningKeysAreWriteOnly(): void
    {
        foreach (['lti_platform', 'lti_tool'] as $name) {
            $signingKeys = $this->effectiveSchema($name)['properties']['signingKeys'];
            $this->assertTrue(
                ($signingKeys['writeOnly'] ?? false),
                "`$name.signingKeys` must be writeOnly — it carries private key material (ocon#147)"
            );
            // The merge must not clobber the array's shape.
            $this->assertSame('array', $signingKeys['type'], 'The signingKeys type must survive the fragment merge');
            $this->assertArrayHasKey('items', $signingKeys, 'The signingKeys items must survive the fragment merge');
        }
    }//end testLtiSigningKeysAreWriteOnly()

    /**
     * The EUDI callback HMAC secret is a top-level string and must be writeOnly.
     *
     * @return void
     */
    public function testEudiCallbackSigningSecretIsWriteOnly(): void
    {
        $property = $this->effectiveSchema('eudi_credential_offer')['properties']['callbackSigningSecret'];
        $this->assertTrue(
            ($property['writeOnly'] ?? false),
            '`eudi_credential_offer.callbackSigningSecret` must be writeOnly (ocon#147)'
        );
        $this->assertSame('string', $property['type'], 'The property type must survive the fragment merge');
    }//end testEudiCallbackSigningSecretIsWriteOnly()

    /**
     * The published status-list token is marked writeOnly as API-surface reduction.
     *
     * @return void
     */
    public function testEudiStatusListCurrentTokenIsWriteOnly(): void
    {
        $property = $this->effectiveSchema('eudi_status_list')['properties']['currentToken'];
        $this->assertTrue(
            ($property['writeOnly'] ?? false),
            '`eudi_status_list.currentToken` must be writeOnly (ocon#147 phase C)'
        );
    }//end testEudiStatusListCurrentTokenIsWriteOnly()

    /**
     * Every touched schema's version was bumped so OpenRegister re-imports it (it only
     * updates a schema when the incoming version exceeds the stored one).
     *
     * The assertion is `>=`, not `===`, and that is the whole point. What this test
     * protects is "the secret-control change actually re-imports", which is satisfied
     * by the floor below AND by every later bump — a schema at 1.2.0 re-imports at
     * least as surely as one at 1.1.0. Pinning the exact value asserted something
     * stricter than the invariant and turned every subsequent, legitimate bump into a
     * failure: `lti_platform`/`lti_tool` moved to 1.2.0 when their `signingKeys` /
     * `status` / `identityPolicy` properties changed, which OpenRegister REQUIRES a
     * bump for, and this test failed for doing the right thing.
     *
     * The true positive is unchanged: a schema left at or below its pre-change
     * version still fails, because that is the case where OR silently keeps the old
     * definition and the secret control never lands.
     *
     * @return void
     */
    public function testTouchedSchemasHadTheirVersionBumped(): void
    {
        // The version at which each schema's secret-control change landed. A schema
        // MUST be at least here; going further is fine and expected.
        $minimum = [
            // rule bumped 1.2.0 -> 1.3.0 in 99-rule-nested-auth-writeonly.json: the ocon#147
            // last residual makes `configuration.authentication.keys` write-only, closing the
            // admin-readable inbound-apiKey impersonation map the lockdown phase left open.
            'rule'                  => '1.3.0',
            'lti_platform'          => '1.1.0',
            'lti_tool'              => '1.1.0',
            'eudi_credential_offer' => '1.1.0',
            'eudi_status_list'      => '1.1.0',
        ];

        foreach ($minimum as $name => $floor) {
            $actual = ($this->effectiveSchema($name)['version'] ?? null);

            $this->assertNotNull(
                $actual,
                "The `$name` schema must declare a version — without one OpenRegister cannot "
                ."decide whether to re-import, and the secret-control change never lands"
            );

            $this->assertTrue(
                version_compare($actual, $floor, '>='),
                "The `$name` schema version must be at least $floor so the secret-control change "
                ."re-imports; found $actual"
            );
        }
    }//end testTouchedSchemasHadTheirVersionBumped()
}//end class
