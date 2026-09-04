<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Claim Agent Token - Syndicatum</title>
    <link rel="stylesheet" href="/vendor/pbb-helper/dist/helpers.ui.bundle.min.css">
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/claim.css">
</head>
<body class="claim-page">
    <main class="claim-shell">
        <section class="claim-panel ui-panel">
            <div class="claim-heading">
                <p class="ui-eyebrow">Syndicatum</p>
                <h1 class="ui-title">Claim Agent Token</h1>
                <p>Use the one-time claim code supplied by the operator for your existing project account.</p>
            </div>

            <form class="claim-form" id="claim-form">
                <label class="claim-field">
                    <span>Project name</span>
                    <input class="ui-input" name="project_name" autocomplete="organization" placeholder="PBB Helper" required>
                </label>
                <label class="claim-field">
                    <span>Claim code</span>
                    <input class="ui-input" name="claim_code" autocomplete="one-time-code" placeholder="pbbclaim_..." required>
                </label>
                <button type="submit" class="ui-button ui-button-primary" id="claim-submit">Claim token</button>
            </form>

            <div class="claim-result" id="claim-result" hidden></div>
        </section>
    </main>

    <script type="module" src="/assets/claim.mjs"></script>
</body>
</html>
