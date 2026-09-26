import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const extensionUrl = new URL("../extension/", import.meta.url);
const companionUrl = new URL("../", import.meta.url);

test("package permits on-demand adapter injection for pre-existing tabs", async () => {
  const manifest = JSON.parse(await readFile(new URL("manifest.json", extensionUrl), "utf8"));
  assert.equal(manifest.version, "0.10.4");
  assert.ok(manifest.permissions.includes("scripting"));
  assert.ok(manifest.host_permissions.includes("https://chatgpt.com/*"));
  assert.ok(manifest.host_permissions.includes("https://gemini.google.com/*"));
  assert.ok(!manifest.host_permissions.includes("https://syndicatum.wizaya.com/*"));
  assert.ok(manifest.optional_host_permissions.includes("https://*/*"));
});

test("connected users can migrate servers without clearing device or binding state", async () => {
  const html = await readFile(new URL("popup.html", extensionUrl), "utf8");
  const popup = await readFile(new URL("popup.js", extensionUrl), "utf8");
  const background = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(html, /id="edit-server"/);
  assert.match(html, /Change Syndicatum server/);
  assert.match(html, /Validate &amp; Continue/);
  assert.match(popup, /syndicatum\.prepare-server-migration/);
  assert.match(popup, /syndicatum\.resume-server-migration/);
  assert.ok(popup.lastIndexOf("syndicatum.prepare-server-migration") < popup.lastIndexOf("chrome.permissions.request"));
  assert.match(background, /chrome\.permissions\.onAdded/);
  assert.match(background, /pendingServerMigration/);
  assert.match(background, /The new server did not recognize the existing Companion device/);
  assert.match(background, /different discussion binding inventory/);
  assert.match(background, /Server change rolled back/);
  assert.ok(background.indexOf("await validateServer(baseUrl)") < background.indexOf("lastServerMigration:"));
  assert.ok(background.indexOf("fetchBindingSnapshot(baseUrl, current.accessToken)") < background.indexOf("lastServerMigration:"));
  assert.doesNotMatch(background.match(/async function migrateServer[\s\S]*?\r?\n}\r?\n/)?.[0] || "", /chrome\.storage\.local\.remove\(STATE_KEY\)/);
});

test("operator chooses and validates a Syndicatum server before authorization", async () => {
  const html = await readFile(new URL("popup.html", extensionUrl), "utf8");
  const popup = await readFile(new URL("popup.js", extensionUrl), "utf8");
  const background = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(html, /placeholder="http:\/\/syndicatumserver\.com"/);
  assert.doesNotMatch(html, /value="https:\/\/chatviewer\.pbb\.ph"/);
  assert.match(popup, /chrome\.permissions\.request/);
  assert.match(popup, /Validating…/);
  assert.match(html, /<strong id="server"><\/strong>/);
  assert.match(background, /syndicatum-connector-v1/);
  assert.match(background, /verified as a compatible Syndicatum installation/);
  assert.ok(background.indexOf("await validateServer(baseUrl)") < background.indexOf("await save({ baseUrl"));
  assert.match(background, /chrome\.permissions\.remove/);
});

test("MCP binding intents require an in-discussion Continue or Cancel confirmation", async () => {
  const content = await readFile(new URL("content.js", extensionUrl), "utf8");
  const background = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(content, /Confirm Syndicatum discussion binding/);
  assert.match(content, /data-action="continue"/);
  assert.match(content, /data-action="cancel"/);
  assert.match(background, /connector-discussion-bindings\.php/);
  assert.match(background, /binding_intent_id/);
  assert.match(background, /sender\.tab\.url/);
  assert.match(content, /Discussion binding successful/);
  assert.match(content, /diagnose_connection using the binding_context_id/);
  assert.match(content, /adapter\.deliver\(prompt\)/);
  assert.match(content, /Retry status check/);
  assert.match(content, /data-field="server"/);
  assert.match(content, /intent\.agent_name} \(ID \$\{intent\.agent_id}/);
  assert.match(content, /intent\.agent_name} \(new agent\)/);
  assert.match(content, /binding\?\.project_name/);
  assert.match(content, /binding\?\.agent_name/);
  assert.match(content, /Status check submitted in this discussion/);
  assert.doesNotMatch(content, /if \(verification\?\.ok\) \{ host\.remove\(\); return; \}/);
  assert.match(background, /server_url: current\.baseUrl/);
  assert.match(background, /companion_sync_warning/);
});

test("popup contains no legacy binding-code workflow", async () => {
  const html = await readFile(new URL("popup.html", extensionUrl), "utf8");
  const popup = await readFile(new URL("popup.js", extensionUrl), "utf8");
  const background = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.doesNotMatch(html, /binding-code|bind-discussion|one-time binding code/i);
  assert.doesNotMatch(popup, /syndicatum\.bind-discussion|bindingCode/);
  assert.doesNotMatch(background, /syndicatum\.bind-discussion|binding_code|bindActiveDiscussion/);
  assert.match(background, /connector-discussion-bindings\.php/);
});

test("content listener guards against duplicate programmatic injection", async () => {
  const source = await readFile(new URL("content.js", extensionUrl), "utf8");
  assert.match(source, /__syndicatumCompanionListenerInstalled/);
});

test("background injects only packaged provider adapter files", async () => {
  const source = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(source, /files: \[`providers\/\$\{provider\}\.js`, "content\.js"\]/);
  assert.doesNotMatch(source, /executeScript\([^)]*func:/s);
});

test("background keeps realtime alive and stores only metadata in delivery diagnostics", async () => {
  const source = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(source, /session\.health\.request/);
  assert.match(source, /setInterval\(sendHealth, 20000\)/);
  assert.match(source, /deliveryHistory/);
  assert.doesNotMatch(source, /deliveryHistory[^;]*message\.body/s);
});

test("recovery and delivery are isolated so one participant cannot block the others", async () => {
  const source = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(source, /const drainRunning = new Map\(\)/);
  assert.match(source, /Promise\.all\(Object\.keys\(PROVIDERS\)\.map/);
  assert.match(source, /Promise\.all\(\[\.\.\.shards\]\.map\(shard => drain\(shard\)\)\)/);
  assert.match(source, /deliveryShard\(candidate\) === shard/);
  assert.match(source, /Promise\.allSettled\(deliveries\)/);
  assert.doesNotMatch(source, /let drainRunning = null/);
});

test("uncertain browser submissions pause their shard until explicit operator review", async () => {
  const html = await readFile(new URL("popup.html", extensionUrl), "utf8");
  const popup = await readFile(new URL("popup.js", extensionUrl), "utf8");
  const background = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(background, /quarantineLegacyDeliveryQueue/);
  assert.match(background, /deliveryQueueVersion: 2/);
  assert.match(background, /upgrade_reconciliation_required/);
  assert.match(background, /deliveryState === DELIVERY_REVIEW_STATE/);
  assert.match(background, /if \(requiresReview\)[\s\S]*?return;[\s\S]*?chrome\.alarms\.create\(RETRY_ALARM/);
  assert.match(background, /operator_confirmed_exact_user_turn/);
  assert.match(background, /operatorRetryAuthorizedAt/);
  assert.match(html, /id="delivery-review"/);
  assert.match(html, /Copy safe review metadata/);
  assert.match(popup, /syndicatum\.resolve-delivery-review/);
  assert.match(popup, /Confirm visible/);
  assert.match(popup, /Retry once/);
  assert.match(popup, /Remove stale/);
  assert.match(popup, /discard_stale/);
  assert.match(popup, /review\.agentName/);
  assert.match(popup, /\$\{review\.agentName\} \(agent \$\{review\.agentId\}\)/);
});

test("popup separates connection health and exposes timestamp diagnostics", async () => {
  const html = await readFile(new URL("popup.html", extensionUrl), "utf8");
  const popup = await readFile(new URL("popup.js", extensionUrl), "utf8");
  const background = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  for (const id of ["server-health", "account-health", "realtime-health", "bindings-health", "delivery-health", "last-server-check", "last-sync", "last-realtime", "last-delivery"]) assert.match(html, new RegExp(`id="${id}"`));
  assert.match(popup, /health\.overall/);
  assert.match(background, /companionHealth/);
  assert.match(background, /lastServerError/);
  assert.match(background, /lastRealtimeError/);
  assert.match(background, /lastDeliveryError/);
});

test("popup identifies its installed build and copies safe diagnostics", async () => {
  const html = await readFile(new URL("popup.html", extensionUrl), "utf8");
  const popup = await readFile(new URL("popup.js", extensionUrl), "utf8");
  assert.match(html, /id="extension-version"/);
  assert.match(html, /id="copy-diagnostics"/);
  assert.match(popup, /chrome\.runtime\.getManifest\(\)\.version/);
  assert.match(popup, /navigator\.clipboard\.writeText\(companionDiagnostics/);
});

test("release archives are deterministic and updates are recoverable", async () => {
  const build = await readFile(new URL("build-release.ps1", companionUrl), "utf8");
  const updater = await readFile(new URL("update-installed.ps1", companionUrl), "utf8");
  assert.match(build, /2000-01-01T00:00:00Z/);
  assert.match(build, /Sort-Object RelativePath/);
  assert.match(build, /normalizedTextExtensions/);
  assert.match(build, /Replace\("`r`n", "`n"\)\.Replace\("`r", "`n"\)/);
  assert.match(build, /UTF8Encoding.*\$false/);
  assert.match(build, /PSEdition.*Desktop/);
  assert.match(build, /Windows PowerShell 5\.1/);
  assert.match(build, /CompressionLevel\]::NoCompression/);
  assert.doesNotMatch(build, /Compress-Archive/);
  assert.match(updater, /backup-/i);
  assert.match(updater, /Compare-FileTree/);
  assert.match(updater, /backup tree was restored/i);
  assert.match(updater, /SyndicatumCompanionDirectoryIdentity/);
  assert.match(updater, /Clear-DirectoryContents \$target/);
  assert.match(updater, /rollback could not be verified/i);
  assert.match(updater, /Get-ChildItem[^\n]+-Force/);
  assert.match(updater, /Name -notmatch '\\\.backup-'/);
  assert.match(updater, /ReloadRequired = \$true/);
  assert.match(updater, /Join-Path \$PSScriptRoot 'extension'/);
  assert.doesNotMatch(updater.match(/param\([\s\S]*?\n\)/)?.[0] || "", /\$MyInvocation/);
});

test("ChatGPT adapter confirms the exact injected turn without capturing its response", async () => {
  const source = await readFile(new URL("providers/chatgpt.js", extensionUrl), "utf8");
  assert.match(source, /matchingUserTurnCount/);
  assert.match(source, /new_exact_user_turn/);
  assert.doesNotMatch(source, /waitForResponse/);
  assert.doesNotMatch(source, /responseText: captured/);
  assert.doesNotMatch(source, /userTurnCount\(\) > before/);
});

test("Gemini adapter confirms the exact injected turn", async () => {
  const source = await readFile(new URL("providers/gemini.js", extensionUrl), "utf8");
  assert.match(source, /registry\.gemini/);
  assert.match(source, /matchingUserTurnCount/);
  assert.match(source, /new_exact_user_turn/);
  assert.match(source, /waitForResponse/);
  assert.match(source, /responseText: captured/);
  assert.match(source, /Gemini said/);
});

test("only Gemini responses use the protected binding-scoped return path", async () => {
  const content = await readFile(new URL("content.js", extensionUrl), "utf8");
  const background = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(content, /syndicatum\.provider\.accepted/);
  assert.match(background, /connector-agent-replies\.php/);
  assert.match(background, /item\.provider === "gemini"/);
  assert.doesNotMatch(background, /\["chatgpt", "gemini"\]\.includes/);
  assert.match(background, /deliveryMetadata/);
  assert.doesNotMatch(background, /deliveryHistory[^;]*responseText/s);
});
