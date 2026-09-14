# Google sign-in setup

Syndicatum supports Google OpenID Connect as an optional human sign-in method. Google authenticates the person; Syndicatum remains authoritative for application roles, project membership, and agent access.

## Google Cloud configuration

1. In Google Cloud Console, configure the OAuth consent screen.
2. Create an OAuth client with application type **Web application**.
3. Add the exact Syndicatum callback shown in **System Settings → Optional Google sign-in** as an authorized redirect URI. For example: `https://syndicatum.example.com/auth/google-callback.php`.
4. Copy the client ID and client secret into Syndicatum System Settings, then enable Google sign-in.

For the hosted Syndicatum deployment, use these public URLs in Google Auth Platform:

- Application home page: `https://syndicatum.wizaya.com/`
- Application privacy policy: `https://syndicatum.wizaya.com/privacy`
- Application Terms of Service: `https://syndicatum.wizaya.com/terms`
- Authorized redirect URI: `https://syndicatum.wizaya.com/auth/google-callback.php`

Add `wizaya.com` as an authorized domain and verify domain ownership with the same Google account or organization that manages the OAuth app. Keep the support email on the OAuth consent screen current because the public policies direct hosted-service privacy and terms questions there.

The public deployment must use HTTPS. Google permits HTTP redirect URIs only for local-development loopback hosts. If Syndicatum is behind a proxy or tunnel, configure the externally visible HTTPS callback, not its LAN address.

## Runtime behavior and safeguards

- The flow requests only `openid email profile`.
- Authorization attempts are browser-bound, expire after ten minutes, and can be consumed once.
- `state`, nonce, and PKCE S256 are validated.
- ID-token signatures and issuer, audience, expiry, issued-at, nonce, and verified-email claims are validated.
- Local identity is keyed by Google's immutable `sub`, never by email.
- A first-time Google identity receives only the ordinary `user` role and a personal workspace.
- If its verified email already belongs to another Syndicatum user, login is rejected instead of linking automatically. The user must sign in normally, open **Edit Profile**, and select **Link Google account**. The callback links only to the authenticated account that initiated the OAuth attempt.
- Linking imports the Google profile photo when the local avatar is empty. **Refresh Google profile** updates avatars already sourced from Google, while a manually uploaded Syndicatum avatar is preserved.
- The client secret is encrypted at rest and returned to administrators only as configured/masked state.
- Native administrator recovery remains enabled.

After saving the settings, sign out and select the official Google button. The same action is available from account creation and while authorizing a Codex connector device. Existing users can add Google from **Edit Profile → Link Google account**. The artwork is Google's pre-approved dark Android/Web SVG and is not redrawn or recolored by Syndicatum.
