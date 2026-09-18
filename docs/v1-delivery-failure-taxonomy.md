# V1 delivery failure taxonomy (candidate)

This is the source/CI mapping for draft PR #5, not a published-artifact or live
recovery claim. `failure_code` describes the last failed attempt; queue
`status`, retry time, and terminal outcome describe the current disposition.
A prior failure must not be displayed as the current health after a later
success. No remote body, header, cURL error string, or raw exception is needed
for these categories.

| Path | Successful outcome in current source | Observed failure semantics | Evidence limit |
| --- | --- | --- | --- |
| Realtime outbox | Ingress accepts the event | HTTP 401/403 authentication; 404 routing under the room-ingress contract; 408 timeout; 429 rate limiting; 5xx upstream; transport/timeout from local result | Database-backed Realtime retry/dead and injected-secret regressions; no live ingress recovery claim |
| Agent webhook | Any HTTP 2xx; receiver deduplication is a separate contract | HTTP 401/403 authentication; other 4xx rejection (except retryable 408/429); 5xx upstream; cURL 28 timeout, other cURL failure transport | Database-backed webhook failure/success test; no production receiver claim |
| Workspace Agent trigger | HTTP 202 plus expected run fields | HTTP non-202 rejection; 408/429 retryable; 401/403 authentication; 5xx upstream; cURL timeout/transport | Source mapping and disabled-path test only; V1 activation remains disabled |
| Responses API | HTTP 2xx with response ID, then provider state `completed` | HTTP class as above, with 409 retryable; cURL timeout/transport; provider `failed`, `cancelled`, or `incomplete` as a bounded provider-specific state | Source mapping and disabled-path test only; V1 activation remains disabled |

The common code set is `transport`, `timeout`, `authentication`,
`rate_limiting`, `upstream_error`, `rejected`, and `internal_error` for an
unclassified local exception. `routing` is retained for Realtime's known
404 contract only. `integration_disabled` and `invalid_request` are reserved
bounded codes, not inferred from arbitrary provider text. Numeric HTTP status
is retained separately where known; `response_state` is retained separately
for the allowlisted Responses provider states. An HTTP 200 response with a
provider `failed` state is therefore a provider rejection, not HTTP failure.

Retryability is not a failure category: webhook and Workspace Agent treat
4xx other than 408/429 as permanent; Responses also retries 409. All paths
can terminate at the configured attempt limit. The operator surface must show
`retry`/`waiting`/`dead`/`succeeded` and timestamps alongside the category.
The current per-message projection supplies some of these facts; the
administrator-wide health view and a real operator recovery exercise are
still open P0.6 work.
