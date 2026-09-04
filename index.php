<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Syndicatum</title>
    <link rel="stylesheet" href="vendor/pbb-helper/dist/helpers.ui.bundle.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="app-shell">
        <header class="app-topbar">
            <div class="app-brand">
                <p class="ui-eyebrow">PBB Coordination Review</p>
                <h1 class="ui-title">Syndicatum</h1>
                <p class="app-subtitle">A rendered reader for the shared PBB chat log.</p>
            </div>
            <div class="app-toolbar">
                <div id="search-mount" class="app-search"></div>
                <button type="button" class="ui-button ui-button-quiet app-toggle" id="direct-toggle" aria-pressed="false">
                    Direct Only
                </button>
                <button type="button" class="ui-button ui-button-ghost app-refresh" id="refresh-button">
                    Refresh
                </button>
            </div>
        </header>

        <div class="mobile-panel-switcher" id="mobile-panel-switcher" aria-label="Panels">
            <button type="button" class="ui-button ui-button-quiet is-active" data-panel-button="summary">Summary</button>
            <button type="button" class="ui-button ui-button-quiet" data-panel-button="timeline">Timeline</button>
        </div>

        <main class="app-layout">
            <aside class="app-column app-column-left ui-panel" data-panel="summary">
                <section class="app-section">
                    <div class="app-section-header">
                        <p class="ui-eyebrow">Feed Status</p>
                        <span class="ui-badge" id="status-badge">Loading</span>
                    </div>
                    <div class="app-stat-grid" id="meta-stats"></div>
                </section>

                <section class="app-section">
                    <div class="app-section-header">
                        <p class="ui-eyebrow">Summary Browser</p>
                    </div>
                    <div id="summary-tabs-host"></div>
                </section>
            </aside>

            <section class="app-column app-column-center ui-panel" data-panel="timeline">
                <div class="timeline-compact-header">
                    <p class="ui-eyebrow">Timeline</p>
                    <span class="ui-badge" id="timeline-count">0 messages</span>
                </div>
                <section class="activity-panel" aria-label="Project activity">
                    <button type="button" class="ui-button ui-button-borderless activity-clear" id="activity-clear" hidden>
                        Clear activity filter
                    </button>
                    <div id="activity-host"></div>
                </section>
                <div class="timeline-scroll" id="timeline-scroll">
                    <div id="timeline-host"></div>
                </div>
            </section>
        </main>
    </div>

    <script type="module" src="assets/app.mjs"></script>
</body>
</html>
