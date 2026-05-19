Logging
---
# Expiration
Logs are provided with an expiration date upon creation. The expiry period varies per status of the log. Success logs are usually
retained only for a short period, while error logs can be retained for a longer period.

To update global expiry periods you can edit the retaining periods in the settings page. The success logs here have a global retaining period for all types of logs.
Also, you can update the retaining period of the logs per source or job. At this point in time the log will be created with the longest of these two retaining periods.
The roadmap contains updating this to using the most specific setting if given.
