# Proposal: health-probe-skips-switched-off-sources

## Why

The hourly connection health job probes every linked source, including one that is switched off (`isEnabled` false). An example set links switched-off sources with made-up addresses to show the connections page. On a real instance the job would call those addresses every hour and mark the connections as failing. A switched-off source is one nothing calls, so the job has no reason to call it either.

## What changes

- `ConnectionProbeService::probeDue()` skips a row whose linked source is switched off.
- A source that cannot be read is still probed, and its probe says it is missing.
