<?php

/**
 * Unit tests for the GitHub source seed fragment.
 *
 * Reads the real `lib/Settings/register.d/github-source.json`, the file
 * OpenRegister imports, rather than a copy of it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-github-is-a-source-template-that-holds-only-a-credential-reference-req-ghp-001
 */
class GithubSourceFragmentTest extends TestCase {

	/**
	 * Keys that would hold a secret on a source.
	 *
	 * @var array<int, string>
	 */
	private const SECRET_KEYS = ['apikey', 'password', 'secret', 'jwt', 'token', 'client_secret', 'authorizationHeader', 'Authorization'];

	/**
	 * The seeded sources by slug.
	 *
	 * @return array<string, array<string, mixed>> The sources.
	 */
	private function sources(): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/github-source.json'), true);
		$this->assertIsArray($data, 'The fragment must be valid JSON.');

		$bySlug = [];
		foreach ($data['components']['objects'] as $object) {
			$this->assertSame('integriq', $object['@self']['register']);
			$this->assertSame('source', $object['@self']['schema']);
			$bySlug[$object['@self']['slug']] = $object;
		}

		return $bySlug;
	}//end sources()

	/**
	 * The API source is dormant, versioned and names its broker credential.
	 *
	 * @return void
	 */
	public function testTheApiSourceIsDormantAndNamesItsCredential(): void {
		$api = $this->sources()['github-api'];

		$this->assertSame('https://api.github.com', $api['location']);
		$this->assertFalse($api['isEnabled']);
		$this->assertSame('application/vnd.github+json', $api['configuration']['headers']['Accept']);
		$this->assertSame('2022-11-28', $api['configuration']['headers']['X-GitHub-Api-Version']);
		$this->assertSame(
			['credentialRef' => ['credentialName' => 'github-publiccode']],
			$api['configuration']['authentication'],
			'The authentication block must hold the reference and nothing beside it (REQ-SBC-001).'
		);

	}//end testTheApiSourceIsDormantAndNamesItsCredential()

	/**
	 * The raw source is enabled and needs no credential.
	 *
	 * @return void
	 */
	public function testTheRawSourceNeedsNoCredential(): void {
		$raw = $this->sources()['github-raw'];

		$this->assertSame('https://raw.githubusercontent.com', $raw['location']);
		$this->assertTrue($raw['isEnabled']);
		$this->assertArrayNotHasKey('authentication', $raw['configuration']);

	}//end testTheRawSourceNeedsNoCredential()

	/**
	 * Neither source carries a secret anywhere in its tree.
	 *
	 * @return void
	 */
	public function testNoSourceCarriesASecret(): void {
		foreach ($this->sources() as $slug => $source) {
			array_walk_recursive(
				$source,
				function ($value, $key) use ($slug): void {
					$this->assertNotContains(
						(string)$key,
						self::SECRET_KEYS,
						sprintf('Source %s carries a secret-shaped key "%s".', $slug, (string)$key)
					);
				}
			);
		}

	}//end testNoSourceCarriesASecret()
}//end class
