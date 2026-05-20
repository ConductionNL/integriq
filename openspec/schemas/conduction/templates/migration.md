# Migration: {{change_name}}

## Current State
<!-- What the schema/data looks like before this change -->

## Target State
<!-- What the schema/data should look like after this change -->

## Migration Class
<!-- Nextcloud migration class outline -->
```
Version: XXXXXXXXXX
File: lib/Migration/VersionXXXXXXXXXX.php
Key operations:
- ...
```

## Migration Steps
<!-- Ordered steps the migration executes. Each step MUST be atomic and verifiable. -->

1. <!-- Step description -->
2. <!-- Step description -->

## Data Impact
<!-- How many records are affected? Is there data loss or transformation? Can it run on live data? -->

## Rollback Procedure
<!-- How to revert if the migration fails — reverse migration class or SQL -->

## Validation
<!-- How to verify the migration succeeded — queries, checks, expected counts -->
