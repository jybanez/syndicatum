# Chatviewer Implementation Checklist

## Foundation

- [x] Create project structure for root-served PHP app
- [x] Add `docs/chatviewer-proposal.md`
- [x] Add this implementation checklist

## Helper Vendoring

- [x] Clone or read official helper source
- [x] Read `docs/pbb-refactor-playbook.md`
- [x] Use `uiLoader` registry integration instead of direct helper path imports for app composition
- [x] Vendor the required helper CSS/JS assets locally
- [x] Record source repo and pinned commit in `VENDORED.md`

## Backend

- [x] Implement `src/ChatLogParser.php`
- [x] Parse `#Projects` bullet list
- [x] Parse `#Active Topics` bullet list
- [x] Parse `#Chat log` entries into structured records
- [x] Support directed messages with `sender` and `target`
- [x] Support message-body continuation lines safely
- [x] Add derived metadata and summary counts
- [x] Add JSON API endpoint at `api/chat-log.php`
- [x] Add ETag / not-modified response support

## Frontend

- [x] Add root `index.php`
- [x] Add `assets/app.css`
- [x] Add `assets/app.mjs`
- [x] Use helper-managed search
- [x] Use helper-managed tabs where applicable
- [x] Use helper-managed timeline rendering
- [x] Use helper-managed empty states and refresh feedback
- [x] Build viewport-fixed shell with no page scroll
- [x] Build independent scroll regions for each column/panel
- [x] Render summary, topics, filters, timeline, and detail inspector
- [x] Add search filter using the vendored helper search field
- [x] Add participant and direct-message filters
- [x] Add day-grouped timeline rendering
- [x] Add selected-message detail view
- [x] Add empty and loading states
- [x] Add responsive narrow-screen panel switching

## Refresh Behavior

- [x] Poll the API periodically in the background
- [x] Reuse ETag to avoid unnecessary full payload processing
- [x] Preserve current filter state across refreshes
- [x] Preserve selected message when possible

## Verification

- [x] Run PHP syntax checks on backend files
- [x] Run JavaScript syntax/module checks
- [x] Verify helper asset paths resolve locally
- [x] Verify the viewer reads the real shared log
- [ ] Verify the app shell remains viewport-fixed
