---
title: Building a connector extension
sidebar_label: Connector extension kit
---

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->

# Building a connector extension

An extension is a separate Nextcloud app that adds an intake channel, a digital
post provider, a payment provider or connector templates to IntegrIQ. It never
replaces a built-in: a contribution that claims an id IntegrIQ already has is
refused, logged with your app id and class, and listed as refused by
`occ integriq:extensions`.

The skeleton in `examples/connector-extension/` is a complete extension that adds
one intake channel. Copy it, rename it, and change the adapter.

## 1. The app

- `examples/connector-extension/appinfo/info.xml` declares the app. Give it your own
  id and namespace.
- `examples/connector-extension/composer.json` maps your namespace to `lib/`.

## 2. Listen for the collect event

IntegrIQ dispatches `OCA\Integriq\Event\RegisterConnectorExtensionsEvent` once per
process, the first time one of its registries is used.

- `examples/connector-extension/lib/AppInfo/Application.php` registers one listener for it.
- `examples/connector-extension/lib/Listener/RegisterConnectorExtensions.php` adds the
  contribution with your app id: `addIntakeChannel()`, `addDigitalPostProvider()`,
  `addPaymentProvider()` or `addConnectorTemplates()` (a directory of templates, shown in
  the Store as "Provided by" your app).

## 3. The adapter

`examples/connector-extension/lib/Intake/WebhookChannel.php` implements
`OCA\Integriq\Intake\IntakeChannelAdapterInterface`:

- `getChannelId()` answers a stable slug (lower-case letters, digits and hyphens).
- `describe()` names the same channel and what it can do.
- `receive()` turns one delivery into an `InboundMessage` that keeps the channel's own
  id for it, and throws `IntakeChannelException` for a delivery it cannot read.
- `reply()` sends a reply, or answers `ReplyResult::unsupported()` when the channel
  cannot reply. It never falls back to another channel.

A payment provider implements `OCA\Integriq\Service\Payment\PaymentProviderInterface`
and a digital post provider `OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface`.

## 4. The contract tests

IntegrIQ ships one abstract PHPUnit case per seam in `tests/Contract/`:
`IntakeChannelContractTestCase`, `PaymentProviderContractTestCase` and
`DigitalPostProviderContractTestCase`. Extend the one for your seam, return your
adapter and a payload your channel really delivers, and every rule above runs
against your code.

- `examples/connector-extension/tests/Unit/WebhookChannelContractTest.php` runs the
  intake channel case on the skeleton's adapter.
- `examples/connector-extension/tests/Unit/RegisterConnectorExtensionsTest.php` checks
  that the listener contributes the channel under the app's id.
- `examples/connector-extension/tests/bootstrap.php` loads IntegrIQ's own test
  bootstrap from `INTEGRIQ_PATH` (by default the IntegrIQ checkout the skeleton sits in).
- `examples/connector-extension/phpunit.xml` runs them.

Run them from an IntegrIQ checkout with its Composer dependencies installed:

```bash
vendor/bin/phpunit -c examples/connector-extension/phpunit.xml
```

For your own app, point `INTEGRIQ_PATH` at an IntegrIQ checkout:

```bash
INTEGRIQ_PATH=/path/to/integriq vendor/bin/phpunit -c phpunit.xml
```

IntegrIQ's CI runs the skeleton's tests on every change to the kit
(`.github/workflows/connector-extension-kit.yml`), so the contract and the skeleton
never drift apart.

## 5. See it work

Enable your app next to IntegrIQ, then run `occ integriq:extensions`: your
contribution is listed with your app id, and a refused one says why.
