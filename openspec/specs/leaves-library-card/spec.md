# leaves-library-card Specification

## Purpose
How integriq's `sync-contract` leaf (the synchronizations a record came from) reports its count, noun and rows to the shared library card and linked card in other apps, instead of drawing its own empty line. Created by change leaves-library-card (archived 2026-10-30); holds one requirement.

## Requirements

### Requirement: The sync leaf reports to the library card and the linked card

The `sync-contract` leaf SHALL declare `chrome: "card"`. Given `onHeader` it SHALL report its count and empty state once
read and SHALL NOT draw its own empty line. Given `onSummary` it SHALL draw nothing and SHALL report `{ count, noun, rows }`
once read, and nothing while loading or while the endpoint is unavailable.

#### Scenario: an object no synchronization created

- **GIVEN** an object that no synchronization created, in a linked card
- **THEN** the leaf reports count 0 with the noun "synchronizations"
