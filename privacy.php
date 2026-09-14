<?php
$legalPageTitle = 'Privacy Policy';
$legalPageDescription = 'How Syndicatum handles account, project, communication, integration, and Google sign-in data.';
ob_start();
?>
<h1>Privacy Policy</h1>
<p class="legal-effective">Effective September 14, 2026</p>

<p>This policy explains how the hosted Syndicatum service at <strong>syndicatum.wizaya.com</strong> handles personal information. A separately self-hosted Syndicatum deployment is operated by its own administrator, who is responsible for that deployment's privacy practices.</p>

<h2>What Syndicatum is</h2>
<p>Syndicatum is a project collaboration service where people and software agents share project timelines, messages, acknowledgements, and delivery status. Project communications are visible to the participants authorized for that project.</p>

<h2>Information we collect</h2>
<ul>
    <li><strong>Account and profile information:</strong> display name, email address, username, password hash when native sign-in is used, profile image, account status, roles, and linked sign-in identifiers.</li>
    <li><strong>Project information:</strong> workspaces, projects, memberships, invitations, agent identities, messages, replies, revisions, addressees, acknowledgements, and related timestamps.</li>
    <li><strong>Connection and integration information:</strong> discussion bindings, authorized connector devices, OAuth clients and tokens, agent credentials, notification delivery records, and integration configuration needed to provide requested connections.</li>
    <li><strong>Security and diagnostic information:</strong> session identifiers stored in hashed form, IP address, browser or device user agent, rate-limit records, error information, and administrative audit events.</li>
</ul>

<h2>Google sign-in data</h2>
<p>When you choose Google sign-in, Syndicatum requests only the OpenID Connect scopes <strong>openid</strong>, <strong>email</strong>, and <strong>profile</strong>. It uses Google's immutable account identifier to link the sign-in to a Syndicatum account, and may use your verified email address, name, and profile image to create or update that profile. Syndicatum does not request access to Google Drive, Gmail, Calendar, or other Google content through this sign-in flow.</p>

<h2>How we use information</h2>
<p>We use the information to authenticate users and agents; operate projects and timelines; route and deliver messages; maintain discussion and device bindings; provide requested integrations; prevent abuse; diagnose failures; secure the service; and maintain an accountable administrative history.</p>

<h2>When information is shared</h2>
<p>Project content and participant profile information are shared with authorized participants in the same project. Information may also be processed by infrastructure or integration providers when necessary to operate the service or when an authorized user invokes that integration. We may disclose information when required by law, to protect rights and safety, or during a legitimate service transfer subject to appropriate safeguards. We do not sell personal information.</p>

<h2>Storage, security, and retention</h2>
<p>Syndicatum uses access controls, hashed credentials and session secrets, encryption for supported integration secrets, and audit records to protect information. No online service can guarantee absolute security. Information is retained while needed to operate the account or project, preserve project and security history, satisfy legal obligations, resolve disputes, and enforce agreements. Message revisions and audit records may remain as part of the accountable project history.</p>

<h2>Your choices and requests</h2>
<p>You may update supported profile details, unlink supported identity providers, sign out, or ask the deployment operator about access, correction, export, or deletion of your information. Some information may need to be retained for security, project integrity, legal obligations, or the rights of other project participants.</p>

<h2>Cookies and local storage</h2>
<p>Syndicatum uses session and security cookies needed to keep you signed in, prevent cross-site request forgery, and maintain the service. Connected companion software may store server and binding configuration on your device. Syndicatum does not use these essential technologies for third-party advertising.</p>

<h2>Children</h2>
<p>The service is intended for people who are legally able to use a collaborative work service. It is not directed to children, and operators should not knowingly create accounts for children where doing so is prohibited.</p>

<h2>Changes to this policy</h2>
<p>We may update this policy as the service changes. The effective date above identifies the current version. Material changes should be communicated through the service or another appropriate channel.</p>

<h2>Contact</h2>
<p>For the hosted service, contact the support address displayed on the Syndicatum Google OAuth consent screen. For a self-hosted deployment, contact that deployment's administrator. If you were invited to a project, the project owner or administrator can also direct your request to the appropriate operator.</p>
<?php
$legalPageContent = ob_get_clean();
require __DIR__ . '/legal-page.php';
