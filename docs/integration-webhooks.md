# External integration webhooks

Syndicatum uses one provider-neutral authentication model for external webhooks: a high-entropy capability URL. The URL binds the request to exactly one project integration. External payloads cannot select or override the project or participant identity.

## Create and activate an endpoint

1. Create the integration identity with `POST /api/v1/project-integrations.php` as a project owner or administrator.
2. Issue its first callback URL with `POST /api/v1/project-integration-credentials.php` and `action: "issue"`.
3. Copy the returned `callback_url` immediately. It is returned only by the issue or rotate mutation and is not recoverable from Syndicatum.
4. Configure the external system to send HTTPS `POST` requests with `Content-Type: application/json` to that exact URL.

For webhook forms such as GitHub's, paste the complete callback URL into **Payload URL**, select `application/json`, keep SSL verification enabled, and leave any provider-specific signing-secret field blank. Authentication comes from the capability URL, not a provider-specific signature.

## Event payload

Any JSON object is accepted within the global size and rate caps. Provider payloads may be sent unchanged. The following optional normalized fields improve timeline presentation:

```json
{
  "event_type": "alert",
  "severity": "warning",
  "title": "Build requires attention",
  "message": "The external quality gate failed.",
  "source": {
    "event_id": "build-1042",
    "resource_type": "build",
    "resource_id": "1042",
    "name": "Release build",
    "url": "https://external.example/builds/1042"
  }
}
```

Allowed event types are `event`, `alert`, `status`, `build`, `deployment`, `incident`, `security`, `monitoring`, and `source_control`. Allowed severities are `neutral`, `info`, `success`, `warning`, `error`, and `critical`. Payloads without normalized fields produce an informational external-event summary without retaining the raw payload.

Each integration has one or more notification participants. Every accepted event is addressed to those active people and agents as an FYI (`action_requested` remains false). Agents decide how to respond from their existing project role instructions and report through their configured supervisor when applicable.

`Idempotency-Key` is optional because many webhook forms cannot configure headers. When it is absent, Syndicatum uses the raw payload digest as the delivery identity. An exact replay returns the original receipt; reuse of an explicit key with a different payload is rejected.

The fixed global caps are 64 KiB per request and 60 accepted events per integration per minute. Project settings cannot increase them.

## Credential lifecycle

- `action: "rotate"` creates a new callback URL and immediately revokes every previous active URL without changing the integration participant.
- `DELETE /api/v1/project-integration-credentials.php` revokes the active URL.
- Disabling or removing an integration fails closed. Removal also revokes its active credential.
- Only credential hashes and a short diagnostic prefix are stored. Raw inbound payloads are not persisted; receipts retain the payload digest, bounded source metadata, and the resulting immutable system-message ID.

## Deployment logging requirement

Capability URLs contain secrets. Syndicatum marks inbound callback requests with the Apache environment variable `syndicatum_sensitive_webhook`. The production access-log configuration must exclude that variable, for example:

```apache
CustomLog logs/access.log combined env=!syndicatum_sensitive_webhook
```

Apply the equivalent exclusion at every reverse proxy, CDN, WAF, and observability layer. Do not place callback URLs in analytics, monitoring labels, support messages, screenshots, or source control. Rotation is required if a URL may have been exposed.
