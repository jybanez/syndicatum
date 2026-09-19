<?php
$legalPageTitle = 'Source and License';
$legalPageDescription = 'Open-source license and source-code information for Syndicatum.';
ob_start();
?>
<h1>Source and License</h1>

<p>Syndicatum's original source code is free software licensed under the <strong>GNU Affero General Public License, version 3 only</strong> (<code>AGPL-3.0-only</code>). You may use, study, share, and modify the covered software under that license's terms.</p>

<h2>Source code</h2>
<p>The corresponding project source and license text are available in the <a href="https://github.com/jybanez/syndicatum" rel="noopener noreferrer">Syndicatum source repository</a>. Operators of modified deployments are responsible for satisfying the source-availability and notice obligations that apply to their versions.</p>

<h2>Third-party components</h2>
<p>Third-party and vendored components remain governed by their respective upstream licenses and notices. The repository's <a href="https://github.com/jybanez/syndicatum/blob/main/THIRD_PARTY_NOTICES.md" rel="noopener noreferrer">third-party notices</a> identify those components and any outstanding license-verification work.</p>

<h2>Hosted service</h2>
<p>The software license governs the covered source code. Use of this hosted deployment is also subject to its <a href="terms">Terms of Service</a> and <a href="privacy">Privacy Policy</a>. The Syndicatum name and branding are not licensed by the AGPL except where applicable law provides otherwise.</p>

<p>This page summarizes the licensing arrangement for convenience. The complete license text controls.</p>
<?php
$legalPageContent = ob_get_clean();
require __DIR__ . '/legal-page.php';
