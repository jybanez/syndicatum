# Registration and welcome workflows

> **Integration name:** PBB Account identifies the optional external sign-in provider, not the Syndicatum product. Its UI and protocol labels are retained; see [Terminology and compatibility](terminology.md).

## Native registration

1. The visitor submits the canonical registration form. Client validation runs before the form enters its busy state, and the API repeats all validation and authorization checks.
2. Syndicatum creates the human user, ordinary `user` role, personal workspace, and a one-time activation record. It does not create a session. Submitting registration again for the same still-pending email rotates the token and sends a replacement link; it does not create another user or workspace.
3. Syndicatum sends a registration email containing an app URL whose fragment carries the single-use activation token. The token is stored only as a SHA-256 digest and expires after 24 hours.
4. Opening the link shows an explicit **Activate account** action. This keeps email-link scanners from activating accounts with a GET request.
5. A confirmed activation consumes the token and creates the native session. Syndicatum then sends the welcome email once. If welcome delivery fails, a later native sign-in retries only that already-scheduled notification.

Pending activation records are durable backup data. Restoring a backup therefore cannot accidentally turn an unactivated account into a sign-in-ready account.

## SSO registration

Google and PBB Account identities that pass their provider verification are active immediately. On first provisioning Syndicatum creates the ordinary user, personal workspace, and provider-specific session, then sends one welcome email. Returning SSO users do not receive another welcome email.

SSO does not create a native registration activation record. Local user status, deletion state, roles, and project permissions remain authoritative after sign-in.

## Delivery guarantees

Lifecycle email state is recorded per user and event. A successful `welcome` event is never sent again. A row left in `sending` state is not replayed automatically because the external delivery outcome is uncertain. Failed, confirmed attempts may be retried through the applicable sign-in workflow.

Native self-registration is unavailable when mail delivery, a valid sender, or the public application origin is not configured. This is checked before creating the pending user.
