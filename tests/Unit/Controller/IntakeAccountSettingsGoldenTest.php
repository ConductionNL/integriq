<?php

/**
 * Golden tests for the DSO and Open Formulieren connection settings: every
 * response, consumer write and intake-group change over a scenario matrix.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DsoPkiSettingsController;
use OCA\Integriq\Controller\OpenFormulierenSettingsController;
use OCA\Integriq\Service\DSOSignatureVerifierService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the allowed and refused outcomes of both settings controllers.
 */
class IntakeAccountSettingsGoldenTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * Request parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * The request, the group manager and the l10n every controller gets.
	 *
	 * @return array{0:IRequest,1:IGroupManager,2:IL10N}
	 */
	private function seams(): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default));
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['admin', 'boss'], true));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return [$request, $groupManager, $l];

	}//end seams()

	/**
	 * The DSO settings controller over the world.
	 *
	 * @return DsoPkiSettingsController
	 */
	private function dso(): DsoPkiSettingsController {
		[$request, $groupManager, $l] = $this->seams();
		$logger = new NullLogger();

		return new DsoPkiSettingsController(
			request: $request,
			connection: $this->buildWorldConnection(objectService: $this->buildWorldObjectService()),
			signatureVerifier: new DSOSignatureVerifierService(new WebhookSignatureService($logger), $logger),
			groupManager: $groupManager,
			groups: $this->buildWorldIntakeGroups(),
			l: $l,
			logger: $logger
		);

	}//end dso()

	/**
	 * The Open Formulieren settings controller over the world.
	 *
	 * @return OpenFormulierenSettingsController
	 */
	private function openFormulieren(): OpenFormulierenSettingsController {
		[$request, $groupManager, $l] = $this->seams();

		return new OpenFormulierenSettingsController(
			request: $request,
			connection: $this->buildWorldOpenFormulierenConnection(objectService: $this->buildWorldObjectService()),
			groupManager: $groupManager,
			groups: $this->buildWorldIntakeGroups(),
			l: $l,
			logger: new NullLogger()
		);

	}//end openFormulieren()

	/**
	 * The world every scenario starts from.
	 *
	 * @param string $consumers Which consumers exist: none, one, two.
	 *
	 * @return void
	 */
	private function world(string $consumers): void {
		$this->resetWorld();
		$this->addAccount(uid: 'admin');
		$this->worldSession->setVolatileActiveUser($this->worldUser('admin'));
		$this->addAccount(uid: 'intake');
		$this->addAccount(uid: 'boss');
		$this->addAccount(uid: 'off', enabled: false);
		$this->addAccount(uid: 'reader', grants: ['read']);
		if ($consumers !== 'none') {
			$this->addDsoConsumer(userId: 'intake');
			$this->addOpenFormulierenConsumer(userId: 'intake');
		}

		if ($consumers === 'two') {
			$this->addDsoConsumer(userId: 'boss', uuid: 'consumer-dso-2');
			$this->addOpenFormulierenConsumer(userId: 'boss', uuid: 'consumer-of-2');
		}

	}//end world()

	/**
	 * Each scenario: the controller, the world, the parameters and the action.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>,3:string}>
	 */
	public static function scenarios(): array {
		$rows = [];
		foreach (['dso', 'openFormulieren'] as $controller) {
			foreach (['none', 'one', 'two'] as $consumers) {
				$rows[$controller . ' get, ' . $consumers] = [$controller, $consumers, [], 'getConfig'];
			}

			foreach (['', 'intake', 'boss', 'ghost', 'off', 'reader', 'admin'] as $uid) {
				foreach (['none', 'one'] as $consumers) {
					$rows[$controller . ' set ' . $uid . ', ' . $consumers] = [$controller, $consumers, ['userId' => ' ' . $uid . ' ', 'mode' => 'hmac', 'hmacSecret' => '', 'secret' => '', 'scheme' => 'openconnector'], 'setConfig'];
				}
			}

			$rows[$controller . ' set, two'] = [$controller, 'two', ['userId' => 'intake', 'mode' => 'hmac', 'scheme' => 'stripe'], 'setConfig'];
		}

		$rows['dso unknown mode'] = ['dso', 'one', ['userId' => 'intake', 'mode' => 'weird', 'hmacSecret' => 's3'], 'setConfig'];
		$rows['of unknown scheme'] = ['openFormulieren', 'one', ['userId' => 'intake', 'scheme' => 'nope'], 'setConfig'];
		$rows['of blank header and tolerance'] = ['openFormulieren', 'one', ['userId' => 'intake', 'header' => '  ', 'toleranceSeconds' => '-5', 'secret' => 'new'], 'setConfig'];

		return $rows;

	}//end scenarios()

	/**
	 * Every scenario answers, writes and changes the intake groups as before.
	 *
	 * @param string              $controller The controller.
	 * @param string              $consumers  Which consumers exist.
	 * @param array<string,mixed> $params     The request parameters.
	 * @param string              $action     The action.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
	public function testScenario(string $controller, string $consumers, array $params, string $action): void {
		$this->world($consumers);
		$this->params = $params;

		$response = $this->$controller()->$action();

		$outcome = [
			'status' => $response->getStatus(),
			'data' => $response->getData(),
			'consumers' => $this->worldConsumers,
			'members' => $this->worldGroupMembers,
			'writes' => count($this->worldWrites),
		];
		$this->assertSame(
			self::GOLDEN[$controller . '|' . $consumers . '|' . json_encode($params) . '|' . $action] ?? 'missing',
			hash('sha256', json_encode($outcome, JSON_THROW_ON_ERROR)),
			json_encode($outcome)
		);

	}//end testScenario()

	/**
	 * The recorded digests, per scenario.
	 *
	 * @var array<string,string>
	 */
	private const GOLDEN = [
		'dso|none|[]|getConfig' => 'e799ded2a56f0019c1142b0bbf843164192891b9d50fa680184d0475bd9e618e',
		'dso|one|[]|getConfig' => 'daa9481f32c1696de82991e578180ced40009d3e05302ed6b0be0459a7e3b5db',
		'dso|two|[]|getConfig' => '35c3cd50f5d59d7de49688b45db273ec61872b54ecdcc175b0b87bec2a804968',
		'dso|none|{"userId":"  ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '235ba9732f2f4979e83c0344c7b1520db44ce0d7dd1ceffd74f38452197f2b45',
		'dso|one|{"userId":"  ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'eb214c260bf2d2723b4e1d9644b0bacac9e9418f1b255f1eaf7cb4121d5dbe19',
		'dso|none|{"userId":" intake ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'a862d47a5e55612feb106802553c284aa30d95433e5d6b20790662572908b8fd',
		'dso|one|{"userId":" intake ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'c66297eb1962faa01d80964587ce7fe7c56e3ba5a1df1b0f76660f42c8236338',
		'dso|none|{"userId":" boss ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'c8284381632aa7e8fdc3f435fde7d62d873703d67ad2032aea010a567b58120b',
		'dso|one|{"userId":" boss ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'db0132a3405b62a203b19c764d21d54a93a94a7b3f2c6d2380a8efbd704714c4',
		'dso|none|{"userId":" ghost ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'a3774829361c98b45b06bf895b4a574185f51be7d7b010f1053585c9da71a667',
		'dso|one|{"userId":" ghost ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '425894edf5237751d1ba9acab76573ea98eb670d6ec3a3787481778b8a20d75c',
		'dso|none|{"userId":" off ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'efcf02afaad9f2f76796de5595514793c0c9597f966d3be9b98964228b9bf4e0',
		'dso|one|{"userId":" off ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '21bfe90c6cd89b1fa2c8dcc688d5187abedb96f5074cfafea73e895e9b947473',
		'dso|none|{"userId":" reader ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'edeaf8dbebd3d4c5c1a643a608d6edffc376f783f4a5112f6d13a867b6612cd9',
		'dso|one|{"userId":" reader ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'a7afb5c36b64bfefada81e4a645580a94d56af0d16a2a9d74c9dc36bba175975',
		'dso|none|{"userId":" admin ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'b6fef459cf3976c172d709267f850f423b69d0a9e7f94117609276dcd3e24787',
		'dso|one|{"userId":" admin ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '68a89c64ea194fd5bebdeeab6ca380a107cca9048e4338359fa24d7da54515c2',
		'dso|two|{"userId":"intake","mode":"hmac","scheme":"stripe"}|setConfig' => '31e511d0f9061d07d2fb7f750341c74fb6856fc2fd788988c3441ae46a5a564a',
		'openFormulieren|none|[]|getConfig' => 'a60a63c6b4916cdebc4855b0c63a9badba506dd2b8a19ad678b47ab62205170a',
		'openFormulieren|one|[]|getConfig' => 'f199d73b3327f240923fa63b3445517aff8e32f3ef8cb97ac3ceab6314691881',
		'openFormulieren|two|[]|getConfig' => '62056bea7b7ad6ba11bad27efc24f5b171b8ff789898ac0e616ac42b1bdddf6c',
		'openFormulieren|none|{"userId":"  ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '0cfb1aafde67b89a1deefc20f6348643d2add9b7959b675631c25d902c15d613',
		'openFormulieren|one|{"userId":"  ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '9f06a64a2da7b4bd7fbb9d7c9c6c35df9fa18cd8fbcbe770e1e1263d38027a46',
		'openFormulieren|none|{"userId":" intake ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '9cad96a6c28969c29b2b2e71017d29f6973e1415f4df251c8598f5411412dda0',
		'openFormulieren|one|{"userId":" intake ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'd3c8d1bb45970f26b36cb2ef2c3be927f296c7f212ee4dd970add7513f6495e5',
		'openFormulieren|none|{"userId":" boss ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '1acf7384f6d5f6a60d59bcfbf152f94b6712863aad6549bd5aa22bcd0b840e10',
		'openFormulieren|one|{"userId":" boss ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'd54b472926ca6a14570aa284cda03210de5fe870c7835d7bee0d64bd4970a511',
		'openFormulieren|none|{"userId":" ghost ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'a3774829361c98b45b06bf895b4a574185f51be7d7b010f1053585c9da71a667',
		'openFormulieren|one|{"userId":" ghost ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '425894edf5237751d1ba9acab76573ea98eb670d6ec3a3787481778b8a20d75c',
		'openFormulieren|none|{"userId":" off ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'efcf02afaad9f2f76796de5595514793c0c9597f966d3be9b98964228b9bf4e0',
		'openFormulieren|one|{"userId":" off ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '21bfe90c6cd89b1fa2c8dcc688d5187abedb96f5074cfafea73e895e9b947473',
		'openFormulieren|none|{"userId":" reader ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '735582c9348d804322623fddf8505b1ddf8ef774000f58a610fb51abe4e6a249',
		'openFormulieren|one|{"userId":" reader ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '20dab7888b26caa72bff1fa09e0d2d565d42a8bed93baa1162d44f59388bc909',
		'openFormulieren|none|{"userId":" admin ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => 'd0229e4e495452fe2a7519151b43748275f3c78e41412b6c33ec4fb1669de380',
		'openFormulieren|one|{"userId":" admin ","mode":"hmac","hmacSecret":"","secret":"","scheme":"openconnector"}|setConfig' => '1ee9315be82541d82054019f2c9316be029f45d1d416519f6e19ef20bbd2d217',
		'openFormulieren|two|{"userId":"intake","mode":"hmac","scheme":"stripe"}|setConfig' => 'a62a2018ab97751d716d52f720f828cededb33311ed724a2a614b6ce5b4d845c',
		'dso|one|{"userId":"intake","mode":"weird","hmacSecret":"s3"}|setConfig' => '41c7f4da828775935f5e8b525d80c68c8829513051b4594cbbca6b6f1ee96fc4',
		'openFormulieren|one|{"userId":"intake","scheme":"nope"}|setConfig' => '18093c381dd7519264d04625baac9d6d414799ab804b4126c155f5cbf06ba984',
		'openFormulieren|one|{"userId":"intake","header":"  ","toleranceSeconds":"-5","secret":"new"}|setConfig' => '8b035858456d99d9a92f164ccaf8a534c21ecc4a7409e1a8869344e5c601855b',
	];
}//end class
