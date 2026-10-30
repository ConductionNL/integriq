# leaves-library-card

## Why

The "Synced from" leaf drew no card of its own on another app's record. nextcloud-vue now hosts a leaf in the library card
(integration-leaf-card, `chrome: "card"` + `onHeader`) and lists leaves in a `linked` card (linked-integrations-card,
`onSummary`), as the boards draw them.

## What changes

- The `sync-contract` descriptor declares `chrome: "card"`.
- Given `onHeader`, the leaf reports its count and empty state and drops its own empty line.
- Given `onSummary`, it draws nothing and reports its count, the noun "synchronizations" and a row per synchronization.
- An unavailable endpoint reports nothing, as before it reported no zero.
