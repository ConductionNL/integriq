<?php

/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

use OCP\Util;

$appId = OCA\Integriq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-main');
Util::addStyle($appId, 'main');
?>
<div id="integriq"></div>


