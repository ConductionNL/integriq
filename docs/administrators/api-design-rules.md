# Checking an API product against the API design rules

Integriq checks an API product's description against the Dutch API Design
Rules before you publish it. You can add rule sets of your own next to them.
Only administrators can check a product, waive a finding or edit the rule sets.

## Check a product

1. Open **Gateway → API products** and choose the product.
2. In **API design rules**, press **Check design**.
3. The findings are listed with errors first, then warnings, then notes. Each
   one names the rule, says what is wrong, shows where in the description it
   is, and links to the rule's text.
4. Under **To check by hand** are the rules no tool can check, such as whether
   the description is published. A person has to look at these.

A new check replaces the product's open findings. A waiver is kept as long as
its rule still fails on the same path.

## Publishing is refused while an error is open

You can't set a product to **active** while any of its errors is still open.
The refusal lists the findings that block it. Warnings and notes never block.

If a description can't be checked at all, publishing is refused too: a
product nobody could check doesn't pass by default.

## Waive a finding

Some errors are false positives, or accepted for a reason you can explain. To
waive one:

1. Press **Waive** next to the open error.
2. Give the reason. A waiver without a reason is refused.
3. The finding now shows who waived it and why, and it no longer blocks
   publishing.

Your waivers stay with the product, so the reason can be read back later.

## Rule sets

**Gateway → API design rules** lists the rule sets a product is checked
against:

- **nl-api-design-rules**: the core rules of the Dutch API Design Rules, in the
  version they follow. A newer version of the rules arrives as a new rule set,
  and the old one is never edited.
- Your own rule sets. Each rule has an id, a title, a severity (error, warning
  or info), the part of the description it looks at and the check it runs, or
  `manual: true` for a rule a person checks. `gemeente-voorbeeld` is an
  example: it asks every operation for a summary.

Switch a rule set off with **enabled** to stop checking against it.
