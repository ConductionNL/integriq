<?php

/**
 * The order every Catalogi route keeps: binding, token, scope, and only then a read.
 *
 * 1. No binding, or one whose schema does not resolve: 404 `catalogue-not-configured`.
 * 2. No or an invalid ZGW JWT: 401.
 * 3. A client without `catalogi.lezen`: 403.
 * Only past all three may a handler read a document type. The binding is
 * integriq's own admin-only configuration and is read in the system context;
 * the document types are read only after the gate opened.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Catalogi
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

namespace OCA\Integriq\Service\Catalogi;

/**
 * Opens or refuses one request.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-only-an-authorised-client-reads-and-concept-types-stay-hidden-req-zcs-002
 */
class CatalogiGate {

	/**
	 * Resolves the binding.
	 *
	 * @var callable(): ?CatalogiBinding
	 */
	private $bindingReader;

	/**
	 * Constructor.
	 *
	 * @param callable(): ?CatalogiBinding $bindingReader Resolves the binding.
	 * @param CatalogiTokenService         $tokens        The token check.
	 */
	public function __construct(
		callable $bindingReader,
		private readonly CatalogiTokenService $tokens,
	) {
		$this->bindingReader = $bindingReader;

	}//end __construct()

	/**
	 * The binding and the client's concept permission, or the refusal.
	 *
	 * @param string $authorization The `Authorization` header.
	 *
	 * @return array{binding: CatalogiBinding|null, concepts: bool, refusal: array{status: int, body: array<string, mixed>}|null}
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-only-an-authorised-client-reads-and-concept-types-stay-hidden-req-zcs-002
	 */
	public function open(string $authorization): array {
		$binding = ($this->bindingReader)();
		if ($binding instanceof CatalogiBinding === false) {
			return self::refused(problem: (new CatalogiProblem(
				status: 404,
				code: 'catalogue-not-configured',
				title: 'No catalogue is configured.'
			))->answer());
		}

		$verdict = $this->tokens->verdict(authorization: $authorization, binding: $binding);
		if ($verdict['status'] === 401) {
			return self::refused(problem: (new CatalogiProblem(
				status: 401,
				code: 'not-authenticated',
				title: 'A valid ZGW JWT is required.'
			))->answer());
		}

		if ($verdict['status'] !== 200) {
			return self::refused(problem: (new CatalogiProblem(
				status: 403,
				code: 'permission-denied',
				title: 'The client does not hold the scope catalogi.lezen.'
			))->answer());
		}

		return ['binding' => $binding, 'concepts' => $verdict['concepts'], 'refusal' => null];
	}//end open()

	/**
	 * A refused gate.
	 *
	 * @param array{status: int, body: array<string, mixed>} $problem The answer.
	 *
	 * @return array{binding: null, concepts: false, refusal: array{status: int, body: array<string, mixed>}}
	 */
	private static function refused(array $problem): array {
		return ['binding' => null, 'concepts' => false, 'refusal' => $problem];
	}//end refused()

}//end class
