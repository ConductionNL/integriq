<?php

/**
 * The page that hands an LTI launch to the tool.
 *
 * It posts the signed id_token and the tool's own state to the tool's
 * registered redirect URI, at once when scripts run, or on a click when
 * they do not. Nothing else is on the page, so nothing else is posted.
 *
 * @var array $_ The template variables.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

?>
<form id="integriq-lti-autopost" method="post" action="<?php p($_['redirectUri']); ?>">
	<input type="hidden" name="id_token" value="<?php p($_['idToken']); ?>">
	<input type="hidden" name="state" value="<?php p($_['state']); ?>">
	<button type="submit"><?php p($_['continueLabel']); ?></button>
</form>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">document.getElementById('integriq-lti-autopost').submit();</script>
