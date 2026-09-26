<?php
$scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/setup.php'));
$appBasePath = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/.');
$appBaseHref = ($appBasePath === '' ? '/' : $appBasePath . '/');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?php echo htmlspecialchars($appBaseHref, ENT_QUOTES, 'UTF-8'); ?>">
    <title>Set up Syndicatum</title>
    <meta name="theme-color" content="#0d1523">
    <link rel="icon" href="assets/brand/web/favicon.ico?v=20260907115852" sizes="any">
    <link rel="stylesheet" href="vendor/pbb-helper/dist/helpers.ui.bundle.min.css?v=0.21.209" data-ui-bundle="ui">
    <link rel="stylesheet" href="assets/setup.css?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/assets/setup.css')); ?>">
</head>
<body>
    <main class="setup-shell">
        <header class="setup-heading">
            <img class="setup-brand" src="assets/brand/svg/syndicatum-standard-color.svg?v=20260907115852" alt="">
            <div>
                <p class="ui-eyebrow">First-run setup · UI preview</p>
                <h1>Set up Syndicatum</h1>
                <p>Review the planned installation journey. Environment checks and installation actions are not connected yet.</p>
            </div>
            <span class="ui-badge">Preview only</span>
        </header>

        <section class="setup-workspace ui-panel" aria-describedby="setup-boundary">
            <div id="setup-stepper"></div>
            <p class="setup-boundary" id="setup-boundary">This preview cannot create a database, administrator, package, or installation.</p>
            <div class="setup-content" id="setup-content" aria-live="polite"></div>
            <footer class="setup-actions">
                <button class="ui-button ui-button-ghost" id="setup-back" type="button">Back</button>
                <button class="ui-button ui-button-primary" id="setup-next" type="button">Next</button>
            </footer>
        </section>
    </main>
    <script type="module" src="assets/setup.mjs?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/assets/setup.mjs')); ?>"></script>
</body>
</html>
