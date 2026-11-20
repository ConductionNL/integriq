<?php

/**
 * Integriq source host key controller.
 *
 * Shows the host key fingerprint an SFTP source's server presents and pins
 * it on the source once an administrator has confirmed it.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\SourceHostKeyStore;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The first test of a new SFTP source shows the server's fingerprint; the
 * administrator checks it with the partner and confirms it, and only then is
 * it saved as the source's pin. Pinning re-reads the key the server presents
 * and refuses a confirmed fingerprint that is not that key, so what is saved
 * is always what the server presented at that moment.
 *
 * Both endpoints are admin-only, as every other change to a source's
 * connection is.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class SourceHostKeyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string           $appName         The name of the app.
	 * @param IRequest         $request         The request.
	 * @param SourceHostKeyStore $sources Reads the source and its pin, and saves a new one.
	 * @param RemoteFileClient $client          Reads the host key the server presents.
	 * @param IL10N            $l               Translations.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SourceHostKeyStore $sources,
		private readonly RemoteFileClient $client,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The fingerprint the source's server presents, beside the pinned one.
	 *
	 * @param string $id The source's UUID.
	 *
	 * @return JSONResponse `pinned` (or null), `presented` and `matches`; 404 for an unknown
	 *                      source, 400 for one that is not SFTP, 502 when the server cannot be read.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function show(string $id): JSONResponse {
		$source = $this->sftpSource(id: $id);
		if ($source instanceof JSONResponse) {
			return $source;
		}

		$presented = $this->presented(source: $source);
		if ($presented instanceof JSONResponse) {
			return $presented;
		}

		$pinned = $this->sources->pinnedOf(source: $source);
		if ($pinned === '') {
			return new JSONResponse(['pinned' => null, 'presented' => $presented, 'matches' => false]);
		}

		return new JSONResponse(['pinned' => $pinned, 'presented' => $presented, 'matches' => hash_equals($pinned, $presented)]);
	}//end show()

	/**
	 * Pin the fingerprint the administrator confirmed, when the server still presents it.
	 *
	 * @param string $id The source's UUID.
	 *
	 * @return JSONResponse `pinned`; 400 without a fingerprint, 404 for an unknown source,
	 *                      409 when the server presents another key, 502 when it cannot be read.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function pin(string $id): JSONResponse {
		$confirmed = $this->sources->normalised(fingerprint: (string)$this->request->getParam('fingerprint', ''));
		if ($confirmed === '') {
			return $this->error(message: $this->l->t('Give the fingerprint you checked with the partner.'), status: Http::STATUS_BAD_REQUEST);
		}

		$source = $this->sftpSource(id: $id);
		if ($source instanceof JSONResponse) {
			return $source;
		}

		$presented = $this->presented(source: $source);
		if ($presented instanceof JSONResponse) {
			return $presented;
		}

		if (hash_equals($presented, $confirmed) === false) {
			$message = $this->l->t(
				'The server now presents %1$s, not the fingerprint you confirmed (%2$s). Nothing was saved; check it again.',
				[$presented, $confirmed]
			);
			return $this->error(message: $message, status: Http::STATUS_CONFLICT);
		}

		return $this->save(source: $source, fingerprint: $presented);
	}//end pin()

	/**
	 * The source, when it exists and is SFTP.
	 *
	 * @param string $id The source's UUID.
	 *
	 * @return ObjectEntity|JSONResponse The source, or the refusal.
	 */
	private function sftpSource(string $id): ObjectEntity|JSONResponse {
		$source = $this->sources->find(id: $id);
		if ($source === null) {
			return $this->error(message: $this->l->t('Not Found'), status: Http::STATUS_NOT_FOUND);
		}

		if (strtolower((string)($source->getObject()['type'] ?? '')) !== 'sftp') {
			return $this->error(message: $this->l->t('Only an SFTP source has a host key to pin.'), status: Http::STATUS_BAD_REQUEST);
		}

		return $source;
	}//end sftpSource()

	/**
	 * The fingerprint the source's server presents now.
	 *
	 * @param ObjectEntity $source The source.
	 *
	 * @return string|JSONResponse The fingerprint, or a 502 naming why it could not be read.
	 */
	private function presented(ObjectEntity $source): string|JSONResponse {
		try {
			return $this->client->presentedHostKey(source: $source->jsonSerialize());
		} catch (RemoteFileException $exception) {
			return $this->error(message: $exception->getMessage(), status: Http::STATUS_BAD_GATEWAY);
		}
	}//end presented()

	/**
	 * Save the fingerprint as the source's pin.
	 *
	 * @param ObjectEntity $source      The source.
	 * @param string       $fingerprint The fingerprint to pin.
	 *
	 * @return JSONResponse `pinned`, or 500 when the source was not saved.
	 */
	private function save(ObjectEntity $source, string $fingerprint): JSONResponse {
		$failure = $this->sources->pin(source: $source, fingerprint: $fingerprint);
		if ($failure !== null) {
			return $this->error(message: $this->l->t('The host key was not saved: %s', [$failure]), status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['pinned' => $fingerprint]);
	}//end save()

	/**
	 * An error response.
	 *
	 * @param string $message The message.
	 * @param int    $status  The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function error(string $message, int $status): JSONResponse {
		return new JSONResponse(['error' => $message], $status);
	}//end error()
}//end class
