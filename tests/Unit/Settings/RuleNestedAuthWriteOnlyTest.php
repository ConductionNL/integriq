<?php

/**
 * Guards the `rule` schema's nested write-only inbound-apiKey path (ocon#147 LAST residual).
 *
 * An `authentication`-type rule stores its inbound API keys at
 * `configuration.authentication.keys` — a map of apiKey => nextcloud userId, i.e. a set of
 * live impersonation credentials. 99-rule-lockdown.json (ocon#147 phase C) closed the
 * disclosure to NON-admins but explicitly left an admin-readable residual, because the secret
 * lives NESTED under the untyped `configuration` object and OpenRegister resolved writeOnly
 * from TOP-LEVEL schema properties only. openregister#459 shipped `x-openregister-writeonly-paths`
 * — a schema-level annotation listing dot-paths from the object root, stripped on EVERY rendered
 * read (admins included, list/search included, `@self.relations` mirror included). This fragment
 * declares `configuration.authentication.keys`, closing the residual.
 *
 * WHY THE STRIP IS DRIVEN BY THE DECLARATION AND NOT BY A HARDCODED LIST: the render simulation
 * below reads the paths out of the EFFECTIVE merged register and applies them. That makes the
 * declaration the thing under test — delete the annotation from the fragment and
 * {@see testRenderedReadMustNotReturnRuleKeys} fails showing the plaintext keys, which is the
 * mutation guard the change is worth having.
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

class RuleNestedAuthWriteOnlyTest extends TestCase
{

    /**
     * The annotation key openregister#459 resolves (Schema::WRITEONLY_PATHS_ANNOTATION).
     *
     * @var string
     */
    private const ANNOTATION = 'x-openregister-writeonly-paths';

    /**
     * The nested inbound-apiKey secret this change strips.
     *
     * @var string
     */
    private const EXPECTED_PATH = 'configuration.authentication.keys';

    /**
     * A representative authentication rule, as an operator authors it. `keys` is the
     * apiKey => userId impersonation map the engine reads at
     * EndpointService::processAuthenticationRule().
     *
     * @return array<string, mixed>
     */
    private function ruleObject(): array
    {
        return [
            'name'          => 'Inbound apiKey guard',
            'type'          => 'authentication',
            'configuration' => [
                'authentication' => [
                    'type'   => 'api-key',
                    'users'  => ['alice'],
                    'groups' => ['admins'],
                    'keys'   => [
                        'SUPER-SECRET-INBOUND-KEY-1' => 'alice',
                        'SUPER-SECRET-INBOUND-KEY-2' => 'bob',
                    ],
                ],
            ],
        ];
    }//end ruleObject()

    /**
     * The EFFECTIVE `rule` schema: base register deep-merged with every register.d fragment,
     * replicating InitializeRegister::deepMergeConfig().
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

        return $descriptor['components']['schemas']['rule'];
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
     * @param array<string, mixed> $object  The outgoing object body.
     * @param bool                 $_render Whether the read goes through the render boundary.
     *
     * @return array<string, mixed>
     */
    private function simulateRead(array $object, bool $_render): array
    {
        if ($_render === false) {
            // openregister#459/#463: `_render: false` returns the raw entity BEFORE
            // renderEntity() is ever reached. This is how EndpointService::getRuleById reads.
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
     * The fragment declares exactly the nested inbound-apiKey path.
     *
     * @return void
     */
    public function testAnnotationDeclaresTheRuleKeysPath(): void
    {
        $this->assertContains(
            self::EXPECTED_PATH,
            $this->declaredPaths(),
            '`rule` must declare `'.self::EXPECTED_PATH.'` write-only — otherwise the API returns the '
            .'inbound apiKey => userId impersonation map in cleartext to every reader, admin included '
            .'(ocon#147, openregister#459)'
        );
    }//end testAnnotationDeclaresTheRuleKeysPath()

    /**
     * A RENDERED read — including an admin's, and including a list/search projection — must not
     * return the inbound keys.
     *
     * THE MUTATION GUARD: remove the annotation from the fragment and this fails, printing the
     * plaintext impersonation keys.
     *
     * @return void
     */
    public function testRenderedReadMustNotReturnRuleKeys(): void
    {
        $rendered = $this->simulateRead($this->ruleObject(), true);

        $this->assertArrayNotHasKey(
            'keys',
            $rendered['configuration']['authentication'],
            'A rendered read must never return `configuration.authentication.keys` — the render boundary '
            .'is schema-gated and unconditional (admins and list/search included). If this fails, the '
            .'write-only path declaration is missing or misspelled.'
        );

        // Belt and braces: no key VALUE survives anywhere in the serialised payload, including the
        // `@self.relations` dot-path mirror shape (keys.<apiKey> leaves cannot be enumerated in advance).
        $serialised = (string) json_encode($rendered);
        foreach (['SUPER-SECRET-INBOUND-KEY-1', 'SUPER-SECRET-INBOUND-KEY-2'] as $value) {
            $this->assertStringNotContainsString($value, $serialised, "The inbound key `$value` leaked through a rendered read");
        }
    }//end testRenderedReadMustNotReturnRuleKeys()

    /**
     * The `@self.relations` mirror is keyed by LITERAL dot-paths (SaveObject::scanForRelations
     * flattens nested values into `configuration.authentication.keys.<apiKey>`), so the same
     * declaration must strip the whole `configuration.authentication.keys.` prefix there too.
     *
     * @return void
     */
    public function testRelationsMirrorMustNotReturnRuleKeys(): void
    {
        $mirror = [
            'configuration.authentication.keys.SUPER-SECRET-INBOUND-KEY-1' => 'alice',
            'configuration.authentication.type'                            => 'api-key',
        ];

        // A declared path removes the value at that location AND its whole sub-tree — in the
        // flattened mirror that means every key prefixed with the declared path + '.'.
        foreach ($this->declaredPaths() as $path) {
            foreach (array_keys($mirror) as $mirrorKey) {
                if ($mirrorKey === $path || str_starts_with($mirrorKey, $path.'.')) {
                    unset($mirror[$mirrorKey]);
                }
            }
        }

        $this->assertArrayNotHasKey(
            'configuration.authentication.keys.SUPER-SECRET-INBOUND-KEY-1',
            $mirror,
            'The @self.relations mirror must not carry the flattened inbound key (openregister#459/#460)'
        );
        $this->assertArrayHasKey(
            'configuration.authentication.type',
            $mirror,
            'The mirror must keep non-secret authentication settings'
        );
    }//end testRelationsMirrorMustNotReturnRuleKeys()

    /**
     * The engine contract: a `_render: false` read STILL returns the keys. This is how
     * EndpointService::getRuleById() reads the rule before processAuthenticationRule() calls
     * authorizeApiKey() — if this ever stops being true, every inbound apiKey is refused (401).
     *
     * @return void
     */
    public function testRawReadStillReturnsRuleKeysForTheEngine(): void
    {
        $raw  = $this->simulateRead($this->ruleObject(), false);
        $keys = $raw['configuration']['authentication']['keys'];

        $this->assertSame('alice', $keys['SUPER-SECRET-INBOUND-KEY-1'], 'The engine must still read the inbound key => userId map via _render: false');
        $this->assertSame('bob', $keys['SUPER-SECRET-INBOUND-KEY-2'], 'The engine must still read every inbound key via _render: false');
    }//end testRawReadStillReturnsRuleKeysForTheEngine()

    /**
     * Non-secret authentication settings (type/users/groups) are NOT stripped — the rule editor
     * reads them back to populate the form.
     *
     * @return void
     */
    public function testNonSecretAuthSettingsAreNotStripped(): void
    {
        $auth = $this->simulateRead($this->ruleObject(), true)['configuration']['authentication'];

        foreach (['type', 'users', 'groups'] as $keep) {
            $this->assertArrayHasKey(
                $keep,
                $auth,
                "`configuration.authentication.$keep` is not a secret and must remain readable — "
                .'stripping it would break the rule editor for no security gain'
            );
        }

        $declared = $this->declaredPaths();
        foreach (['type', 'users', 'groups'] as $notSecret) {
            $this->assertNotContains(
                'configuration.authentication.'.$notSecret,
                $declared,
                "`$notSecret` must NOT be declared write-only — it is a non-secret authentication setting"
            );
        }
    }//end testNonSecretAuthSettingsAreNotStripped()

    /**
     * Every declared path must be well-formed and rooted at a property the schema DECLARES.
     * openregister#459 deliberately exempts this annotation from openregister#419's
     * drop-the-bad-key isolation: a malformed path or an undeclared root ABORTS the save.
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
                "The path `$path` is rooted at `{$segments[0]}`, which the `rule` schema does not declare — "
                .'openregister#459 ABORTS the save on an undeclared root segment'
            );
        }
    }//end testEveryDeclaredPathIsWellFormedAndRooted()

    /**
     * The schema version was bumped so OpenRegister actually re-imports it — it updates a schema
     * only when the incoming version exceeds the stored one, so a content change without a bump is
     * a silent no-op on every existing install.
     *
     * @return void
     */
    public function testRuleSchemaVersionWasBumped(): void
    {
        $this->assertSame(
            '1.3.0',
            ($this->effectiveSchema()['version'] ?? null),
            'The `rule` schema version must be bumped to 1.3.0 so the write-only path declaration re-imports'
        );
    }//end testRuleSchemaVersionWasBumped()

    /**
     * The engine reads the rule RAW before it authenticates. `_render: false` is the load-bearing
     * argument — the strip is SCHEMA-gated (RenderObject::schemaHasWriteOnlyRule), NOT `_rbac`-gated,
     * which is the lesson ocon#212/#226 learned. Assert EndpointService::getRuleById passes it.
     *
     * @return void
     */
    public function testEndpointServiceReadsTheRuleRawBeforeAuth(): void
    {
        $endpointService = (string) file_get_contents(dirname(__DIR__, 3).'/lib/Service/EndpointService.php');

        $this->assertMatchesRegularExpression(
            '/private function getRuleById\(string \$id\).*?_render:\s*false/s',
            $endpointService,
            'EndpointService::getRuleById() MUST read the rule with `_render: false`. Without it the '
            .'write-only inbound keys are stripped from the entity the engine authenticates with, and '
            .'every inbound apiKey is refused with 401 (ocon#147, openregister#459/#463).'
        );
    }//end testEndpointServiceReadsTheRuleRawBeforeAuth()

    /**
     * The or#463 save-side contract this whole fix depends on, simulated locally (the real logic is
     * PropertyRbacHandler::collectOmittedWriteOnlyPaths + restoreWriteOnlyValues in openregister):
     * an update payload that OMITS `configuration.authentication.keys` PRESERVES the stored keys; a
     * payload that sends `keys: []` (present) CLEARS them. This documents exactly why the frontend
     * (buildAuthenticationConfiguration.js) MUST omit rather than send an empty array.
     *
     * @return void
     */
    public function testSaveSidePreservesOmittedKeysAndClearsPresentEmptyKeys(): void
    {
        $stored = $this->ruleObject();

        // Case A: operator saved without entering new keys — frontend OMITS the path.
        $incomingOmit = [
            'configuration' => [
                'authentication' => [
                    'type'   => 'api-key',
                    'users'  => ['alice'],
                    'groups' => ['admins'],
                    // no `keys` key at all
                ],
            ],
        ];
        $mergedOmit = $this->applyWriteOnlyPreserve($incomingOmit, $stored, $this->declaredPaths());
        $this->assertSame(
            $stored['configuration']['authentication']['keys'],
            $mergedOmit['configuration']['authentication']['keys'],
            'openregister#463 must carry the stored inbound keys forward when the payload OMITS the '
            .'write-only path — this is what stops a natural GET/edit/PUT round-trip from destroying them'
        );

        // Case B: payload sends an explicit empty `keys` — this is a PRESENT value, so it is NOT
        // preserved and PUT semantics clear it. This is the destruction path the frontend must avoid.
        $incomingEmpty = [
            'configuration' => [
                'authentication' => [
                    'type'   => 'api-key',
                    'users'  => ['alice'],
                    'groups' => ['admins'],
                    'keys'   => [],
                ],
            ],
        ];
        $mergedEmpty = $this->applyWriteOnlyPreserve($incomingEmpty, $stored, $this->declaredPaths());
        $this->assertSame(
            [],
            $mergedEmpty['configuration']['authentication']['keys'],
            'A present `keys: []` is NOT an omission — openregister#463 leaves it untouched and PUT '
            .'semantics clear the stored keys. This is why buildAuthenticationConfiguration must OMIT.'
        );
    }//end testSaveSidePreservesOmittedKeysAndClearsPresentEmptyKeys()

    /**
     * Simulate openregister#463's save-side preserve: for each declared write-only path absent from
     * the incoming payload but present in the stored object, carry the stored leaf value forward.
     * Mirrors PropertyRbacHandler::collectOmittedWriteOnlyPaths + restoreWriteOnlyValues (pathExists
     * uses array_key_exists, so a present `[]` counts as "client set it" and is NOT restored).
     *
     * @param array<string, mixed> $incoming The incoming update payload.
     * @param array<string, mixed> $stored   The RAW stored object.
     * @param array<int, string>   $paths    The declared write-only dot-paths.
     *
     * @return array<string, mixed>
     */
    private function applyWriteOnlyPreserve(array $incoming, array $stored, array $paths): array
    {
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            if ($this->pathExists($incoming, $segments) === true) {
                // Present (even as []) => client set it => not restored.
                continue;
            }

            if ($this->pathExists($stored, $segments) === false) {
                continue;
            }

            $incoming = $this->writePath($incoming, $segments, $this->readPath($stored, $segments));
        }

        return $incoming;
    }//end applyWriteOnlyPreserve()

    /**
     * array_key_exists-at-every-segment probe (a present null/[] reports true).
     *
     * @param array<string, mixed> $object   The array to probe.
     * @param array<int, string>   $segments The dot-path segments.
     *
     * @return bool
     */
    private function pathExists(array $object, array $segments): bool
    {
        $cursor = $object;
        foreach ($segments as $segment) {
            if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }//end pathExists()

    /**
     * Read the value at a dot-path (caller has checked pathExists).
     *
     * @param array<string, mixed> $object   The source array.
     * @param array<int, string>   $segments The dot-path segments.
     *
     * @return mixed
     */
    private function readPath(array $object, array $segments)
    {
        $cursor = $object;
        foreach ($segments as $segment) {
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }//end readPath()

    /**
     * Write a value at a dot-path, creating intermediate arrays as needed.
     *
     * @param array<string, mixed> $object   The array to mutate (copy returned).
     * @param array<int, string>   $segments The dot-path segments.
     * @param mixed                $value    The value to set.
     *
     * @return array<string, mixed>
     */
    private function writePath(array $object, array $segments, $value): array
    {
        $head = array_shift($segments);
        if ($segments === []) {
            $object[$head] = $value;
            return $object;
        }

        $child = [];
        if (isset($object[$head]) === true && is_array($object[$head]) === true) {
            $child = $object[$head];
        }

        $object[$head] = $this->writePath($child, $segments, $value);
        return $object;
    }//end writePath()
}//end class
