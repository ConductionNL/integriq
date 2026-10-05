# Design: opt-out per purpose and a read-only probe

## 1. Where things stand

| What | Where | Today |
|---|---|---|
| purpose is stored | `OptOutRowBuilder.php:91`, `:169-171` | yes, from the change event |
| purpose is read | `OptOutMatcher.php:72-120` | no. Scope, channel, case and list only. |
| dedupe key | `OptOut.php:253` | address, scope, ref, channel. No purpose. |
| v3 claims | `UnsubscribeTokenService.php:199-211` | `a`, `s`, `ch`, `r`, `e`. No purpose. |
| "stop everything" | `SenderIdentityController.php:309` | instance scope, no purpose |
| pipelinq writes a purpose | `IntegriqMarketingConsent.php:203`, `:241`, `:296` | `marketing` on every list consent, withdrawal and migrated `consentRecord` |
| pipelinq messaging consent | `ConsentService::recordInIntegriq`, `ConsentMigrationService` | no purpose: STOP and START keywords, migrated `messagingConsentRecord` |

## 2. How purposes map onto categories

hydra's section 4 lists the categories. Section 7 maps pipelinq's `consentRecord` to `purpose=marketing`. Nothing else names a purpose. So the purposes come from the categories, grouped by what a person wants to stop.

| Purpose | Covers | Written by |
|---|---|---|
| `marketing` | `marketing` | pipelinq list consent, withdrawal, migrated `consentRecord`; a link in a marketing message |
| `service` | `case-update`, `reminder`, `service` | a link in any of those messages |
| empty | every non-exempt category | "Stop everything", STOP keyword, migrated `messagingConsentRecord`, every row written before this change |

- The exempt floor (`besluit`, `statutory`, `account`, `security`) is never stopped. An opt-out of any purpose still logs an override when it would have matched.
- `reply` passes every opt-out, as today.
- A purpose given as a category name normalises to its group: `case-update` and `reminder` become `service`. `all` becomes empty. An unknown purpose becomes empty and logs a warning. Empty is the safe reading: it stops more, never less.

Why two groups and not one per category: Ruben named three wishes (marketing, case and service, everything). A reminder unsubscribe that left case updates running would surprise a person more than one that stops both. A case link already narrows itself by case scope.

## 3. Existing rows

**Rows without a purpose mean "stop everything" in their scope.** That is how every row behaves today. Reading them as "service only" would start marketing to people who opted out. No data moves for them.

Rows that already carry a purpose (pipelinq's marketing rows, written since pipelinq#2162) get a new dedupe key. Without that, pipelinq's next write for the same wish would compute the new key, miss the old row and insert a second one. The old row would then keep a withdrawn opt-out alive after a new consent. The migration recomputes `dedupe_key` for every row with a non-empty purpose. Keys with a purpose cannot collide with keys without one, so the step cannot fail on the unique index.

## 4. The dedupe key

`keyFor(address, scope, ref, channel, purpose)`. The purpose is appended only when set, the same way the channel is (`OptOut.php:255-257`). Every row without a purpose keeps its key.

## 5. Links

`mintScoped()` adds `p` when the purpose is set. `materialFor()` takes the category and sets `p` to its purpose. `inspect()` returns `purpose`, empty for v1, v2 and a v3 token without `p`.

| Choice on the page | Writes |
|---|---|
| `this` | the link's scope, channel, ref and purpose |
| `all` | instance scope, empty purpose |

The confirm page names what stops. For `marketing`: "You will no longer receive newsletters and campaigns". For `service`: the scope sentence as today. "Stop everything" is offered whenever the link is narrower than instance scope with an empty purpose.

## 6. Consent

`hasConsent()` uses the same coverage rule. A consent row with purpose `marketing` permits a marketing send. A row with an empty purpose permits any send that needs consent. pipelinq's business-initiated WhatsApp asks as `service` and reads the messaging opt-in rows, which carry no purpose. So nothing changes for it.

## 7. The probe

`OutboundSendDecisionRequestedEvent` gains `bool $probe = false` as its ninth constructor argument and `isProbe()`. The listener passes it to `decideMany(probe: true)`.

A probe:

- returns the same `send`, `code` and `reason` as a real ask
- returns `unsubscribe: null`, so no token is minted and no short link is stored
- writes no log row of any kind: no `suppressed`, no `override`, no `allowed-count`

The argument is last, so a sibling that passes eight arguments keeps working. A sibling that passes nine to an older integriq gets a real ask: PHP ignores the extra argument. The probe is a promise about the log, not about the answer, so that degrades safely.

## 8. Risks

- **A marketing row stops less than before.** That is the fix. The log shows every change.
- **An old v3 link in a marketing mail still stops everything** in its scope. Links live 90 days by default, so this fades.
- **A probe could be abused to read the list without a trace.** Only PHP code in the same process can dispatch the event. It gives the same power as reading the table.
