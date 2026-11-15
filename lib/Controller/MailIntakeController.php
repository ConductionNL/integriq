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
 * @spec openspec/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\MailboxTransportException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Mail\MailboxSourceHandlerInterface;
use OCA\Integriq\Service\Mail\MailIntakeServiceInterface;
use OCA\Integriq\Service\Mail\MessageParserInterface;
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
 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
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
	 * @param ActionAuthServiceInterface $actionAuth The ADR-023 action gate.
	 * @param OrObjectService $orObjectService Reads the mailbox source.
	 * @param MessageParserInterface $messageParser Parses the uploaded file.
	 * @param MailIntakeServiceInterface $intakeService Takes the message in.
	 * @param MailboxSourceHandlerInterface $mailboxHandler Runs an on-demand poll.
	 * @param IL10N $l Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly OrObjectService $orObjectService,
		private readonly MessageParserInterface $messageParser,
		private readonly MailIntakeServiceInterface $intakeService,
		private readonly MailboxSourceHandlerInterface $mailboxHandler,
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
	 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
	 */
	#[NoAdminRequired]
	public function import(string $sourceId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_IMPORT);

		$sourceId = $this->requestedSourceId(sourceId: $sourceId);
		$source = $this->findMailbox(sourceId: $sourceId);
		if ($source === null) {
			return $this->noMailbox();
		}

		return $this->importUpload(sourceId: $sourceId, source: $source);

	}//end import()

	/**
	 * The source id from the path, else from the `sourceId` request parameter.
	 *
	 * @param string $sourceId The source id from the path.
	 *
	 * @return string
	 */
	private function requestedSourceId(string $sourceId): string {
		if ($sourceId === '') {
			return (string)$this->request->getParam('sourceId', '');
		}

		return $sourceId;

	}//end requestedSourceId()

	/**
	 * The 404 for a source that is missing or not a mailbox.
	 *
	 * @return JSONResponse
	 */
	private function noMailbox(): JSONResponse {
		return new JSONResponse(
			['error' => $this->l->t('No mailbox source with that id.')],
			Http::STATUS_NOT_FOUND
		);

	}//end noMailbox()

	/**
	 * Take the uploaded message in on the mailbox and answer it with 201.
	 *
	 * @param string       $sourceId The mailbox source id.
	 * @param ObjectEntity $source   The mailbox source.
	 *
	 * @return JSONResponse The created or already known message, or the 400 for a bad upload.
	 */
	private function importUpload(string $sourceId, ObjectEntity $source): JSONResponse {
		$upload = $this->readUpload();
		if ($upload instanceof JSONResponse) {
			return $upload;
		}

		$parsed = $this->messageParser->parse($upload['name'], $upload['raw']);
		$message = $this->intakeService->intake(
			$sourceId,
			$parsed,
			$this->casePatternOf(source: $source)
		);

		return $this->importedResponse(message: $message, warning: $parsed->getWarning());

	}//end importUpload()

	/**
	 * The uploaded `file`'s name and bytes; 400 when it is missing or empty.
	 *
	 * @return array{name: string, raw: string}|JSONResponse
	 */
	private function readUpload(): array|JSONResponse {
		$upload = $this->uploadedFile();
		if ($upload instanceof JSONResponse) {
			return $upload;
		}

		$raw = file_get_contents($upload['tmp_name']);
		if ($raw === false || $raw === '') {
			return new JSONResponse(
				['error' => $this->l->t('The uploaded file was empty.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return ['name' => (string)($upload['name'] ?? 'message.eml'), 'raw' => $raw];

	}//end readUpload()

	/**
	 * The uploaded `file` with its temporary path; 400 without one.
	 *
	 * @return array<string, mixed>|JSONResponse
	 */
	private function uploadedFile(): array|JSONResponse {
		$upload = $this->request->getUploadedFile('file');
		if (is_array($upload) === false || is_string(($upload['tmp_name'] ?? null)) === false) {
			return new JSONResponse(
				['error' => $this->l->t('Attach the message file as "file".')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $upload;

	}//end uploadedFile()

	/**
	 * The mailbox's case pattern as a string, or null without one.
	 *
	 * @param ObjectEntity $source The mailbox source.
	 *
	 * @return string|null
	 */
	private function casePatternOf(ObjectEntity $source): ?string {
		$casePattern = ($this->configurationOf(source: $source)['casePattern'] ?? null);
		if ($casePattern === null) {
			return null;
		}

		return (string)$casePattern;

	}//end casePatternOf()

	/**
	 * The 201 answer naming the message and the parser's warning.
	 *
	 * @param ObjectEntity $message The created or already known message.
	 * @param string|null  $warning What the parser warns about the upload.
	 *
	 * @return JSONResponse
	 */
	private function importedResponse(ObjectEntity $message, ?string $warning): JSONResponse {
		return new JSONResponse(
			[
				'id' => (string)$message->getUuid(),
				'message' => $message->getObject(),
				'warning' => $warning,
			],
			Http::STATUS_CREATED
		);

	}//end importedResponse()

	/**
	 * Whether the signed-in user may import a mail file, and into which mailboxes.
	 *
	 * The inbox page asks this before it offers "Import file (.eml or .msg)",
	 * so a user without the import right is not offered an action that would
	 * only answer 403. The mailboxes are listed only to a user who may import.
	 *
	 * @return JSONResponse `{"allowed": bool, "mailboxes": [{"id", "name"}]}`, or 401.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
	 */
	#[NoAdminRequired]
	public function importOptions(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->actionAuth->can(user: $user, action: self::ACTION_IMPORT) === false) {
			return new JSONResponse(['allowed' => false, 'mailboxes' => []]);
		}

		return new JSONResponse(['allowed' => true, 'mailboxes' => $this->mailboxes()]);

	}//end importOptions()

	/**
	 * The mailbox sources a file can be imported under, by name.
	 *
	 * @return array<int, array{id: string, name: string}> The mailboxes.
	 *
	 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
	 */
	private function mailboxes(): array {
		$found = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => MailIntakeServiceInterface::REGISTER,
					'schema'   => 'source',
					'type'     => MailIntakeServiceInterface::SOURCE_TYPE,
				],
			]
		);
		if (isset($found['results']) === true && is_array($found['results']) === true) {
			$found = $found['results'];
		}

		$mailboxes = [];
		foreach ($found as $source) {
			$mailbox = $this->mailboxOf(source: $source);
			if ($mailbox !== null) {
				$mailboxes[] = $mailbox;
			}
		}

		usort($mailboxes, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

		return $mailboxes;

	}//end mailboxes()

	/**
	 * One listed source as a mailbox option, or null when it is not a mailbox.
	 *
	 * @param mixed $source The source, as an entity or as its data.
	 *
	 * @return array{id: string, name: string}|null The option.
	 */
	private function mailboxOf(mixed $source): ?array {
		$data = $source;
		$id   = '';
		if ($source instanceof ObjectEntity) {
			$data = $source->getObject();
			$id   = (string)$source->getUuid();
		}

		if (is_array($data) === false
			|| strtolower((string)($data['type'] ?? '')) !== MailIntakeServiceInterface::SOURCE_TYPE
		) {
			return null;
		}

		if ($id === '') {
			$id = (string)($data['id'] ?? ($data['uuid'] ?? ''));
		}

		if ($id === '') {
			return null;
		}

		return ['id' => $id, 'name' => (string)($data['name'] ?? $id)];

	}//end mailboxOf()

	/**
	 * Run one poll of a mailbox source now.
	 *
	 * @param string $id The mailbox source id.
	 *
	 * @return JSONResponse What the poll did.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
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
				register: MailIntakeServiceInterface::REGISTER,
				schema: 'source',
			);
		} catch (DoesNotExistException) {
			return null;
		}

		if (($source instanceof ObjectEntity) === false) {
			return null;
		}

		$type = strtolower((string)($source->getObject()['type'] ?? ''));
		if ($type !== MailIntakeServiceInterface::SOURCE_TYPE) {
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
