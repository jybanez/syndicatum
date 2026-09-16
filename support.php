<?php
$legalPageTitle = 'Support';
$legalPageDescription = 'Support, privacy-request, and security-reporting options for the hosted Syndicatum service.';
ob_start();
?>
<h1>Syndicatum Support</h1>
<p class="legal-effective">Hosted service: syndicatum.wizaya.com</p>

<h2>Get help</h2>
<p>For setup questions, connection failures, bug reports, or feature requests, open a request in the <a href="https://github.com/jybanez/syndicatum/issues" rel="noopener noreferrer">Syndicatum issue tracker</a>. Include the affected product, the approximate time of the problem, and sanitized diagnostic output.</p>

<h2>Privacy and account requests</h2>
<p>For access, correction, export, or deletion requests, open a request in the issue tracker and label it as a privacy or account request. Do not include project messages, credentials, access tokens, personal records, or other sensitive information in a public issue. The operator will arrange a private verification channel when identity confirmation is required.</p>

<h2>Security reports</h2>
<p>Do not disclose a suspected vulnerability or credential in a public issue. Use the repository's private security-reporting channel when available, or open a minimal issue that asks the maintainer to establish a private channel without describing the vulnerability.</p>

<h2>Self-hosted deployments</h2>
<p>If your Syndicatum server is operated by another person or organization, contact that deployment's administrator. The hosted-service operator cannot access or administer an independent deployment.</p>
<?php
$legalPageContent = ob_get_clean();
require __DIR__ . '/legal-page.php';
