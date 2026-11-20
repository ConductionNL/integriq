<?php

/**
 * Shared doubles for the Catalogi facade tests: a binding, tokens and stored types.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Catalogi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Catalogi;

use OCA\Integriq\Service\Catalogi\CatalogiBinding;
use OCA\Integriq\Service\Catalogi\CatalogiGate;
use OCA\Integriq\Service\Catalogi\CatalogiTokenService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridgeInterface;
use OCA\Integriq\Exception\AuthenticationException;

/**
 * Builds gates and tokens for the facade tests; used by a TestCase.
 */
trait CatalogiFixture {

	/**
	 * The served base URL.
	 *
	 * @var string
	 */
	public const BASE_URL = 'https://gemeente.example/index.php/apps/integriq/catalogi/api/v1';

	/**
	 * A bearer header whose payload names a client.
	 *
	 * @param string $clientId The client id.
	 *
	 * @return string
	 */
	private static function bearer(string $clientId): string {
		$encode = static fn (array $part): string => rtrim(strtr(base64_encode((string)json_encode($part)), '+/', '-_'), '=');

		return 'Bearer ' . $encode(['alg' => 'HS256']) . '.' . $encode(['client_id' => $clientId, 'iat' => 1]) . '.signature';
	}//end bearer()

	/**
	 * The binding: client `reader` holds catalogi.lezen, `concepts` may also read
	 * concepts, `stranger` holds another scope.
	 *
	 * @return CatalogiBinding
	 */
	private static function binding(): CatalogiBinding {
		return CatalogiBinding::fromObject(row: [
			'publishedUuid' => 'cat-1',
			'domein' => 'WOO',
			'rsin' => '002220647',
			'naam' => 'Documenttypen',
			'register' => 'documents',
			'schema' => 'documentType',
			'clients' => [
				['clientId' => 'reader', 'scopes' => ['catalogi.lezen']],
				['clientId' => 'concepts', 'scopes' => ['catalogi.lezen'], 'concepts' => true],
				['clientId' => 'stranger', 'scopes' => ['zaken.lezen']],
			],
		]);
	}//end binding()

	/**
	 * A token service whose JWT check passes unless the token says `invalid`.
	 *
	 * @return CatalogiTokenService
	 */
	private function tokens(): CatalogiTokenService {
		$bridge = $this->createMock(OpenRegisterCredentialBridgeInterface::class);
		$bridge->method('authorizeJwt')->willReturnCallback(
			static function (string $authorization): void {
				if (str_contains($authorization, 'invalid') === true) {
					throw new AuthenticationException('invalid');
				}
			}
		);

		return new CatalogiTokenService(credentials: $bridge);
	}//end tokens()

	/**
	 * A gate over the given binding.
	 *
	 * @param CatalogiBinding|null $binding The binding, or none.
	 *
	 * @return CatalogiGate
	 */
	private function gate(?CatalogiBinding $binding): CatalogiGate {
		return new CatalogiGate(bindingReader: static fn (): ?CatalogiBinding => $binding, tokens: $this->tokens());
	}//end gate()

}//end class
