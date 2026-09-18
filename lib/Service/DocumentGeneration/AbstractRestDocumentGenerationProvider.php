<?php

/**
 * Integriq REST Document Generation Provider (shared half).
 *
 * The parts SmartDocuments and Xential share: credentials resolved by
 * reference through the OpenRegister credential broker, a mock mode with
 * fixtures, and one honest mapping from a transport failure to
 * `unreachable` rather than to `failed`.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DocumentGeneration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-credentials-are-resolved-by-reference-never-passed-by-value-req-dgv-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DocumentGeneration;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\BrokeredCallConfigurationException;
use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\Integriq\Service\BrokeredCallService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A vendor document generation API, reached over REST through the broker.
 *
 * No API key is ever an argument here. The source carries a `credentialRef`,
 * the broker resolves the material in-process, and a source without one is
 * refused at activation with a message naming what is missing. There is no
 * plaintext fallback.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-credentials-are-resolved-by-reference-never-passed-by-value-req-dgv-003
 */
abstract class AbstractRestDocumentGenerationProvider implements DocumentGenerationProviderInterface {

	/**
	 * Constructor.
	 *
	 * @param BrokeredCallService $brokeredCallService Dispatches through the credential broker.
	 * @param LoggerInterface $logger Logger for credential-free diagnostics.
	 */
	public function __construct(
		protected readonly BrokeredCallService $brokeredCallService,
		protected readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The vendor's path for listing templates.
	 *
	 * @return string The path, relative to the source's base URL.
	 */
	abstract protected function templatesPath(): string;

	/**
	 * The vendor's path for starting a render.
	 *
	 * @return string The path, relative to the source's base URL.
	 */
	abstract protected function renderPath(): string;

	/**
	 * The vendor's path for polling one render.
	 *
	 * @param string $providerJobId The vendor's job id.
	 *
	 * @return string The path, relative to the source's base URL.
	 */
	abstract protected function statusPath(string $providerJobId): string;

	/**
	 * The render envelope this vendor expects.
	 *
	 * @param string $templateId The vendor's template id.
	 * @param array $data The merge data.
	 *
	 * @return array<string, mixed> The request body.
	 */
	abstract protected function renderEnvelope(string $templateId, array $data): array;

	/**
	 * The fixtures this binding answers with in mock mode.
	 *
	 * @return array<int, array{id: string, name: string}> The fixture templates.
	 */
	abstract protected function fixtureTemplates(): array;

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The configuration schema every vendor binding shares.
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'required' => ['providerId'],
			'properties' => [
				'providerId' => ['type' => 'string', 'const' => $this->getProviderId()],
				'baseUrl' => ['type' => 'string', 'description' => 'The vendor API base URL.'],
				'mockMode' => [
					'type' => 'boolean',
					'description' => 'Answer from fixtures instead of calling the vendor. No credential needed.',
				],
				'authentication' => [
					'type' => 'object',
					'properties' => [
						'credentialRef' => [
							'type' => 'string',
							'description' => 'Reference to the credential held by the OpenRegister broker.',
						],
					],
				],
			],
		];

	}//end getConfigSchema()

	/**
	 * Refuse a source that cannot be used, naming what is missing.
	 *
	 * Called when an operator activates a source. A vendor binding without a
	 * `credentialRef` is refused here rather than at the first render, where
	 * the refusal would arrive as a failed beschikking.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 *
	 * @return void
	 *
	 * @throws DocumentGenerationException When the source cannot render.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#scenario-a-source-without-credentials-cannot-activate
	 */
	public function assertActivatable(array $sourceConfiguration): void {
		if ($this->isMockMode(sourceConfiguration: $sourceConfiguration) === true) {
			return;
		}

		if ($this->hasCredentialRef(sourceConfiguration: $sourceConfiguration) === false) {
			throw new DocumentGenerationException(
				message: 'The ' . $this->getProviderId() . ' binding needs '
					. '`configuration.authentication.credentialRef`, and this source carries none. '
					. 'Register the API key with the OpenRegister credential broker and reference it here. '
					. 'An API key written into the source itself is not accepted.'
			);
		}

		if (trim((string)($sourceConfiguration['baseUrl'] ?? '')) === '') {
			throw new DocumentGenerationException(
				message: 'The ' . $this->getProviderId() . ' binding needs `configuration.baseUrl`, '
					. 'and this source carries none.'
			);
		}

	}//end assertActivatable()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 *
	 * @return array<int, array{id: string, name: string}> The vendor's templates.
	 *
	 * @throws DocumentGenerationException When the source is unconfigured or the vendor cannot be reached.
	 */
	public function listTemplates(array $sourceConfiguration): array {
		if ($this->isMockMode(sourceConfiguration: $sourceConfiguration) === true) {
			return $this->fixtureTemplates();
		}

		$this->assertActivatable(sourceConfiguration: $sourceConfiguration);

		$decoded = $this->dispatchJson(
			sourceConfiguration: $sourceConfiguration,
			method: 'GET',
			path: $this->templatesPath()
		);

		$templates = [];
		foreach (array_values((array)($decoded['templates'] ?? $decoded['data'] ?? [])) as $template) {
			$template = (array)$template;
			$id = (string)($template['id'] ?? $template['templateId'] ?? '');
			if ($id === '') {
				continue;
			}

			$templates[] = ['id' => $id, 'name' => (string)($template['name'] ?? $id)];
		}

		return $templates;

	}//end listTemplates()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 * @param string $templateId The vendor's template id.
	 * @param array $data The merge data.
	 *
	 * @return RenderOutcome What the vendor answered.
	 */
	public function render(array $sourceConfiguration, string $templateId, array $data): RenderOutcome {
		if ($this->isMockMode(sourceConfiguration: $sourceConfiguration) === true) {
			return RenderOutcome::queued(
				providerJobId: 'MOCK-' . strtoupper($this->getProviderId()) . '-' . substr(
					hash('sha256', $templateId . json_encode($data)),
					0,
					12
				),
				detail: 'Mock mode: the vendor was not called'
			);
		}

		try {
			$this->assertActivatable(sourceConfiguration: $sourceConfiguration);

			$decoded = $this->dispatchJson(
				sourceConfiguration: $sourceConfiguration,
				method: 'POST',
				path: $this->renderPath(),
				body: $this->renderEnvelope(templateId: $templateId, data: $data)
			);
		} catch (DocumentGenerationException $exception) {
			if ($exception->isUnreachable() === true) {
				return RenderOutcome::unreachable(detail: $exception->getMessage());
			}

			return RenderOutcome::failed(detail: $exception->getMessage());
		}//end try

		return $this->outcomeFromBody(body: $decoded);

	}//end render()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 * @param string $providerJobId The vendor's job id.
	 *
	 * @return RenderOutcome The current outcome.
	 */
	public function status(array $sourceConfiguration, string $providerJobId): RenderOutcome {
		if ($this->isMockMode(sourceConfiguration: $sourceConfiguration) === true) {
			return RenderOutcome::rendered(
				providerJobId: $providerJobId,
				fileReference: 'mock:' . $providerJobId,
				detail: 'Mock mode: a fixture document'
			);
		}

		try {
			$decoded = $this->dispatchJson(
				sourceConfiguration: $sourceConfiguration,
				method: 'GET',
				path: $this->statusPath(providerJobId: $providerJobId)
			);
		} catch (DocumentGenerationException $exception) {
			if ($exception->isUnreachable() === true) {
				return RenderOutcome::unreachable(
					detail: $exception->getMessage(),
					providerJobId: $providerJobId
				);
			}

			return RenderOutcome::failed(detail: $exception->getMessage(), providerJobId: $providerJobId);
		}//end try

		return $this->outcomeFromBody(body: $decoded, providerJobId: $providerJobId);

	}//end status()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 * @param string $fileReference The reference a `rendered` outcome carried.
	 *
	 * @return string The document bytes.
	 *
	 * @throws DocumentGenerationException When the document cannot be fetched.
	 */
	public function fetch(array $sourceConfiguration, string $fileReference): string {
		if ($this->isMockMode(sourceConfiguration: $sourceConfiguration) === true) {
			return "%PDF-1.4 fixture\nReference: " . $fileReference . "\n";
		}

		$this->assertActivatable(sourceConfiguration: $sourceConfiguration);

		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			method: 'GET',
			path: '/documents/' . rawurlencode($fileReference)
		);

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new DocumentGenerationException(
				message: 'The ' . $this->getProviderId() . ' vendor answered HTTP ' . $status
					. ' for document ' . $fileReference . '.'
			);
		}

		return (string)$response->getBody();

	}//end fetch()

	/**
	 * Map a vendor response body onto one outcome.
	 *
	 * A body that says "done" without naming a document is NOT reported as
	 * rendered. A render with nothing to show for it is not a render, and
	 * filing it as one puts an empty beschikking in a case file.
	 *
	 * @param array $body The decoded response body.
	 * @param string $providerJobId The job id already known, when there is one.
	 *
	 * @return RenderOutcome The outcome.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	protected function outcomeFromBody(array $body, string $providerJobId = ''): RenderOutcome {
		$jobId = (string)($body['jobId'] ?? $body['id'] ?? $providerJobId);
		$status = strtolower((string)($body['status'] ?? ''));
		$fileReference = (string)($body['documentId'] ?? $body['fileReference'] ?? '');

		if (in_array($status, ['failed', 'error', 'rejected'], true) === true) {
			return RenderOutcome::failed(
				detail: (string)($body['message'] ?? 'The vendor refused this render'),
				providerJobId: $jobId
			);
		}

		if (in_array($status, ['done', 'ready', 'rendered', 'completed'], true) === true) {
			if ($fileReference === '') {
				return RenderOutcome::failed(
					detail: 'The vendor reported the render as done and named no document. '
						. 'Nothing is filed for a render with no document.',
					providerJobId: $jobId
				);
			}

			return RenderOutcome::rendered(providerJobId: $jobId, fileReference: $fileReference);
		}

		return RenderOutcome::queued(providerJobId: $jobId, detail: 'The vendor is working on it');

	}//end outcomeFromBody()

	/**
	 * Whether this source answers from fixtures.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 *
	 * @return boolean True in mock mode.
	 */
	protected function isMockMode(array $sourceConfiguration): bool {
		return ($sourceConfiguration['mockMode'] ?? false) === true;

	}//end isMockMode()

	/**
	 * Whether the source carries a credential reference.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 *
	 * @return boolean True when the broker has something to resolve.
	 */
	protected function hasCredentialRef(array $sourceConfiguration): bool {
		return $this->brokeredCallService->hasCredentialRef(
			config: ['authentication' => ($sourceConfiguration['authentication'] ?? [])]
		);

	}//end hasCredentialRef()

	/**
	 * Dispatch one brokered call and decode its JSON body.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 * @param string $method The HTTP method.
	 * @param string $path The path, relative to the base URL.
	 * @param array|null $body The request body, when there is one.
	 *
	 * @return array<string, mixed> The decoded body.
	 *
	 * @throws DocumentGenerationException On a refusal, and marked unreachable on a transport failure.
	 */
	protected function dispatchJson(
		array $sourceConfiguration,
		string $method,
		string $path,
		?array $body = null,
	): array {
		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			method: $method,
			path: $path,
			body: $body
		);

		$status = $response->getStatusCode();
		$raw = (string)$response->getBody();

		if ($status >= 500) {
			// The vendor is there and broken, which is the same to us as not
			// being there: nobody knows whether the render happened.
			throw (new DocumentGenerationException(
				message: 'The ' . $this->getProviderId() . ' vendor answered HTTP ' . $status
					. '. Nobody can say yet whether the document was produced.'
			))->asUnreachable();
		}

		if ($status < 200 || $status >= 300) {
			throw new DocumentGenerationException(
				message: 'The ' . $this->getProviderId() . ' vendor refused the request with HTTP ' . $status . '.'
			);
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			throw new DocumentGenerationException(
				message: 'The ' . $this->getProviderId() . ' vendor answered something that is not JSON.'
			);
		}

		return $decoded;

	}//end dispatchJson()

	/**
	 * Dispatch one brokered call.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 * @param string $method The HTTP method.
	 * @param string $path The path, relative to the base URL.
	 * @param array|null $body The request body, when there is one.
	 *
	 * @return Response The raw response.
	 *
	 * @throws DocumentGenerationException On a configuration or transport failure.
	 */
	protected function dispatch(
		array $sourceConfiguration,
		string $method,
		string $path,
		?array $body = null,
	): Response {
		$config = ['authentication' => ($sourceConfiguration['authentication'] ?? [])];
		if ($body !== null) {
			$config['body'] = json_encode($body);
			$config['headers'] = ['Content-Type' => ['application/json']];
		}

		$url = rtrim((string)($sourceConfiguration['baseUrl'] ?? ''), '/') . $path;

		try {
			$dispatch = $this->brokeredCallService->prepare(
				config: $config,
				sourceData: ['type' => 'documentGeneration'],
				asynchronous: false
			);

			return $this->brokeredCallService->dispatch(
				credentialId: $dispatch['credentialId'],
				actingUserId: $dispatch['actingUserId'],
				method: $method,
				url: $url,
				config: $config
			);
		} catch (BrokeredCallConfigurationException $exception) {
			throw new DocumentGenerationException(
				message: $exception->getMessage(),
				previous: $exception
			);
		} catch (Throwable $exception) {
			// The message carries the vendor and the URL, never the payload
			// and never the credential: the broker holds the material and
			// this class never sees it.
			$this->logger->warning(
				'[' . static::class . '] the vendor could not be reached',
				['providerId' => $this->getProviderId(), 'exception' => $exception->getMessage()]
			);

			throw (new DocumentGenerationException(
				message: 'The ' . $this->getProviderId() . ' vendor at ' . $url . ' could not be reached: '
					. $exception->getMessage() . '. Nobody can say yet whether the document was produced.',
				previous: $exception
			))->asUnreachable();
		}//end try

	}//end dispatch()
}//end class
