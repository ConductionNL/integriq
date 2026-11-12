<?php

/**
 * Golden test for the OAuth call options AuthenticationService builds for the
 * client-credentials and password grants.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/authentication-twig/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\AuthenticationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Twig\Loader\ArrayLoader;

/**
 * Pins the call options, and the missing-parameter refusal, of both grants.
 */
class OAuthGrantConfigGoldenTest extends TestCase {

	/**
	 * The call options a private builder answers, or its refusal message.
	 *
	 * @param string              $builder       The private method.
	 * @param array<string,mixed> $configuration The configuration.
	 *
	 * @return array<string,mixed>|string
	 */
	private function build(string $builder, array $configuration): array|string {
		$method = new ReflectionMethod(AuthenticationService::class, $builder);
		try {
			return $method->invoke(new AuthenticationService(new ArrayLoader()), $configuration);
		} catch (BadRequestException $exception) {
			return $exception->getMessage();
		}

	}//end build()

	/**
	 * Each builder, configuration and answer.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>|string}>
	 */
	public static function configurations(): array {
		$client = ['grant_type' => 'client_credentials', 'scope' => 's', 'client_id' => 'id', 'client_secret' => 'sec'];
		$password = ['grant_type' => 'password', 'scope' => 's', 'username' => 'u', 'password' => 'p'];
		return [
			'client in body' => [
				'createClientCredentialConfig',
				$client + ['authentication' => 'body'],
				['form_params' => ['grant_type' => 'client_credentials', 'scope' => 's', 'client_id' => 'id', 'client_secret' => 'sec']],
			],
			'client basic' => [
				'createClientCredentialConfig',
				$client + ['authentication' => 'basic_auth'],
				['form_params' => ['grant_type' => 'client_credentials', 'scope' => 's'], 'auth' => ['username' => 'id', 'password' => 'sec']],
			],
			'client other' => [
				'createClientCredentialConfig',
				$client + ['authentication' => 'header', 'client_assertion_type' => 'other'],
				['form_params' => ['grant_type' => 'client_credentials', 'scope' => 's']],
			],
			'client missing' => [
				'createClientCredentialConfig',
				['scope' => 's', 'client_secret' => 'x'],
				'Some required parameters are not set: [grant_type,authentication,client_id]',
			],
			'password in body' => [
				'createPasswordConfig',
				$password + ['authentication' => 'body'],
				['form_params' => ['grant_type' => 'password', 'scope' => 's', 'username' => 'u', 'password' => 'p']],
			],
			'password basic' => [
				'createPasswordConfig',
				$password + ['authentication' => 'basic_auth'],
				['form_params' => ['grant_type' => 'password', 'scope' => 's'], 'auth' => ['username' => 'u', 'password' => 'p']],
			],
			'password other' => [
				'createPasswordConfig',
				$password + ['authentication' => 'x'],
				['form_params' => ['grant_type' => 'password', 'scope' => 's']],
			],
			'password missing' => [
				'createPasswordConfig',
				[],
				'Some required parameters are not set: [grant_type,scope,authentication,username,password]',
			],
		];

	}//end configurations()

	/**
	 * Each configuration builds the same call options, or the same refusal.
	 *
	 * @param string                     $builder       The private method.
	 * @param array<string,mixed>        $configuration The configuration.
	 * @param array<string,mixed>|string $expected      The call options or the message.
	 *
	 * @return void
	 */
	#[DataProvider('configurations')]
	public function testCallOptionsAreUnchanged(string $builder, array $configuration, array|string $expected): void {
		$this->assertSame($expected, $this->build($builder, $configuration));

	}//end testCallOptionsAreUnchanged()
}//end class
