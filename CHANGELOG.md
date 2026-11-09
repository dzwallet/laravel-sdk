# Changelog

## 0.2.0
- Signed notifications v2: signature over the exact raw body, signed timestamp (5 minute window), unique event id
- `verifyNotification()`, `verifyWebhook()`, `ipnFromPayload()`; the event now carries `eventId`
- The ready-made route ignores events it already handled (cache, 7 days); config `ipn.require_v2`, `ipn.tolerance`, `ipn.dedupe`
- The older `signature` field is still accepted unless `ipn.require_v2` is true
- composer.json no longer contains a `version` field (versions come from git tags)

## 0.1.0
- Card payment checkout (test and live), payment status, payment links (create, status, reactivate)
- Signed IPN verification (client, middleware `dzwallet.signature`, optional route and event)
