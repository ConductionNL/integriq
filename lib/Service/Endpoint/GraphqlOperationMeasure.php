<?php

/**
 * Integriq GraphqlOperationMeasure.
 *
 * Picks the operation a GraphQL request runs from the definitions of its
 * document, and measures it: how deep its selection nests once fragments are
 * expanded, and which fields it asks for at the root.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

use UnexpectedValueException;

/**
 * Chooses and measures the operation of a read GraphQL document.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */
class GraphqlOperationMeasure {

	/**
	 * The operation a request runs, with its depth and root fields.
	 *
	 * @param list<array<string, mixed>>                $operations    The operations of the document.
	 * @param array<string, list<array<string, mixed>>> $fragments     The fragments by name.
	 * @param string|null                               $operationName The `operationName` of the request, when given.
	 *
	 * @return GraphqlOperation
	 *
	 * @throws UnexpectedValueException When no single operation is named, or a fragment is unknown or cyclic.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function measure(array $operations, array $fragments, ?string $operationName): GraphqlOperation {
		$operation = $this->chosen(operations: $operations, operationName: $operationName);

		return new GraphqlOperation(
			type: $operation['type'],
			name: $operation['name'],
			depth: $this->depth(selections: $operation['selections'], fragments: $fragments, visiting: []),
			rootFields: $this->rootFields(selections: $operation['selections'], fragments: $fragments, visiting: [])
		);

	}//end measure()

	/**
	 * The operation the request asks for (GraphQL spec, GetOperation).
	 *
	 * @param list<array<string, mixed>> $operations    The operations of the document.
	 * @param string|null                $operationName The requested name.
	 *
	 * @return array<string, mixed> The operation.
	 *
	 * @throws UnexpectedValueException When none or several match.
	 */
	private function chosen(array $operations, ?string $operationName): array {
		if ($operations === []) {
			throw new UnexpectedValueException('The GraphQL document holds no operation.');
		}

		if ($operationName !== null && $operationName !== '') {
			foreach ($operations as $operation) {
				if ($operation['name'] === $operationName) {
					return $operation;
				}
			}

			throw new UnexpectedValueException('The GraphQL document holds no operation named "' . $operationName . '".');
		}

		if (count($operations) > 1) {
			throw new UnexpectedValueException('The GraphQL document holds several operations; name one in operationName.');
		}

		return $operations[0];

	}//end chosen()

	/**
	 * How deep a selection nests: a field counts one, a fragment counts nothing itself.
	 *
	 * @param list<array<string, mixed>>               $selections The selections.
	 * @param array<string, list<array<string, mixed>>> $fragments  The fragments by name.
	 * @param array<string, true>                      $visiting   The fragments being expanded.
	 *
	 * @return int The depth.
	 *
	 * @throws UnexpectedValueException On an unknown or cyclic fragment.
	 */
	private function depth(array $selections, array $fragments, array $visiting): int {
		$deepest = 0;
		foreach ($selections as $selection) {
			$depth = match ($selection['kind']) {
				'field' => (1 + $this->depth(selections: $selection['selections'], fragments: $fragments, visiting: $visiting)),
				'inline' => $this->depth(selections: $selection['selections'], fragments: $fragments, visiting: $visiting),
				default => $this->depth(
					selections: $this->fragment(name: $selection['name'], fragments: $fragments, visiting: $visiting),
					fragments: $fragments,
					visiting: ($visiting + [$selection['name'] => true])
				),
			};
			$deepest = max($deepest, $depth);
		}

		return $deepest;

	}//end depth()

	/**
	 * The names of the fields an operation asks for at its root.
	 *
	 * @param list<array<string, mixed>>               $selections The root selections.
	 * @param array<string, list<array<string, mixed>>> $fragments  The fragments by name.
	 * @param array<string, true>                      $visiting   The fragments being expanded.
	 *
	 * @return list<string> The field names, each once.
	 */
	private function rootFields(array $selections, array $fragments, array $visiting): array {
		$names = [];
		foreach ($selections as $selection) {
			if ($selection['kind'] === 'field') {
				$names[] = $selection['name'];
				continue;
			}

			$inner = $selection['selections'] ?? null;
			$next  = $visiting;
			if ($selection['kind'] === 'spread') {
				$inner = $this->fragment(name: $selection['name'], fragments: $fragments, visiting: $visiting);
				$next  = ($visiting + [$selection['name'] => true]);
			}

			$names = array_merge($names, $this->rootFields(selections: $inner, fragments: $fragments, visiting: $next));
		}

		return array_values(array_unique($names));

	}//end rootFields()

	/**
	 * The selections of a fragment named in a spread.
	 *
	 * @param string                                    $name      The fragment name.
	 * @param array<string, list<array<string, mixed>>> $fragments The fragments by name.
	 * @param array<string, true>                       $visiting  The fragments being expanded.
	 *
	 * @return list<array<string, mixed>> Its selections.
	 *
	 * @throws UnexpectedValueException On an unknown or cyclic fragment.
	 */
	private function fragment(string $name, array $fragments, array $visiting): array {
		if (isset($fragments[$name]) === false) {
			throw new UnexpectedValueException('The fragment "' . $name . '" is not defined.');
		}

		if (isset($visiting[$name]) === true) {
			throw new UnexpectedValueException('The fragment "' . $name . '" spreads itself.');
		}

		return $fragments[$name];

	}//end fragment()
}//end class
