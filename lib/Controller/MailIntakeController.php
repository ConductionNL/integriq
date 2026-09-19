<?php

/**
 * Integriq MailIntakeController.
 *
 * The HTTP surface of mail intake: import a saved `.eml` or `.msg` onto a
 * mailbox source, and run one mailbox poll on demand. Both are gated by the
 * ADR-023 action matrix, admin-only until an operator broadens them, because
 * both write objects other apps act on.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
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
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\MailboxTransportException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Mail\MailboxSourceHandler;
use OCA\Integriq\Service\Mail\MailIntakeService;
use OCA\Integriq\Service\Mail\MessageParser;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Import and poll endpoints for mailbox sources.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
class MailIntakeController extends Controller {

	/**
	 * The ADR-023 action an import is gated by.
	 *
	 * @var string
	 */
	public const ACTION_IMPORT = 'mail.import';

	/**
	 * The ADR-023 action an on-demand poll is gated by.
	 *
	 * @var string
	 */
	public const ACTION_POLL = 'mail.poll';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Names the principal making the write.
	 * @param ActionAuthService $actionAuth The ADR-023 action gate.
	 * @param OrObjectService $orObjectService Reads the mailbox source.
	 * @param MessageParser $messageParser Parses the uploaded file.
	 * @param MailIntakeService $intakeService Takes the message in.
	 * @param MailboxSourceHandler $mailboxHandler Runs an on-demand poll.
	 * @param IL10N $l Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly OrObjectService $orObjectService,
		private readonly MessageParser $messageParser,
		private readonly MailIntakeService $intakeService,
		private readonly MailboxSourceHandler $mailboxHandler,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Import one `.eml` or `.msg` file onto a mailbox source.
	 *
	 * @param string $sourceId The mailbox source id.
	 *
	 * @return JSONResponse The created or already known message.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
	 */
	#[NoAdminRequired]
	public function import(string $sourceId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_IMPORT);

		if ($sourceId === '') {
			$sourceId = (string)$this->request->getParam('sourceId', '');
		}

		$source = $this->findMailbox(sourceId: $sourceId);
		if ($source === null) {
			return new JSONResponse(
				['error' => $this->l->t('No mailbox source with that id.')],
				Http::STATUS_NOT_FOUND
			);
		}

		$upload = $this->request->getUploadedFile('file');
		if (is_array($upload) === false || is_string(($upload['tmp_name'] ?? null)) === false) {
			return new JSONResponse(
				['error' => $this->l->t('Attach the message file as "file".')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$raw = file_get_contents($upload['tmp_name']);
		if ($raw === false || $raw === '') {
			return new JSONResponse(
				['error' => $this->l->t('The uploaded file was empty.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$parsed = $this->messageParser->parse((string)($upload['name'] ?? 'message.eml'), $raw);
		$configuration = $this->configurationOf(source: $source);

		$casePattern = ($configuration['casePattern'] ?? null);
		if ($casePattern !== null) {
			$casePattern = (string)$casePattern;
		}

		$message = $this->intakeService->intake(
			$sourceId,
			$parsed,
			$casePattern
		);

		return new JSONResponse(
			[
				'id' => (string)$message->getUuid(),
				'message' => $message->getObject(),
				'warning' => $parsed->getWarning(),
			],
			Http::STATUS_CREATED
		);

	}//end import()

	/**
	 * Run one poll of a mailbox source now.
	 *
	 * @param string $id The mailbox source id.
	 *
	 * @return JSONResponse What the poll did.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
	 */
	#[NoAdminRequired]
	public function poll(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_POLL);

		$source = $this->findMailbox(sourceId: $id);
		if ($source === null) {
			return new JSONResponse(
				['error' => $this->l->t('No mailbox source with that id.')],
				Http::STATUS_NOT_FOUND
			);
		}

		try {
			$result = $this->mailboxHandler->poll($source);
		} catch (MailboxTransportException $exception) {
			return new JSONResponse(
				['error' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse($result);

	}//end poll()

	/**
	 * Find a source and refuse one that is not a mailbox.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return ObjectEntity|null The source, or null when it is missing or not a mailbox.
	 */
	private function findMailbox(string $sourceId): ?ObjectEntity {
		if (trim($sourceId) === '') {
			return null;
		}

		try {
			$source = $this->orObjectService->find(
				id: $sourceId,
				register: MailIntakeService::REGISTER,
				schema: 'source',
			);
		} catch (DoesNotExistException) {
			return null;
		}

		if (($source instanceof ObjectEntity) === false) {
			return null;
		}

		$type = strtolower((string)($source->getObject()['type'] ?? ''));
		if ($type !== MailIntakeService::SOURCE_TYPE) {
			return null;
		}

		return $source;

	}//end findMailbox()

	/**
	 * A source's configuration block.
	 *
	 * @param ObjectEntity $source The source.
	 *
	 * @return array<string,mixed> The configuration.
	 */
	private function configurationOf(ObjectEntity $source): array {
		$configuration = ($source->getObject()['configuration'] ?? []);
		if (is_array($configuration) === false) {
			return [];
		}

		return $configuration;

	}//end configurationOf()

}//end class
