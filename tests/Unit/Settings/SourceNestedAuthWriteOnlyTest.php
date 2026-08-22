<?php

/**
 * Guards the `source` schema's nested write-only auth paths (ocon#235 OAuth/JWT half +
 * ocon#242 direct-Guzzle half: encryptedToken / encryptedApiKey / mtls.*, incl. FSC's
 * directory-nested pair, plus the openregister#463 generic-editor round-trip that closes #245).
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
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

class SourceNestedAuthWriteOnlyTest extends TestCase {

	/**
	 * The annotation key openregister#459 resolves (Schema::WRITEONLY_PATHS_ANNOTATION).
	 *
	 * @var string
	 */
	private const ANNOTATION = 'x-openregister-writeonly-paths';

	/**
	 * The nested auth secrets this change strips.
	 *
	 * The first four are the ocon#235 OAuth/JWT half; the remainder are the ocon#242
	 * direct-Guzzle half, now safe to strip because ocon#244 re-resolves the source RAW
	 * (RawSourceResolver::resolveRaw -> find(_render:false)) before any of the six clients
	 * read a credential. `mtls` strips the WHOLE certificate bundle sub-tree; `mode` (its
	 * non-secret sibling discriminator) is deliberately NOT stripped. FSC nests its auth one
	 * level deeper under `directory`, so its two paths carry that extra segment.
	 *
	 * @var array<int, string>
	 */
	private const EXPECTED_PATHS = [
		'configuration.authentication.client_secret',
		'configuration.authentication.password',
		'configuration.authentication.secret',
		'configuration.authentication.private_key',
		'configuration.authentication.encryptedToken',
		'configuration.authentication.encryptedApiKey',
		'configuration.authentication.mtls',
		'configuration.directory.authentication.encryptedToken',
		'configuration.directory.authentication.mtls',
	];

	/**
	 * A representative source, as an operator authors it.
	 *
	 * @return array<string, mixed>
	 */
	private function sourceObject(): array {
		return [
			'name' => 'Some OAuth source',
			'configuration' => [
				'authentication' => [
					'authentication' => 'body',
					'grant_type' => 'client_credentials',
					'scope' => 'read',
					'tokenUrl' => 'https://idp.example/token',
					'client_id' => 'my-client-id',
					'client_secret' => 'SUPER-SECRET-CLIENT-SECRET',
					'username' => 'my-username',
					'password' => 'SUPER-SECRET-PASSWORD',
					'secret' => 'SUPER-SECRET-JWT-SIGNING-SECRET',
					'private_key' => 'SUPER-SECRET-PRIVATE-KEY',
					'algorithm' => 'RS256',
					// ocon#242 direct-Guzzle secrets (KISS/StufZkn/IStandaarden/Dso read
					// encryptedToken; RestNotifyNl reads encryptedApiKey; the mTLS clients
					// read the mtls.* bundle). `mode` is the non-secret discriminator.
					'mode' => 'mtls',
					'scheme' => 'Bearer',
					'encryptedToken' => 'SUPER-SECRET-ENCRYPTED-TOKEN',
					'encryptedApiKey' => 'SUPER-SECRET-ENCRYPTED-APIKEY',
					'mtls' => [
						'encryptedCertificate' => 'SUPER-SECRET-CERT-PEM',
						'encryptedPrivateKey' => 'SUPER-SECRET-KEY-PEM',
						'encryptedPassphrase' => 'SUPER-SECRET-PASSPHRASE',
						'encryptedCaBundle' => 'SUPER-SECRET-CA-PEM',
					],
				],
				// FSC nests its auth one level deeper: configuration.directory.authentication.*
				'directory' => [
					'directoryUrl' => 'https://directory.example',
					'authentication' => [
						'mode' => 'mtls',
						'scheme' => 'Bearer',
						'encryptedToken' => 'SUPER-SECRET-FSC-TOKEN',
						'mtls' => [
							'encryptedCertificate' => 'SUPER-SECRET-FSC-CERT-PEM',
							'encryptedPrivateKey' => 'SUPER-SECRET-FSC-KEY-PEM',
						],
					],
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
	private function effectiveSchema(): array {
		$root = dirname(__DIR__, 3);
		$descriptor = json_decode((string)file_get_contents($root . '/lib/Settings/openconnector_register.json'), true);

		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			$this->assertIsArray($fragment, "Fragment $fragmentPath must be valid JSON");
			$descriptor = $this->deepMerge($descriptor, $fragment);
		}

		return $descriptor['components']['schemas']['source'];
	}//end effectiveSchema()

	/**
	 * Recursive deep merge — mirrors InitializeRegister::deepMergeConfig (lists append,
	 * associative arrays recurse, scalars overwrite).
	 *
	 * @param array<mixed> $base The base.
	 * @param array<mixed> $overlay The overlay.
	 *
	 * @return array<mixed>
	 */
	private function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true && isset($base[$key]) === true && is_array($base[$key]) === true) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
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
	private function declaredPaths(): array {
		return (array)($this->effectiveSchema()[self::ANNOTATION] ?? []);
	}//end declaredPaths()

	/**
	 * Reproduce OpenRegister's render boundary: strip every DECLARED path (and its whole
	 * sub-tree) from an outgoing object. Mirrors PropertyRbacHandler::stripWriteOnlyPath.
	 *
	 * Driven by the declaration on purpose — see the class docblock.
	 *
	 * @param array<string, mixed> $object The outgoing object body.
	 * @param bool $_render Whether the read goes through the render boundary.
	 *
	 * @return array<string, mixed>
	 */
	private function simulateRead(array $object, bool $_render): array {
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
	 * @param array<string, mixed> $object The object to descend into.
	 * @param array<int, string> $segments The remaining path segments.
	 *
	 * @return array<string, mixed>
	 */
	private function stripPath(array $object, array $segments): array {
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
	public function testAnnotationDeclaresTheNestedAuthSecrets(): void {
		$declared = $this->declaredPaths();

		foreach (self::EXPECTED_PATHS as $path) {
			$this->assertContains(
				$path,
				$declared,
				"`source` must declare `$path` write-only — otherwise the API returns the credential "
				. 'in cleartext to every reader, admin included (ocon#235, openregister#459)'
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
	public function testRenderedReadMustNotReturnNestedAuthSecrets(): void {
		$rendered = $this->simulateRead($this->sourceObject(), true);
		$auth = $rendered['configuration']['authentication'];
		$fscAuth = $rendered['configuration']['directory']['authentication'];

		foreach (['client_secret', 'password', 'secret', 'private_key', 'encryptedToken', 'encryptedApiKey', 'mtls'] as $secret) {
			$this->assertArrayNotHasKey(
				$secret,
				$auth,
				"A rendered read must never return `configuration.authentication.$secret` — "
				. 'the render boundary is schema-gated and unconditional (admins included). '
				. 'If this fails, the write-only path declaration is missing or misspelled.'
			);
		}

		// FSC's deeper nesting: configuration.directory.authentication.{encryptedToken,mtls}.
		foreach (['encryptedToken', 'mtls'] as $secret) {
			$this->assertArrayNotHasKey(
				$secret,
				$fscAuth,
				"A rendered read must never return `configuration.directory.authentication.$secret` — "
				. 'FSC nests its auth under `directory`, and the path declaration must carry that segment.'
			);
		}

		// Belt and braces: no secret VALUE survives anywhere in the serialised payload,
		// including the `@self.relations` dot-path mirror shape. This covers every mtls
		// bundle member (encryptedCertificate/PrivateKey/Passphrase/CaBundle) too.
		$serialised = (string)json_encode($rendered);
		$leakables = [
			'SUPER-SECRET-CLIENT-SECRET',
			'SUPER-SECRET-PASSWORD',
			'SUPER-SECRET-JWT-SIGNING-SECRET',
			'SUPER-SECRET-PRIVATE-KEY',
			'SUPER-SECRET-ENCRYPTED-TOKEN',
			'SUPER-SECRET-ENCRYPTED-APIKEY',
			'SUPER-SECRET-CERT-PEM',
			'SUPER-SECRET-KEY-PEM',
			'SUPER-SECRET-PASSPHRASE',
			'SUPER-SECRET-CA-PEM',
			'SUPER-SECRET-FSC-TOKEN',
			'SUPER-SECRET-FSC-CERT-PEM',
			'SUPER-SECRET-FSC-KEY-PEM',
		];
		foreach ($leakables as $value) {
			$this->assertStringNotContainsString($value, $serialised, "The secret `$value` leaked through a rendered read");
		}
	}//end testRenderedReadMustNotReturnNestedAuthSecrets()

	/**
	 * The non-secret `mode` discriminator is a SIBLING of `mtls`, not a member of it, so the
	 * strip of the `mtls` sub-tree must leave `mode` (and `scheme`) readable — otherwise the
	 * editor can no longer show which transport (token/mtls) a source uses. Same for FSC's
	 * deeper-nested `mode`.
	 *
	 * @return void
	 */
	public function testMtlsModeDiscriminatorSurvivesTheStrip(): void {
		$rendered = $this->simulateRead($this->sourceObject(), true);
		$auth = $rendered['configuration']['authentication'];
		$fscAuth = $rendered['configuration']['directory']['authentication'];

		$this->assertSame('mtls', $auth['mode'], '`configuration.authentication.mode` is a non-secret discriminator and must survive');
		$this->assertSame('Bearer', $auth['scheme'], '`scheme` is a non-secret call parameter and must survive');
		$this->assertSame('mtls', $fscAuth['mode'], '`configuration.directory.authentication.mode` must survive too');

		foreach (['configuration.authentication.mode', 'configuration.directory.authentication.mode', 'configuration.authentication.scheme'] as $notSecret) {
			$this->assertNotContains(
				$notSecret,
				$this->declaredPaths(),
				"`$notSecret` must NOT be declared write-only — it is a discriminator/parameter, not a credential"
			);
		}
	}//end testMtlsModeDiscriminatorSurvivesTheStrip()

	/**
	 * THE ocon#245 PROOF — render->edit->save round-trip preserves the direct-Guzzle secrets.
	 *
	 * The source editor is the generic `CnFormDialog`: its edit mode clones the RENDERED
	 * (already-stripped) object via JSON.parse(JSON.stringify(item)), so every write-only path
	 * is ABSENT from the update payload the operator submits. openregister#463 PRESERVES an
	 * omitted write-only path on save instead of nulling it. This test replays that: strip on
	 * render, clone as the editor does, mutate a NON-secret field, then apply the openregister#463
	 * preserve, and assert the secrets survive. Removing the annotation from the fragment makes
	 * the render step leak the plaintext (testRenderedReadMustNotReturnNestedAuthSecrets fails);
	 * removing the preserve step here nulls the secrets (asserted below), so both halves are
	 * mutation-guarded.
	 *
	 * @return void
	 */
	public function testGenericEditorRoundTripPreservesDirectGuzzleSecrets(): void {
		$stored = $this->sourceObject();

		// 1) The API renders the source to the editor — write-only paths stripped/absent.
		$rendered = $this->simulateRead($stored, true);

		// 2) CnFormDialog edit mode: deep clone of the rendered object (no secret present).
		$editorPayload = json_decode((string)json_encode($rendered), true);

		// 3) Operator edits a NON-secret field and submits.
		$editorPayload['name'] = 'Renamed source';
		$editorPayload['configuration']['authentication']['scope'] = 'read write';

		// 4) openregister#463 save: an OMITTED write-only path is preserved from the stored object.
		$saved = $this->simulateOr463Save(stored: $stored, incoming: $editorPayload);

		// The non-secret edit landed.
		$this->assertSame('Renamed source', $saved['name']);
		$this->assertSame('read write', $saved['configuration']['authentication']['scope']);

		// Every direct-Guzzle secret SURVIVED the round-trip (this is what closes ocon#245).
		$auth = $saved['configuration']['authentication'];
		$fscAuth = $saved['configuration']['directory']['authentication'];
		$this->assertSame('SUPER-SECRET-ENCRYPTED-TOKEN', $auth['encryptedToken'], 'encryptedToken must survive the generic-editor round-trip (openregister#463)');
		$this->assertSame('SUPER-SECRET-ENCRYPTED-APIKEY', $auth['encryptedApiKey'], 'encryptedApiKey must survive');
		$this->assertSame('SUPER-SECRET-CERT-PEM', $auth['mtls']['encryptedCertificate'], 'the whole mtls bundle must survive');
		$this->assertSame('SUPER-SECRET-KEY-PEM', $auth['mtls']['encryptedPrivateKey'], 'the mtls private key must survive');
		$this->assertSame('SUPER-SECRET-FSC-TOKEN', $fscAuth['encryptedToken'], 'FSC directory-nested encryptedToken must survive');
		$this->assertSame('SUPER-SECRET-FSC-CERT-PEM', $fscAuth['mtls']['encryptedCertificate'], 'FSC directory-nested mtls must survive');

		// The top-level source secrets (apikey/secret/password/jwt/authenticationConfig) are
		// preserved by the SAME openregister#463 mechanism — assert the co-located client_secret.
		$this->assertSame('SUPER-SECRET-CLIENT-SECRET', $auth['client_secret'], 'nested OAuth secret preserved by the same mechanism');

		// MUTATION GUARD: without openregister#463's preserve, a naive replace nulls them.
		$naive = $editorPayload;
		$this->assertArrayNotHasKey(
			'encryptedToken',
			$naive['configuration']['authentication'],
			'Sanity: the editor payload really is missing the secret — the preserve is doing real work'
		);
	}//end testGenericEditorRoundTripPreservesDirectGuzzleSecrets()

	/**
	 * Reproduce openregister#463's save-side preserve: for every declared write-only path that
	 * is ABSENT in the incoming payload, carry the stored value forward. Everything the payload
	 * DOES supply overwrites. Mirrors SaveObject's omitted-write-only-path preservation.
	 *
	 * @param array<string, mixed> $stored The persisted object (secrets intact).
	 * @param array<string, mixed> $incoming The update payload (write-only paths omitted).
	 *
	 * @return array<string, mixed> The merged object as openregister#463 would persist it.
	 */
	private function simulateOr463Save(array $stored, array $incoming): array {
		$result = $incoming;
		foreach ($this->declaredPaths() as $path) {
			$segments = explode('.', $path);
			if ($this->hasPath($incoming, $segments) === true) {
				// Operator supplied a new value — it wins (a genuine credential rotation).
				continue;
			}

			$storedValue = $this->readPath($stored, $segments);
			if ($storedValue === null && $this->hasPath($stored, $segments) === false) {
				// Nothing stored either — nothing to preserve.
				continue;
			}

			$result = $this->writePath($result, $segments, $storedValue);
		}

		return $result;
	}//end simulateOr463Save()

	/**
	 * Whether a dot-path exists in a nested array.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param array<int, string> $segments The path segments.
	 *
	 * @return bool
	 */
	private function hasPath(array $object, array $segments): bool {
		$cursor = $object;
		foreach ($segments as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return false;
			}

			$cursor = $cursor[$segment];
		}

		return true;
	}//end hasPath()

	/**
	 * Read a dot-path from a nested array (null when absent).
	 *
	 * @param array<string, mixed> $object The object.
	 * @param array<int, string> $segments The path segments.
	 *
	 * @return mixed
	 */
	private function readPath(array $object, array $segments) {
		$cursor = $object;
		foreach ($segments as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end readPath()

	/**
	 * Write a value at a dot-path in a nested array, creating intermediate objects.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param array<int, string> $segments The path segments.
	 * @param mixed $value The value to set.
	 *
	 * @return array<string, mixed>
	 */
	private function writePath(array $object, array $segments, $value): array {
		$head = array_shift($segments);
		if ($segments === []) {
			$object[$head] = $value;
			return $object;
		}

		$child = ($object[$head] ?? []);
		if (is_array($child) === false) {
			$child = [];
		}

		$object[$head] = $this->writePath($child, $segments, $value);
		return $object;
	}//end writePath()

	/**
	 * All six direct-Guzzle bridges must re-resolve the source RAW (`_render: false`) before a
	 * client reads a credential — otherwise marking these paths write-only would strip the very
	 * secret the bridge needs and every bridge would fail closed. This is the ocon#244 read-side
	 * precondition that makes the ocon#242 strip safe; assert it stays true.
	 *
	 * @return void
	 */
	public function testAllSixDirectGuzzleBridgesReResolveTheSourceRaw(): void {
		$root = dirname(__DIR__, 3);
		$services = [
			'lib/Service/IwmoIjwSyncService.php',
			'lib/Service/DsoIngestService.php',
			'lib/Service/StufZknSyncService.php',
			'lib/Service/KissSyncService.php',
			'lib/Service/SmsDispatchService.php',
			'lib/Service/FscCallService.php',
		];

		foreach ($services as $service) {
			$code = (string)file_get_contents($root . '/' . $service);
			$this->assertStringContainsString(
				'resolveRaw',
				$code,
				"$service must route its active source through RawSourceResolver::resolveRaw() so the client "
				. 'reads the credential via `_render: false` (ocon#244). Without it the ocon#242 write-only strip '
				. 'would take this bridge down.'
			);
		}

		// The resolver itself reads with `_render: false` — the load-bearing argument.
		$resolver = (string)file_get_contents($root . '/lib/Service/Security/RawSourceResolver.php');
		$this->assertMatchesRegularExpression(
			'/_render:\s*false/',
			$resolver,
			'RawSourceResolver must call ObjectService::find() with `_render: false` — `_rbac: false` does NOT '
			. 'bring a schema-gated write-only path back (the ocon#212/#226 lesson).'
		);
	}//end testAllSixDirectGuzzleBridgesReResolveTheSourceRaw()

	/**
	 * The `@self.relations` mirror is keyed by LITERAL dot-paths (SaveObject::scanForRelations
	 * flattens nested values), so the same declaration must strip it there too.
	 *
	 * @return void
	 */
	public function testRelationsMirrorMustNotReturnNestedAuthSecrets(): void {
		$mirror = [
			'configuration.authentication.client_secret' => 'SUPER-SECRET-CLIENT-SECRET',
			'configuration.authentication.client_id' => 'my-client-id',
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
	public function testRawReadStillReturnsNestedAuthSecretsForTheEngine(): void {
		$raw = $this->simulateRead($this->sourceObject(), false);
		$auth = $raw['configuration']['authentication'];

		$this->assertSame('SUPER-SECRET-CLIENT-SECRET', $auth['client_secret'], 'The engine must still read client_secret via _render: false');
		$this->assertSame('SUPER-SECRET-PASSWORD', $auth['password'], 'The engine must still read password via _render: false');
		$this->assertSame('SUPER-SECRET-JWT-SIGNING-SECRET', $auth['secret'], 'The engine must still read the JWT secret via _render: false');
		$this->assertSame('SUPER-SECRET-PRIVATE-KEY', $auth['private_key'], 'The engine must still read private_key via _render: false');

		// The six direct-Guzzle clients depend on _render:false too (ocon#244/RawSourceResolver).
		$this->assertSame('SUPER-SECRET-ENCRYPTED-TOKEN', $auth['encryptedToken'], 'KISS/StufZkn/IStandaarden/Dso must still read encryptedToken via _render: false');
		$this->assertSame('SUPER-SECRET-ENCRYPTED-APIKEY', $auth['encryptedApiKey'], 'RestNotifyNl must still read encryptedApiKey via _render: false');
		$this->assertSame('SUPER-SECRET-CERT-PEM', $auth['mtls']['encryptedCertificate'], 'MtlsConfigResolver must still read the mtls bundle via _render: false');

		$fscAuth = $raw['configuration']['directory']['authentication'];
		$this->assertSame('SUPER-SECRET-FSC-TOKEN', $fscAuth['encryptedToken'], 'FSC must still read its directory-nested encryptedToken via _render: false');
		$this->assertSame('SUPER-SECRET-FSC-CERT-PEM', $fscAuth['mtls']['encryptedCertificate'], 'FSC must still read its directory-nested mtls via _render: false');
	}//end testRawReadStillReturnsNestedAuthSecretsForTheEngine()

	/**
	 * Identifiers are NOT secrets and must survive a rendered read — mirroring the
	 * top-level overlay, which marks apikey/secret/password/jwt but deliberately NOT
	 * username/jwtId. `authentication` is an auth-STRATEGY string, and `credentialRef`
	 * is the broker reference BrokeredCallService must keep reading.
	 *
	 * @return void
	 */
	public function testIdentifiersAndStrategyAreNotStripped(): void {
		$auth = $this->simulateRead($this->sourceObject(), true)['configuration']['authentication'];

		foreach (['username', 'client_id', 'authentication', 'grant_type', 'scope', 'tokenUrl', 'algorithm'] as $keep) {
			$this->assertArrayHasKey(
				$keep,
				$auth,
				"`configuration.authentication.$keep` is not a secret and must remain readable — "
				. 'stripping it would break the source editor and the operator UX for no security gain'
			);
		}

		$declared = $this->declaredPaths();
		foreach (['username', 'client_id', 'credentialRef', 'authentication'] as $notSecret) {
			$this->assertNotContains(
				'configuration.authentication.' . $notSecret,
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
	public function testEveryDeclaredPathIsWellFormedAndRooted(): void {
		$schema = $this->effectiveSchema();
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
				. 'openregister#459 ABORTS the save on an undeclared root segment'
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
	public function testSourceSchemaVersionWasBumped(): void {
		$this->assertSame(
			'1.4.0',
			($this->effectiveSchema()['version'] ?? null),
			'The `source` schema version must be bumped to 1.4.0 so the ocon#242 write-only path additions re-import'
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
	public function testCallServiceReResolvesTheSourceRawBeforeAuth(): void {
		$callService = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CallService.php');

		$this->assertMatchesRegularExpression(
			'/private function resolveSourceForDispatch.*?_render:\s*false/s',
			$callService,
			'CallService::resolveSourceForDispatch() MUST re-read the source with `_render: false`. '
			. 'Without it the nested write-only auth secrets are stripped from the entity the engine '
			. 'authenticates with, and every outbound call goes out UNAUTHENTICATED (ocon#215/#236).'
		);
	}//end testCallServiceReResolvesTheSourceRawBeforeAuth()
}//end class
