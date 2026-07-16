<?php

/**
 * Guards the `source` schema's nested write-only auth paths (ocon#235 secret half).
 *
 * The top-level source credentials went writeOnly in ocon#147 phase 2. The OAuth/JWT
 * credentials authored under the UNTYPED `configuration` object stayed plaintext-readable
 * until openregister#459 shipped `x-openregister-writeonly-paths` — a schema-level
 * annotation listing dot-paths from the object root, stripped on EVERY rendered read
 * (admins included, list/search included, `@self.relations` mirror included).
 *
 * WHY THE STRIP IS DRIVEN BY THE DECLARATION AND NOT BY A HARDCODED LIST: the render
 * simulation below reads the paths out of the EFFECTIVE merged register and applies them.
 * That makes the declaration the thing under test — delete the annotation from the
 * fragment and {@see testRenderedReadMustNotReturnNestedAuthSecrets} fails showing the
 * plaintext client_secret, which is the mutation guard the change is worth having.
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

class SourceNestedAuthWriteOnlyTest extends TestCase
{

    /**
     * The annotation key openregister#459 resolves (Schema::WRITEONLY_PATHS_ANNOTATION).
     *
     * @var string
     */
    private const ANNOTATION = 'x-openregister-writeonly-paths';

    /**
     * The nested auth secrets this change strips.
     *
     * @var array<int, string>
     */
    private const EXPECTED_PATHS = [
        'configuration.authentication.client_secret',
        'configuration.authentication.password',
        'configuration.authentication.secret',
        'configuration.authentication.private_key',
    ];

    /**
     * A representative source, as an operator authors it.
     *
     * @return array<string, mixed>
     */
    private function sourceObject(): array
    {
        return [
            'name'          => 'Some OAuth source',
            'configuration' => [
                'authentication' => [
                    'authentication' => 'body',
                    'grant_type'     => 'client_credentials',
                    'scope'          => 'read',
                    'tokenUrl'       => 'https://idp.example/token',
                    'client_id'      => 'my-client-id',
                    'client_secret'  => 'SUPER-SECRET-CLIENT-SECRET',
                    'username'       => 'my-username',
                    'password'       => 'SUPER-SECRET-PASSWORD',
                    'secret'         => 'SUPER-SECRET-JWT-SIGNING-SECRET',
                    'private_key'    => 'SUPER-SECRET-PRIVATE-KEY',
                    'algorithm'      => 'RS256',
                ],
            ],
        ];
    }//end sourceObject()

    /**
     * The EFFECTIVE `source` schema: base register deep-merged with every register.d
     * fragment, replicating InitializeRegister::deepMergeConfig().
     *
     * @return array<string, mixed>
     */
    private function effectiveSchema(): array
    {
        $root       = dirname(__DIR__, 3);
        $descriptor = json_decode((string) file_get_contents($root.'/lib/Settings/openconnector_register.json'), true);

        $fragments = glob($root.'/lib/Settings/register.d/*.json');
        sort($fragments);
        foreach ($fragments as $fragmentPath) {
            $fragment = json_decode((string) file_get_contents($fragmentPath), true);
            $this->assertIsArray($fragment, "Fragment $fragmentPath must be valid JSON");
            $descriptor = $this->deepMerge($descriptor, $fragment);
        }

        return $descriptor['components']['schemas']['source'];
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
     * The paths the EFFECTIVE schema declares write-only.
     *
     * @return array<int, string>
     */
    private function declaredPaths(): array
    {
        return (array) ($this->effectiveSchema()[self::ANNOTATION] ?? []);
    }//end declaredPaths()

    /**
     * Reproduce OpenRegister's render boundary: strip every DECLARED path (and its whole
     * sub-tree) from an outgoing object. Mirrors PropertyRbacHandler::stripWriteOnlyPath.
     *
     * Driven by the declaration on purpose — see the class docblock.
     *
     * @param array<string, mixed> $object  The outgoing object body.
     * @param bool                 $_render Whether the read goes through the render boundary.
     *
     * @return array<string, mixed>
     */
    private function simulateRead(array $object, bool $_render): array
    {
        if ($_render === false) {
            // openregister#459: `_render: false` returns the raw entity BEFORE
            // renderEntity() is ever reached. This is how the engine reads.
            return $object;
        }

        foreach ($this->declaredPaths() as $path) {
            $object = $this->stripPath($object, explode('.', $path));
        }

        return $object;
    }//end simulateRead()

    /**
     * Unset one dot-path (and everything beneath it) from a nested array.
     *
     * @param array<string, mixed> $object   The object to descend into.
     * @param array<int, string>   $segments The remaining path segments.
     *
     * @return array<string, mixed>
     */
    private function stripPath(array $object, array $segments): array
    {
        $head = array_shift($segments);
        if (array_key_exists($head, $object) === false) {
            return $object;
        }

        if ($segments === []) {
            unset($object[$head]);
            return $object;
        }

        if (is_array($object[$head]) === true) {
            $object[$head] = $this->stripPath($object[$head], $segments);
        }

        return $object;
    }//end stripPath()

    /**
     * The fragment declares exactly the nested auth secrets.
     *
     * @return void
     */
    public function testAnnotationDeclaresTheNestedAuthSecrets(): void
    {
        $declared = $this->declaredPaths();

        foreach (self::EXPECTED_PATHS as $path) {
            $this->assertContains(
                $path,
                $declared,
                "`source` must declare `$path` write-only — otherwise the API returns the credential "
                .'in cleartext to every reader, admin included (ocon#235, openregister#459)'
            );
        }
    }//end testAnnotationDeclaresTheNestedAuthSecrets()

    /**
     * A RENDERED read — including an admin's, and including `_rbac: false` — must not
     * return any nested auth secret.
     *
     * THE MUTATION GUARD: remove the annotation from the fragment and this fails,
     * printing the plaintext client_secret.
     *
     * @return void
     */
    public function testRenderedReadMustNotReturnNestedAuthSecrets(): void
    {
        $rendered = $this->simulateRead($this->sourceObject(), true);
        $auth     = $rendered['configuration']['authentication'];

        foreach (['client_secret', 'password', 'secret', 'private_key'] as $secret) {
            $this->assertArrayNotHasKey(
                $secret,
                $auth,
                "A rendered read must never return `configuration.authentication.$secret` — "
                .'the render boundary is schema-gated and unconditional (admins included). '
                .'If this fails, the write-only path declaration is missing or misspelled.'
            );
        }

        // Belt and braces: no secret VALUE survives anywhere in the serialised payload,
        // including the `@self.relations` dot-path mirror shape.
        $serialised = (string) json_encode($rendered);
        foreach (['SUPER-SECRET-CLIENT-SECRET', 'SUPER-SECRET-PASSWORD', 'SUPER-SECRET-JWT-SIGNING-SECRET', 'SUPER-SECRET-PRIVATE-KEY'] as $value) {
            $this->assertStringNotContainsString($value, $serialised, "The secret `$value` leaked through a rendered read");
        }
    }//end testRenderedReadMustNotReturnNestedAuthSecrets()

    /**
     * The `@self.relations` mirror is keyed by LITERAL dot-paths (SaveObject::scanForRelations
     * flattens nested values), so the same declaration must strip it there too.
     *
     * @return void
     */
    public function testRelationsMirrorMustNotReturnNestedAuthSecrets(): void
    {
        $mirror = [
            'configuration.authentication.client_secret' => 'SUPER-SECRET-CLIENT-SECRET',
            'configuration.authentication.client_id'     => 'my-client-id',
        ];

        foreach ($this->declaredPaths() as $path) {
            unset($mirror[$path]);
        }

        $this->assertArrayNotHasKey(
            'configuration.authentication.client_secret',
            $mirror,
            'The @self.relations mirror must not carry the nested secret (openregister#459)'
        );
        $this->assertArrayHasKey(
            'configuration.authentication.client_id',
            $mirror,
            'The mirror must keep non-secret identifiers'
        );
    }//end testRelationsMirrorMustNotReturnNestedAuthSecrets()

    /**
     * The engine contract: a `_render: false` read STILL returns the secrets. This is how
     * CallService::resolveSourceForDispatch() reads the source before it authenticates —
     * if this ever stops being true, every outbound call goes out unauthenticated (ocon#215).
     *
     * @return void
     */
    public function testRawReadStillReturnsNestedAuthSecretsForTheEngine(): void
    {
        $raw  = $this->simulateRead($this->sourceObject(), false);
        $auth = $raw['configuration']['authentication'];

        $this->assertSame('SUPER-SECRET-CLIENT-SECRET', $auth['client_secret'], 'The engine must still read client_secret via _render: false');
        $this->assertSame('SUPER-SECRET-PASSWORD', $auth['password'], 'The engine must still read password via _render: false');
        $this->assertSame('SUPER-SECRET-JWT-SIGNING-SECRET', $auth['secret'], 'The engine must still read the JWT secret via _render: false');
        $this->assertSame('SUPER-SECRET-PRIVATE-KEY', $auth['private_key'], 'The engine must still read private_key via _render: false');
    }//end testRawReadStillReturnsNestedAuthSecretsForTheEngine()

    /**
     * Identifiers are NOT secrets and must survive a rendered read — mirroring the
     * top-level overlay, which marks apikey/secret/password/jwt but deliberately NOT
     * username/jwtId. `authentication` is an auth-STRATEGY string, and `credentialRef`
     * is the broker reference BrokeredCallService must keep reading.
     *
     * @return void
     */
    public function testIdentifiersAndStrategyAreNotStripped(): void
    {
        $auth = $this->simulateRead($this->sourceObject(), true)['configuration']['authentication'];

        foreach (['username', 'client_id', 'authentication', 'grant_type', 'scope', 'tokenUrl', 'algorithm'] as $keep) {
            $this->assertArrayHasKey(
                $keep,
                $auth,
                "`configuration.authentication.$keep` is not a secret and must remain readable — "
                .'stripping it would break the source editor and the operator UX for no security gain'
            );
        }

        $declared = $this->declaredPaths();
        foreach (['username', 'client_id', 'credentialRef', 'authentication'] as $notSecret) {
            $this->assertNotContains(
                'configuration.authentication.'.$notSecret,
                $declared,
                "`$notSecret` must NOT be declared write-only — it is an identifier/strategy/reference, not a credential"
            );
        }
    }//end testIdentifiersAndStrategyAreNotStripped()

    /**
     * Every declared path must be well-formed and rooted at a property the schema
     * DECLARES. openregister#459 deliberately exempts this annotation from openregister#419's
     * drop-the-bad-key isolation: a malformed path or an undeclared root ABORTS the save.
     * A typo here would take the whole register import down, so assert it locally.
     *
     * @return void
     */
    public function testEveryDeclaredPathIsWellFormedAndRooted(): void
    {
        $schema     = $this->effectiveSchema();
        $properties = $schema['properties'];

        foreach ($this->declaredPaths() as $path) {
            $this->assertIsString($path, 'Every write-only path must be a string');
            $this->assertNotSame('', $path, 'A write-only path must not be empty');

            $segments = explode('.', $path);
            foreach ($segments as $segment) {
                $this->assertNotSame('', $segment, "The path `$path` has an empty segment — this ABORTS the save");
            }

            $this->assertArrayHasKey(
                $segments[0],
                $properties,
                "The path `$path` is rooted at `{$segments[0]}`, which the `source` schema does not declare — "
                .'openregister#459 ABORTS the save on an undeclared root segment'
            );
        }
    }//end testEveryDeclaredPathIsWellFormedAndRooted()

    /**
     * The schema version was bumped so OpenRegister actually re-imports it — it updates a
     * schema only when the incoming version exceeds the stored one, so a content change
     * without a bump is a silent no-op on every existing install.
     *
     * @return void
     */
    public function testSourceSchemaVersionWasBumped(): void
    {
        $this->assertSame(
            '1.3.0',
            ($this->effectiveSchema()['version'] ?? null),
            'The `source` schema version must be bumped to 1.3.0 so the write-only path declaration re-imports'
        );
    }//end testSourceSchemaVersionWasBumped()

    /**
     * The engine re-resolves the source RAW before it authenticates. `_render: false` is the
     * load-bearing argument — the strip is SCHEMA-gated (RenderObject::schemaHasWriteOnlyRule),
     * NOT `_rbac`-gated, which is the lesson ocon#212 learned when its first fix used
     * `_rbac: false` and webhooks still went out unsigned until ocon#226.
     *
     * @return void
     */
    public function testCallServiceReResolvesTheSourceRawBeforeAuth(): void
    {
        $callService = (string) file_get_contents(dirname(__DIR__, 3).'/lib/Service/CallService.php');

        $this->assertMatchesRegularExpression(
            '/private function resolveSourceForDispatch.*?_render:\s*false/s',
            $callService,
            'CallService::resolveSourceForDispatch() MUST re-read the source with `_render: false`. '
            .'Without it the nested write-only auth secrets are stripped from the entity the engine '
            .'authenticates with, and every outbound call goes out UNAUTHENTICATED (ocon#215/#236).'
        );
    }//end testCallServiceReResolvesTheSourceRawBeforeAuth()
}//end class
