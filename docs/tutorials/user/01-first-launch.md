---
sidebar_position: 1
title: Open Integriq for the first time
description: Open Integriq, find your way around the navigation, and confirm the integration layer is wired up.
---

# Open Integriq for the first time

A first look at Integriq — where the app lives, what the navigation gives you, and how to tell it is wired up and ready to integrate.

## Goal

By the end you will have opened the Integriq app, recognised the dashboard and the left-hand navigation, and confirmed that the core lists (Sources, Endpoints, Mappings, Synchronizations, …) load without errors.

## Prerequisites

- A Nextcloud account on an instance where the **Integriq** app is installed and enabled.
- The **OpenRegister** app installed and enabled — Integriq writes synchronised data into OpenRegister registers and schemas, so it is a hard dependency for almost every flow.

## Steps

1. Open the Nextcloud app menu in the top bar and pick **Connector**. You land on the Integriq dashboard.

   ![Integriq dashboard](/screenshots/tutorials/user/01-first-launch-01.png)

2. Read the dashboard panels — synchronization runs, source health, recent errors. On a fresh install they are empty; they fill in as soon as you configure your first source and run a sync.

   ![Dashboard cards](/screenshots/tutorials/user/01-first-launch-02.png)

3. Open the left-hand navigation. The entries map one-to-one onto the Integriq concept model: **Sources**, **Endpoints**, **Consumers**, **Mappings**, **Jobs**, **Cloud Events**, **Synchronization**, **Rules**, **Import**. Each top-level item has a *Logs* sub-entry where every call is recorded for audit and debugging.

   ![Integriq navigation](/screenshots/tutorials/user/01-first-launch-03.png)

4. Click **Sources**. The list view opens with a **Add Source** button and a search sidebar. An empty install shows *No items found* — expected until you create the first source.

   ![Sources list, empty state](/screenshots/tutorials/user/01-first-launch-04.png)

## Verification

You are set up correctly when: the Integriq dashboard renders without an error banner, the left navigation lists the entries above, and clicking through to **Sources** (or any other list) shows either rows or a clean *No items found* state — not a load error.

## Common issues

| Symptom | Fix |
|---|---|
| "OpenRegister is not installed or enabled" banner | Install and enable the OpenRegister app, then reload Integriq. |
| Dashboard or list panel renders blank with a console error | Clear the browser cache with **Shift+F5** — the bundled JS sometimes lingers from a previous install. |
| Integriq is missing from the app menu | The app is not enabled for your account — ask an administrator to enable it (and check it is not restricted to a group you are not in). |

## Reference

- [Sources feature](../../features/sources.md) — full reference for the Source object.
- [Synchronizations feature](../../features/synchronizations.md) — how Integriq moves data between systems.
- [Configuration management](../../features/configuration-management.md) — import / export of full Integriq configurations.
