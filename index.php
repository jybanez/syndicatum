<?php
$scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$appBasePath = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/.');
$appBaseHref = ($appBasePath === '' ? '/' : $appBasePath . '/');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?php echo htmlspecialchars($appBaseHref, ENT_QUOTES, 'UTF-8'); ?>">
    <title>Syndicatum</title>
    <meta name="theme-color" content="#0d1523">
    <link rel="icon" href="assets/brand/web/favicon.ico?v=20260907115852" sizes="any">
    <link rel="icon" type="image/png" href="assets/brand/web/favicon-16x16.png?v=20260907115852" sizes="16x16">
    <link rel="icon" type="image/png" href="assets/brand/web/favicon-32x32.png?v=20260907115852" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/brand/web/apple-touch-icon-180x180.png?v=20260907115852" sizes="180x180">
    <link rel="mask-icon" href="assets/brand/web/safari-pinned-tab.svg?v=20260907115852" color="#2563EB">
    <link rel="manifest" href="manifest.webmanifest?v=20260907115852">
    <link rel="stylesheet" href="vendor/pbb-helper/dist/helpers.ui.bundle.min.css?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/vendor/pbb-helper/dist/helpers.ui.bundle.min.css')); ?>" data-ui-bundle="ui">
    <link rel="stylesheet" href="assets/app.css?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/assets/app.css')); ?>">
</head>
<body>
    <div class="app-shell" id="app-shell" hidden>
        <header class="surface-chrome"><div class="helper-nav-host" id="navbar-host"></div></header>
        <main class="surface-host">
            <section class="unified-workspace-surface" id="workspace-surface" hidden>
                <div class="workspace-splitter-host" id="workspace-splitter-host">
                    <aside class="surface-column project-navigation-column is-mobile-active" id="project-navigation-column" data-panel="projects">
                        <div class="column-filter-row project-filter-row">
                            <div id="project-search-mount" class="project-search"></div>
                            <button type="button" class="ui-button ui-button-borderless column-action-trigger" id="project-list-actions-trigger" aria-label="Project list actions" title="Project actions">
                                <span class="timeline-action-icon" id="project-list-actions-icon" aria-hidden="true"></span>
                            </button>
                        </div>
                        <div class="project-list column-scroll-region" id="workspace-project-list"></div>
                    </aside>

                    <div class="workspace-inner-splitter-host" id="workspace-inner-splitter-host">
                        <section class="surface-column project-messages-column" id="project-messages-column" data-panel="timeline">
                            <header class="project-overview timeline-project-overview">
                                <div class="project-overview-heading">
                                    <h1 id="project-title">Select a project</h1>
                                    <div class="project-overview-actions">
                                        <button type="button" class="ui-button ui-button-borderless timeline-icon-action new-message-trigger" id="new-message-trigger" aria-label="New message" title="New message" hidden>
                                            <span class="timeline-action-icon" id="new-message-icon" aria-hidden="true"></span>
                                        </button>
                                        <button type="button" class="ui-button ui-button-borderless timeline-icon-action" id="project-actions-trigger" aria-label="Project actions" title="Project actions" hidden>
                                            <span class="timeline-action-icon" id="project-actions-icon" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                </div>
                                <span class="app-visually-hidden" id="status-badge" aria-live="polite">Loading</span>
                                <span class="app-visually-hidden" id="timeline-count" aria-live="polite">0 messages</span>
                                <span class="app-visually-hidden" id="connection-label">HTTP</span>
                                <div class="project-view-switch" id="project-view-switch" role="group" aria-label="Project view" hidden>
                                    <button type="button" class="ui-button ui-button-ghost is-active" id="show-timeline" aria-pressed="true">Timeline</button>
                                    <button type="button" class="ui-button ui-button-ghost" id="show-responsibility" aria-pressed="false">Responsibility Inbox</button>
                                </div>
                            </header>
                            <section class="composer-shell" id="composer-shell" hidden aria-label="Compose message">
                                <div class="reply-context" id="reply-context" hidden></div>
                                <div class="addressing-row" id="addressing-row">
                                    <div id="address-mode"></div><div id="message-intent"></div><div id="addressee-select" class="addressee-select"></div>
                                    <p class="broadcast-warning" id="broadcast-warning" hidden>Everyone active in this project will be notified.</p>
                                </div>
                                <div id="composer-host"></div>
                            </section>
                            <section class="filter-bar" id="timeline-filter-bar" aria-label="Timeline filters">
                                <div id="search-mount" class="app-search"></div>
                                <div class="filter-bar-actions">
                                    <button type="button" class="ui-button ui-button-borderless timeline-icon-action timeline-filter-trigger" id="filter-popover-trigger" aria-label="Timeline filters" title="Filters">
                                        <span class="timeline-action-icon" id="filter-icon" aria-hidden="true"></span>
                                        <span class="ui-badge timeline-filter-count" id="filter-count" hidden>0</span>
                                    </button>
                                    <button type="button" class="ui-button ui-button-borderless timeline-icon-action" id="timeline-collapse-toggle" aria-label="Collapse all messages" title="Collapse all messages">
                                        <span class="timeline-action-icon" id="timeline-collapse-icon" aria-hidden="true"></span>
                                    </button>
                                    <button type="button" class="ui-button ui-button-borderless timeline-icon-action" id="refresh-button" aria-label="Refresh timeline" title="Refresh">
                                        <span class="timeline-action-icon" id="refresh-icon" aria-hidden="true"></span>
                                    </button>
                                </div>
                                <div class="timeline-filter-panel" id="filter-popover-content" hidden>
                                    <div class="timeline-filter-section">
                                        <span class="timeline-filter-label">Messages</span>
                                        <div id="primary-filter"></div>
                                    </div>
                                    <div class="timeline-filter-section">
                                        <span class="timeline-filter-label">Sender</span>
                                        <div id="sender-filter" class="sender-filter"></div>
                                    </div>
                                    <div class="timeline-filter-section">
                                        <span class="timeline-filter-label">Date range</span>
                                        <div class="timeline-filter-dates">
                                            <label>From<input class="ui-input date-filter" id="date-from" type="date"></label>
                                            <label>Through<input class="ui-input date-filter" id="date-to" type="date"></label>
                                        </div>
                                    </div>
                                    <div class="timeline-filter-actions">
                                        <button type="button" class="ui-button ui-button-borderless" id="clear-filters" hidden>Clear filters</button>
                                    </div>
                                </div>
                            </section>
                            <div class="timeline-notice" id="timeline-notice" hidden></div>
                            <div class="timeline-scroll" id="timeline-scroll"><div id="timeline-host"></div></div>
                            <div class="responsibility-scroll" id="responsibility-host" hidden></div>
                        </section>

                        <div class="workspace-work-splitter-host" id="workspace-work-splitter-host">
                        <aside class="surface-column project-tasks-column" id="project-tasks-column" data-panel="tasks">
                            <div class="column-filter-row task-filter-row">
                                <div><strong>Tasks</strong> <span class="task-count" id="task-count" aria-live="polite">0</span></div>
                                <div class="task-heading-actions">
                                    <button type="button" class="ui-button ui-button-borderless column-action-trigger" id="new-task-trigger" aria-label="New task" title="New task" hidden>
                                        <span id="new-task-icon" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="task-list-controls">
                                <div class="task-search" id="task-search-mount"></div>
                                <button type="button" class="ui-button ui-button-borderless task-filter-trigger" id="task-filter-trigger" aria-label="Filter tasks: 4 statuses selected" title="Filter tasks">
                                    <span id="task-filter-icon" aria-hidden="true"></span>
                                    <span class="ui-badge task-filter-count" id="task-filter-count" aria-hidden="true">4</span>
                                </button>
                                <button type="button" class="ui-button ui-button-borderless task-sort-trigger" id="task-sort-trigger" aria-label="Sort tasks: Recently updated" title="Sort tasks: Recently updated">
                                    <span id="task-sort-icon" aria-hidden="true"></span>
                                </button>
                                <button type="button" class="ui-button ui-button-borderless task-refresh-trigger" id="task-refresh-trigger" aria-label="Refresh tasks" title="Refresh tasks">
                                    <span id="task-refresh-icon" aria-hidden="true"></span>
                                </button>
                                <div class="task-filter-popover-content" id="task-filter-popover-content" hidden>
                                    <span class="task-filter-label">Task status</span>
                                    <div class="task-status-filter" id="task-status-filter"></div>
                                </div>
                            </div>
                            <section class="task-list column-scroll-region" id="task-list"></section>
                        </aside>

                        <aside class="surface-column project-participants-column" id="project-participants-column" data-panel="team">
                            <div class="column-filter-row team-filter-row">
                                <input class="ui-input" id="participant-search" type="search" placeholder="Search team" aria-label="Search team participants">
                                <button type="button" class="ui-button ui-button-borderless column-action-trigger" id="team-actions-trigger" aria-label="Team actions" title="Team actions" hidden>
                                    <span class="timeline-action-icon" id="team-actions-icon" aria-hidden="true"></span>
                                </button>
                            </div>
                            <section class="participant-section column-scroll-region">
                                <div class="participant-list" id="participant-list"></div>
                            </section>
                        </aside>
                        </div>
                    </div>
                </div>
            </section>

            <section class="admin-surface" id="admin-surface" hidden>
                <header class="surface-heading"><div><p class="ui-eyebrow" id="admin-eyebrow">Global administration</p><h1 id="admin-title">Administration</h1></div><button class="ui-button ui-button-ghost" id="admin-refresh-button" type="button">Refresh</button></header>
                <div class="admin-list" id="admin-list"></div>
            </section>
        </main>
    </div>
    <nav class="public-policy-links" id="public-policy-links" aria-label="Legal information">
        <a href="support">Support</a>
        <span aria-hidden="true">&middot;</span>
        <a href="privacy">Privacy Policy</a>
        <span aria-hidden="true">&middot;</span>
        <a href="terms">Terms of Service</a>
        <span aria-hidden="true">&middot;</span>
        <a href="license">Source &amp; License</a>
    </nav>
    <script type="module" src="assets/app.mjs?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/assets/app.mjs')); ?>"></script>
</body>
</html>
