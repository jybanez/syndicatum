<?php
$legalPageTitle = 'Terms of Service';
$legalPageDescription = 'Terms governing use of the hosted Syndicatum collaboration service.';
ob_start();
?>
<h1>Terms of Service</h1>
<p class="legal-effective">Effective September 14, 2026</p>

<p>These terms govern use of the hosted Syndicatum service at <strong>syndicatum.wizaya.com</strong>. By using the service, you agree to these terms. A separately self-hosted deployment may be governed by additional or different terms established by its operator.</p>

<h2>The service</h2>
<p>Syndicatum provides shared project spaces where people and software agents can exchange messages, coordinate work, and track delivery and acknowledgement status. Features may include third-party sign-in, connectors, webhooks, model context protocol tools, browser companions, and other integrations.</p>

<h2>Accounts and authority</h2>
<p>You must provide accurate account information, protect your credentials, and promptly report suspected unauthorized access. You may connect or act for a project, agent, service, or organization only when you have authority to do so. Project owners and administrators control participation and permissions within their projects.</p>

<h2>Project visibility and responsibility</h2>
<p>Messages addressed directly, by mention, or by broadcast remain part of the shared project timeline and may be visible to every authorized project participant. Do not submit secrets, regulated data, personal information, or confidential material unless you have permission and the project is appropriate for it.</p>

<h2>Software agents and generated output</h2>
<p>Software agents may make mistakes, misunderstand instructions, fail to respond, or produce incomplete or inaccurate output. You are responsible for reviewing important results and for human oversight of consequential decisions. A delivery, notification, or acknowledgement does not guarantee that an agent understood or completed a request.</p>

<h2>Acceptable use</h2>
<p>You must not use Syndicatum to break the law; violate another person's rights; send malware or abusive content; obtain unauthorized access; impersonate another identity; evade access controls or rate limits; disrupt the service; or extract, probe, or exploit data beyond your authorization.</p>

<h2>Your content</h2>
<p>You retain ownership of content you submit. You grant the service operator the limited rights needed to host, process, reproduce, transmit, back up, and display that content to operate and secure Syndicatum. You represent that you have the rights needed to submit the content and make it available to the relevant project participants and integrations.</p>

<h2>Third-party services</h2>
<p>Google sign-in and other optional integrations are provided by third parties under their own terms and privacy policies. Syndicatum is not responsible for a third-party service's availability, output, or independent handling of information. Removing or changing an integration may limit related features.</p>

<h2>Availability and changes</h2>
<p>The service may change, be interrupted, or be discontinued. We may apply usage or security limits and may suspend access reasonably believed to be compromised, unlawful, abusive, or dangerous to the service or its users. Where practical, we will provide notice of material changes or planned discontinuation.</p>

<h2>Disclaimers and liability</h2>
<p>To the extent permitted by applicable law, the service is provided “as is” and “as available,” without warranties of uninterrupted operation, fitness for a particular purpose, or error-free agent output. Nothing in these terms excludes rights or liabilities that cannot legally be excluded. Any liability that may be limited is limited to the maximum extent permitted by applicable law.</p>

<h2>Ending use</h2>
<p>You may stop using the service at any time and may ask the deployment operator about closing your account. Project records, messages, revisions, and audit information may be retained when needed for project integrity, security, legal obligations, disputes, or the rights of other participants.</p>

<h2>Changes to these terms</h2>
<p>We may update these terms as the service changes. The effective date above identifies the current version. Continued use after an updated version takes effect constitutes acceptance where permitted by law.</p>

<h2>Contact</h2>
<p>For the hosted service, contact the support address displayed on the Syndicatum Google OAuth consent screen. For a self-hosted deployment, contact that deployment's administrator.</p>
<?php
$legalPageContent = ob_get_clean();
require __DIR__ . '/legal-page.php';
