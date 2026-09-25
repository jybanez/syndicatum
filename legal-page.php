<?php
if (!isset($legalPageTitle, $legalPageDescription, $legalPageContent)) {
    http_response_code(404);
    exit;
}
$scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
$appBasePath = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/.');
$appBaseHref = ($appBasePath === '' ? '/' : $appBasePath . '/');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?php echo htmlspecialchars($appBaseHref, ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo htmlspecialchars($legalPageTitle, ENT_QUOTES, 'UTF-8'); ?> · Syndicatum</title>
    <meta name="description" content="<?php echo htmlspecialchars($legalPageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="#0d1523">
    <link rel="icon" href="assets/brand/web/favicon.ico?v=20260907115852" sizes="any">
    <link rel="stylesheet" href="vendor/pbb-helper/dist/helpers.ui.bundle.min.css?v=0.21.205" data-ui-bundle="ui">
    <link rel="stylesheet" href="assets/legal.css?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/assets/legal.css')); ?>">
</head>
<body>
    <header class="legal-header">
        <a class="legal-brand" href="./" aria-label="Syndicatum home">
            <img src="assets/brand/svg/syndicatum-standard-color.svg?v=20260907115852" alt="">
            <span>Syndicatum</span>
        </a>
        <nav aria-label="Information pages"><a href="support">Support</a><a href="privacy">Privacy</a><a href="terms">Terms</a><a href="license">Source &amp; License</a></nav>
    </header>
    <main class="legal-shell">
        <article class="legal-card">
            <?php echo $legalPageContent; ?>
        </article>
    </main>
    <footer class="legal-footer"><a href="./">Return to Syndicatum</a></footer>
</body>
</html>
