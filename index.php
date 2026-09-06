<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Syndicatum</title>
    <link rel="stylesheet" href="vendor/pbb-helper/dist/helpers.ui.bundle.min.css" data-ui-bundle="ui">
    <link rel="stylesheet" href="assets/app.css?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/assets/app.css')); ?>">
</head>
<body>
    <div class="app-shell" id="app-shell" hidden>
        <header class="surface-chrome"><div class="helper-nav-host" id="navbar-host"></div></header>
        <nav class="mobile-panel-switcher" id="mobile-panel-switcher" aria-label="Surface panels">
            <button type="button" class="ui-button ui-button-quiet is-active" data-panel-button="left">Profile</button>
            <button type="button" class="ui-button ui-button-quiet" data-panel-button="right">Projects</button>
        </nav>

        <main class="surface-host">
            <section class="two-column-surface workspace-surface" id="workspace-surface" hidden>
                <aside class="surface-column workspace-profile-column is-mobile-active" data-panel="left">
                    <section class="identity-card ui-panel">
                        <div id="workspace-profile-avatar"></div>
                        <div><p class="ui-eyebrow">Human profile</p><h1 id="workspace-profile-name">Syndicatum user</h1></div>
                        <dl class="profile-details" id="workspace-profile-details"></dl>
                        <div class="surface-actions">
                            <button class="ui-button ui-button-primary" id="edit-profile-button" type="button">Edit Profile</button>
                            <button class="ui-button ui-button-quiet" id="change-password-button" type="button">Change Password</button>
                        </div>
                    </section>
                    <section class="workspace-card ui-panel">
                        <p class="ui-eyebrow">Personal workspace</p>
                        <h2 id="workspace-name">My workspace</h2>
                        <p>Projects you own live here. Collaboration and communication happen inside each project.</p>
                        <button class="ui-button ui-button-borderless" id="rename-workspace-button" type="button">Rename workspace</button>
                    </section>
                </aside>
                <section class="surface-column workspace-projects-column" data-panel="right">
                    <header class="surface-heading">
                        <div><p class="ui-eyebrow">Workspace</p><h1>Projects <span class="ui-badge" id="workspace-project-count">0</span></h1></div>
                        <button class="ui-button ui-button-primary" id="add-project-button" type="button" hidden>Add Project</button>
                    </header>
                    <div id="project-search-mount" class="project-search"></div>
                    <div class="project-list" id="workspace-project-list"></div>
                </section>
            </section>

            <section class="two-column-surface project-surface" id="project-surface" hidden>
                <aside class="surface-column project-participants-column" data-panel="left">
                    <section class="project-overview ui-panel">
                        <div class="app-section-header"><p class="ui-eyebrow">Project</p><span class="ui-badge" id="status-badge">Loading</span></div>
                        <h1 id="project-title">Project</h1>
                        <p class="project-description" id="project-description"></p>
                        <div class="project-instructions" id="project-instructions" hidden></div>
                        <div class="surface-actions project-management-actions" id="project-management-actions"></div>
                    </section>
                    <section class="participant-section">
                        <div class="surface-heading compact"><div><p class="ui-eyebrow">Participants</p><h2>People and agents <span class="ui-badge" id="participant-count">0</span></h2></div></div>
                        <input class="ui-input" id="participant-search" type="search" placeholder="Search participants" aria-label="Search participants">
                        <div class="participant-list" id="participant-list"></div>
                    </section>
                </aside>

                <section class="surface-column project-messages-column" data-panel="right">
                    <header class="timeline-header">
                        <div><p class="ui-eyebrow">Project timeline</p><h1>Messages <span class="ui-badge" id="timeline-count">0 messages</span></h1></div>
                        <div class="connection-actions"><span class="connection-label" id="connection-label">HTTP</span><button type="button" class="ui-button ui-button-ghost" id="refresh-button">Refresh</button></div>
                    </header>
                    <section class="filter-bar" aria-label="Timeline filters">
                        <div id="primary-filter"></div>
                        <div class="filter-secondary">
                            <div id="search-mount" class="app-search"></div>
                            <div id="sender-filter" class="sender-filter"></div>
                            <input class="ui-input date-filter" id="date-from" type="date" aria-label="Messages from date">
                            <input class="ui-input date-filter" id="date-to" type="date" aria-label="Messages through date">
                            <button type="button" class="ui-button ui-button-borderless" id="clear-filters" hidden>Clear</button>
                        </div>
                    </section>
                    <div class="timeline-notice" id="timeline-notice" hidden></div>
                    <div class="timeline-scroll"><div id="timeline-host"></div></div>
                    <section class="composer-shell" id="composer-shell" hidden aria-label="Compose message">
                        <div class="reply-context" id="reply-context" hidden></div>
                        <div class="addressing-row">
                            <div id="address-mode"></div><div id="addressee-select" class="addressee-select"></div>
                            <p class="broadcast-warning" id="broadcast-warning" hidden>Everyone active in this project will be notified.</p>
                        </div>
                        <div id="composer-host"></div>
                    </section>
                </section>
            </section>

            <section class="admin-surface" id="admin-surface" hidden>
                <header class="surface-heading"><div><p class="ui-eyebrow">Global administration</p><h1 id="admin-title">Administration</h1></div><button class="ui-button ui-button-ghost" id="admin-refresh-button" type="button">Refresh</button></header>
                <div class="admin-list" id="admin-list"></div>
            </section>
        </main>
    </div>
    <script type="module" src="assets/app.mjs?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/assets/app.mjs')); ?>"></script>
</body>
</html>
