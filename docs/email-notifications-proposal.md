# Email Notifications Proposal

Status: Phase 1 development capture started
Created: September 26, 2026  
Audience: Product, engineering, operations, security, and support

## Current implementation boundary

The first development slice supports Team human invitations through the shared
template renderer and a private development transport. When invitation capture
is enabled in System Settings, the normal invitation mutation renders matching
plain-text and HTML bodies and atomically writes an inspectable `.eml` file plus
a matching HTML-only `.html` preview for template styling
under private installation storage (or `SYNDICATUM_MAIL_CAPTURE_DIR`). The
administrator still receives the one-time invitation token as a fallback, and
the email link uses a URL fragment so the token is not sent in ordinary HTTP
request paths or access logs.

Actual SMTP delivery, a durable recipient-specific outbox, retries, delivery
health, user preferences, and general project-event notifications are not part
of this initial slice. Captured files contain live invitation tokens and must
remain outside the repository and public web root with restricted access.

The invitation template uses the approved **Project brief** design: a compact
Syndicatum brand row, the project name as the primary heading, labeled
inviter/installation/role details, one **View invitation** action, explicit
expiry and privacy guidance, and direct-link/token fallbacks. The HTML uses a
conservative table layout and inline essential styles, with the packaged
128 px color mark displayed at 48 px. The plain-text alternative carries the
same invitation, fallback, expiry, and security information.

Invitation expiry is rendered as a human-friendly date and time with its
timezone. The renderer retains an absolute brand URL for SMTP/web transport
compatibility. Development captures are self-contained: the `.eml` includes
the packaged PNG as an inline CID attachment, while the standalone `.html`
embeds the same PNG as a base64 data URI for offline design inspection.

## 1. Purpose

Add dependable email awareness for human users without turning email into a second project record or duplicating every Realtime event. Syndicatum remains the authoritative place to read, acknowledge, assign, and resolve work.

The first implementation should solve two immediate gaps:

1. Deliver project invitations rather than relying on manual transfer of invitation tokens.
2. Bring a human back to Syndicatum when explicit work requires their attention.

Email is not an AI-agent activation channel. Codex, ChatGPT, Gemini, and webhook agents continue to use their protected connector, Realtime, and webhook delivery paths.

## 2. Product principles

- Send for attention, not for every change.
- Keep the Timeline and Responsibility Inbox authoritative.
- Prefer one clear call to action: **View invitation**.
- Do not support reply-by-email in the initial release.
- Do not acknowledge, start, resolve, or otherwise mutate work merely because an email was delivered or opened.
- Avoid sensitive message content in email by default.
- Respect user preferences, project-level muting, quiet hours, and timezones.
- Coalesce bursts and suppress stale notifications when the work no longer requires attention.
- Keep account and security messages separate from optional project-notification preferences.

## 3. Initial notification policy

| Event | Default delivery | Notes |
| --- | --- | --- |
| Project invitation | Immediate | Transactional; contains the time-limited acceptance link. |
| Direct action request addressed to a human | Immediate | Brief reason and safe summary; no automatic acknowledgement. |
| Task assigned to a human | Immediate | Include project, assigner, task title, and due date when present. |
| Handoff offered or assigned | Immediate | Clearly identify the expected response. |
| Decision requested | Immediate | Use the same recipient and authorization rules as the Responsibility Inbox. |
| Work marked blocked and addressed to the user | Immediate or digest | Immediate only when the user is responsible or explicitly addressed. |
| Due soon or overdue task | Daily digest | Avoid repeated per-task messages. |
| Ordinary Timeline update | Off | Available in Syndicatum and Realtime only. |
| Acknowledgement or routine status change | Off | Do not create notification loops. |
| Change authored by the recipient | Off | Never email a user about their own mutation. |
| AI-agent activation | Never | Continue using existing protected agent channels. |

## 4. Email content

Project-work email should contain only the minimum context needed to decide whether to return:

- installation name;
- project name;
- sender or assigner;
- concise notification reason;
- safe title or short summary;
- due date or urgency when applicable;
- a signed or canonical deep link to the exact Syndicatum view;
- notification-preference and project-mute links where appropriate.

Full message bodies, internal instructions, credentials, claim codes, secrets, attachments, and private agent bootstrap content must not appear in email. HTML and plain-text alternatives should be generated from the same template data. User-authored values must be escaped.

## 5. User experience

### Personal preferences

Provide an **Email notifications** section in the user profile with:

- delivery mode: Immediate, Daily digest, or Off;
- quiet hours and timezone;
- direct requests;
- task assignments and handoffs;
- decisions and blocked work;
- due-soon and overdue digest;
- per-project mute controls.

Project invitations and essential account/security messages are transactional and are not disabled by project-notification preferences.

### Administration

Add a future **Email** tab to System Settings only after delivery exists. It should include:

- enabled state;
- SMTP host and port;
- encryption mode;
- username;
- write-only replacement password;
- sender name and address;
- reply-to address, if different;
- connection timeout and optional CA settings when operationally required;
- **Send test email** action;
- non-sensitive delivery-health summary.

Email remains disabled until configuration is complete and a sender address is valid. Environment-provided settings may be exposed as locked values using the existing settings registry conventions.

## 6. Delivery architecture

### Separate recipient-specific outbox

The existing `message_events_outbox` publishes shared canonical Realtime events. It must not be reused as recipient delivery state. Email needs a separate durable outbox because each recipient can have different preferences, schedule, suppression state, attempts, and final outcome.

The mutation that creates an eligible invitation, action request, task assignment, or handoff should create email intents in the same database transaction. A background worker resolves preferences and sends eligible rows after commit. Web requests must never wait for SMTP.

Suggested logical records:

```text
email_notification_preferences
├─ user_id
├─ delivery_mode
├─ timezone
├─ quiet_hours_start / quiet_hours_end
├─ category flags
└─ updated_at

email_project_preferences
├─ user_id
├─ project_id
├─ muted
└─ updated_at

email_notification_deliveries
├─ delivery_uuid
├─ recipient_user_id or invitation_id
├─ event_type
├─ project_id / message_id / task_id
├─ template_name and template_version
├─ status: queued, sending, retry, succeeded, suppressed, dead
├─ attempt_count / next_attempt_at
├─ idempotency_key
├─ suppression_reason
├─ provider_message_id
├─ last_failure_code
└─ created_at / sent_at / failed_at
```

Recipient, event identity, and template version should form a unique idempotency boundary. Retries use bounded exponential backoff. Unknown outcomes must not be automatically replayed without an idempotent provider contract or a safe reconciliation mechanism.

### Worker behavior

Before sending, the worker should re-check:

1. the recipient is still active and authorized for the project;
2. the source work still exists and remains relevant;
3. the recipient did not author the event;
4. the category and project are not muted;
5. quiet-hour and digest scheduling rules;
6. a matching delivery has not already succeeded;
7. the destination address is eligible for notification delivery.

Several eligible events within a short window may be coalesced into one project summary. Deliveries suppressed because work was resolved, responsibility changed, membership ended, or preferences changed should retain a machine-readable reason.

## 7. Address trust and verification

The current user model stores a normalized email address, but notification delivery should not assume every locally entered address is verified. Before general project notifications are enabled, define an address-trust policy:

- Google identity emails may be treated according to the validated `email_verified` claim at sign-in.
- PBB Account emails may be trusted only under an explicit provider contract.
- Native accounts require an email-verification flow or an administrator-approved address policy.
- Project invitation email is itself delivered to the invited address, but acceptance must continue to require the time-limited single-use token and the existing authorization checks.

Address changes must invalidate or re-establish notification eligibility as appropriate.

## 8. Security and privacy

- Store SMTP passwords as write-only encrypted settings using the existing secret-setting mechanism.
- Never write credentials, message bodies, recipient tokens, or full provider responses to logs.
- Classify failures into sanitized authentication, configuration, transport, rate-limit, recipient, and unknown categories.
- Use HTTPS deep links based on the configured public Syndicatum origin.
- Sign one-click preference links, scope them narrowly, and give them short expirations.
- Apply authorization again when a user opens a link; possession of an email URL does not grant project access.
- Do not use open-tracking pixels by default.
- Document retention for delivery metadata and minimize retained provider identifiers.
- Rate-limit test emails, verification messages, and invitation resends.

## 9. Operations, recovery, and observability

Email configuration belongs to the controlled settings registry. Durable preferences and eligible delivery history must receive explicit backup/restore policies. Queued or uncertain delivery rows should default to non-replay or deliberate reconciliation during restore so a restore cannot unexpectedly resend old mail.

Administration should report:

- configuration completeness;
- last successful test;
- queued, retrying, dead, and recently succeeded counts;
- oldest queued age;
- sanitized failure categories;
- worker heartbeat.

The health surface must not reveal recipient addresses, email bodies, SMTP credentials, or invitation tokens to unauthorized users.

## 10. Testing and acceptance

Required acceptance coverage includes:

- notification eligibility for each supported event;
- preference, mute, quiet-hour, and digest behavior;
- author and duplicate suppression;
- authorization re-check before send;
- HTML escaping and plain-text parity;
- idempotency, retry backoff, permanent failure, and uncertain outcomes;
- SMTP secret masking and environment locks;
- test-email validation before entering busy state;
- deep-link routing and access denial after membership removal;
- invitation expiry, single use, resend, and revocation;
- backup/restore policy preventing unintended replay;
- installation with email disabled or unconfigured;
- mobile and desktop preference/settings layouts.

## 11. Proposed rollout

### Phase 1 — Transactional foundation

- SMTP settings registry and worker.
- Separate email delivery outbox.
- Administrator connection test and delivery health.
- Project invitation email.
- Direct human action request, task assignment, and handoff email.
- Safe templates and exact Syndicatum deep links.
- Immediate/off preference and per-project mute.

### Phase 2 — Attention management

- Daily digest.
- Due-soon and overdue summaries.
- Quiet hours and timezone scheduling.
- Burst coalescing and richer suppression.
- Verified native-account email flow.

### Phase 3 — Operational maturity

- Provider adapters where generic SMTP is insufficient.
- Bounce and complaint handling where supported.
- Branding and localized templates.
- Administrator escalation policies with strict anti-noise limits.
- Retention controls and delivery analytics.

## 12. Decisions required before implementation

1. Which account sources qualify an email address as verified?
2. Should direct requests default to Immediate or require explicit opt-in?
3. What is the default digest time and timezone fallback?
4. How much user-authored content may appear in email on self-hosted installations?
5. Which SMTP encryption modes and certificate overrides are supportable?
6. What delivery-history retention period is appropriate?
7. Should invitation email be mandatory when SMTP is enabled or remain manually shareable as a fallback?
8. Which restored delivery states are suppressed, reconciled, or explicitly replayable?

## 13. Recommended first implementation slice

Implement SMTP configuration, test email, a durable recipient-specific outbox, the worker, and project invitation delivery as one vertical slice. This exercises secret storage, templates, deep links, retries, health reporting, backup policy, and deployment configuration before project-work notifications increase volume and policy complexity.
