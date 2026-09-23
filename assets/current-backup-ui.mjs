const STAGES = ["Preparing backup", "Collecting production runtime", "Exporting database", "Collecting persistent files", "Securing configuration", "Building encrypted package", "Verifying package", "Complete"];

function displaySize(bytes) {
  if (bytes === null || bytes === undefined || bytes === "") return "Unavailable";
  const size = Number(bytes);
  if (!Number.isFinite(size) || size < 0) return "Unavailable";
  if (size < 1024) return `${size} B`;
  const unit = size < 1048576 ? "KB" : size < 1073741824 ? "MB" : "GB";
  const divisor = unit === "KB" ? 1024 : unit === "MB" ? 1048576 : 1073741824;
  return `${(size / divisor).toFixed(1)} ${unit}`;
}

function status(job) { return String(job?.status || "Queued"); }
function complete(job) { return status(job) === "Ready" || status(job) === "Failed"; }
function progress(job) { return Math.max(0, Math.min(100, Number(job?.overall_progress_percent) || 0)); }

export function mountCurrentBackup({ host, factories, api, request, csrfHeaders, toast, formatDate, realtime }) {
  let alive = true;
  let grid = null;
  let jobs = [];
  const deletedJobIds = new Set();
  let details = null;
  let running = null;
  let loadController = null;
  let inFlight = false;
  let columnWidths = null;
  let startModal = null;
  let startButton = null;
  let clearButton = null;
  let clearMenu = null;
  let clearModal = null;
  let clearing = false;
  let realtimeNotice = null;

  host.replaceChildren();

  const viewJob = (job) => ({
    ...job,
    id: job.operation_id,
    created_at: job.artifact_created_at,
    size_bytes: job.artifact_size_bytes,
    filename: job.artifact_filename,
    includes_data: job.include_data,
    error_message: job.failure_summary,
  });
  const jobById = (operationId) => jobs.find((job) => job.operation_id === operationId);
  const sortJobs = () => jobs.sort((a, b) => String(b.initiated_at || "").localeCompare(String(a.initiated_at || "")));
  const applyJob = (source) => {
    if (!source?.operation_id) return;
    if (deletedJobIds.has(source.operation_id)) return;
    const next = viewJob(source);
    const index = jobs.findIndex((job) => job.operation_id === next.operation_id);
    if (index >= 0 && Number(jobs[index].revision || 0) > Number(next.revision || 0)) return;
    if (index >= 0) jobs[index] = next;
    else jobs.push(next);
    sortJobs();
    grid?.setRows?.(jobs);
    if (details?.id === next.operation_id) details.update(next);
    if (running?.id === next.operation_id) running.update(next);
  };
  const icon = (name) => {
    try { return factories.createIcon(name, { size: 18, decorative: true })?.outerHTML || ""; }
    catch (_error) { return ""; }
  };
  const indeterminate = (label) => {
    const node = document.createElement("div");
    node.className = "recovery-backup-indeterminate";
    factories.createProgress(node, { label }, { style: "indeterminate", size: "sm", rounded: true,
      showLabel: false, showPercent: false, ariaLabel: label });
    return node;
  };

  async function refresh() {
    if (!alive || inFlight) return;
    inFlight = true;
    const controller = new AbortController();
    loadController = controller;
    try {
      const payload = await request(api.backups, { signal: controller.signal });
      if (!alive || controller.signal.aborted) return;
      const snapshot = Array.isArray(payload?.data?.backups) ? payload.data.backups.map(viewJob) : [];
      const current = new Map(jobs.map((job) => [job.operation_id, job]));
      jobs = snapshot.map((job) => Number(current.get(job.operation_id)?.revision || 0) > Number(job.revision || 0)
        ? current.get(job.operation_id) : job);
      for (const job of current.values()) {
        if (!jobs.some((entry) => entry.operation_id === job.operation_id)) jobs.push(job);
      }
      sortJobs();
      grid?.setRows?.(jobs);
      if (details) {
        const current = jobById(details.id);
        if (current) details.update(current);
      }
      if (running) {
        const current = jobById(running.id);
        if (current) running.update(current);
      }
    } catch (error) {
      if (alive && !controller.signal.aborted) {
        const notice = host.querySelector(".recovery-backup-load-error");
        if (notice) { notice.hidden = false; notice.textContent = `Could not refresh backups: ${error.message}`; }
      }
    } finally {
      if (loadController === controller) loadController = null;
      inFlight = false;
    }
  }

  function makeDetails(job) {
    if (details?.id === job.operation_id) { details.update(job); return; }
    const content = document.createElement("div");
    content.className = "recovery-backup-metadata";
    let modal;
    let latest = job;
    let deleting = false;
    const render = () => {
      content.replaceChildren();
      const state = status(latest);
      if (state === "Running" || state === "Queued") {
        const notice = document.createElement("p");
        notice.className = "recovery-workflow-notice";
        notice.textContent = "Backup is running in the background. A file and download will be available only after it is ready.";
        content.appendChild(notice);
      }
      const list = document.createElement("dl");
      list.className = "recovery-backup-metadata-list";
      const ended = latest.finished_at ? new Date(latest.finished_at).getTime() : NaN;
      const began = latest.initiated_at ? new Date(latest.initiated_at).getTime() : NaN;
      const duration = Number.isFinite(began) && Number.isFinite(ended) && ended >= began
        ? `${Math.round((ended - began) / 1000)}s` : state === "Running" || state === "Queued" ? "In progress" : "Unavailable";
      const timing = [
        ["Initiated", latest.initiated_at ? formatDate(latest.initiated_at) : "Unavailable"],
        ["Finished", latest.finished_at ? formatDate(latest.finished_at) : state === "Running" || state === "Queued" ? "In progress" : "Unavailable"],
        ["Duration", duration],
      ];
      const fields = state === "Ready" ? [
        ["Filename", latest.artifact_filename || "Unavailable"], ...timing,
        ["Size", displaySize(latest.artifact_size_bytes)], ["Status", state],
        ["Package type", latest.include_data ? "Full clone" : "Clean installation"],
        ["Initiated by", latest.initiated_by_name || "Unavailable"], ["Recovery key", null],
      ] : [
        ...timing, ["Status", state],
        ...(state === "Running" || state === "Queued" ? [["Overall progress", `${progress(latest)}%`], ["Stage", latest.stage || "Preparing backup"]] : []),
        ["Package type", latest.include_data ? "Full clone" : "Clean installation"],
        ["Initiated by", latest.initiated_by_name || "Unavailable"],
        ...(state === "Failed" ? [["Details", latest.failure_summary || "Backup failed; retry the operation."]] : []),
      ];
      for (const [label, value] of fields) {
        const term = document.createElement("dt"); term.textContent = label;
        const description = document.createElement("dd");
        if (label === "Recovery key") {
          description.className = "recovery-key-copy-row";
          const masked = document.createElement("span");
          masked.className = "recovery-key-copy-value";
          masked.textContent = "Protected · copy when needed";
          const button = document.createElement("button");
          button.type = "button";
          button.className = "ui-button ui-button-borderless recovery-key-copy-action";
          button.setAttribute("aria-label", "Copy backup recovery key");
          button.title = "Copy backup recovery key";
          button.innerHTML = icon("actions.copy");
          button.addEventListener("click", () => { void copyRecoveryKey(latest.operation_id, button, masked); });
          description.append(masked, button);
        } else description.textContent = value;
        list.append(term, description);
      }
      content.appendChild(list);
      modal?.setActions(actions());
    };
    const actions = () => [
      ...(complete(latest) ? [{ id: "delete", label: "Delete Backup", variant: "danger",
        className: "recovery-backup-delete-action", closeOnClick: false,
        onClick() { void deleteBackup(); return false; } }] : []),
      ...(status(latest) === "Ready" ? [
        { id: "kickstart", label: "Download Kickstart", closeOnClick: false,
          onClick() { downloadKickstart(); return false; } },
        { id: "download", label: "Download backup", variant: "primary", closeOnClick: false,
          onClick() { void authorizeDownload(latest.operation_id); return false; } },
      ] : []),
    ];
    async function deleteBackup() {
      if (deleting || !complete(latest)) return;
      const confirmed = await factories.uiConfirm(
        "Delete this backup package and its stored metadata? This cannot be undone.",
        { title: "Delete backup?", variant: "warning", confirmText: "Delete Backup", confirmVariant: "danger" },
      );
      if (!confirmed || !alive || deleting) return;
      deleting = true;
      modal.setBusy(true, { message: "Deleting backup…" });
      try {
        const operationId = latest.operation_id;
        await request(api.backups, { method: "DELETE", headers: csrfHeaders(),
          body: JSON.stringify({ operation_id: operationId }) });
        deletedJobIds.add(operationId);
        jobs = jobs.filter((entry) => entry.operation_id !== operationId);
        grid?.setRows?.(jobs);
        await modal.close({ reason: "backup-deleted", operationId });
        if (alive) toast.success("Backup deleted.");
      } catch (error) {
        if (alive) toast.error(error.message);
      } finally {
        deleting = false;
        if (modal.getState().open) modal.setBusy(false);
      }
    }
    modal = factories.createActionModal({ title: "Backup details", size: "md", content, actions: actions(), autoBusy: false,
      onClose() { if (details?.modal === modal) details = null; modal.destroy(); } });
    details = { id: job.operation_id, modal, update(next) { latest = next; render(); } };
    render();
    modal.open();
  }

  async function authorizeDownload(operationId) {
    try {
      const payload = await request(api.backupDownloads, { method: "POST", headers: csrfHeaders(),
        body: JSON.stringify({ operation_id: operationId }) });
      const downloadUrl = payload?.data?.download_url;
      if (!downloadUrl || !alive) throw new Error("A download could not be authorized.");
      const link = document.createElement("a");
      link.href = downloadUrl;
      link.rel = "noopener";
      document.body.appendChild(link);
      link.click(); link.remove();
    } catch (error) { if (alive) toast.error(error.message); }
  }

  function downloadKickstart() {
    const link = document.createElement("a");
    link.href = api.kickstartDownload;
    link.rel = "noopener";
    document.body.appendChild(link);
    link.click(); link.remove();
  }

  async function copyRecoveryKey(operationId, button, valueNode) {
    button.disabled = true;
    try {
      const payload = await request(api.backupRecoveryKey, { method: "POST", headers: csrfHeaders(),
        body: JSON.stringify({ operation_id: operationId }) });
      const recoveryKey = payload?.data?.recovery_key;
      if (!recoveryKey) throw new Error("The recovery key is unavailable.");
      if (!navigator.clipboard?.writeText) {
        valueNode.textContent = recoveryKey;
        throw new Error("Clipboard access is unavailable. Select and copy the displayed recovery key.");
      }
      await navigator.clipboard.writeText(recoveryKey);
      toast.success("Backup recovery key copied.");
    } catch (error) { if (alive) toast.error(error.message); }
    finally { button.disabled = false; }
  }

  function showProgress(job) {
    if (running?.id === job.operation_id) { running.update(job); return; }
    const content = document.createElement("div");
    content.className = "recovery-workflow-modal";
    const list = document.createElement("ol");
    list.className = "recovery-progress";
    const controls = STAGES.map((label) => {
      const item = document.createElement("li");
      const target = document.createElement("div");
      target.className = "recovery-progress-bar";
      const control = factories.createProgress(target, { label, value: 0 }, { style: "striped", size: "sm", rounded: true,
        showLabel: true, showPercent: true, ariaLabel: `${label} progress` });
      item.appendChild(target); list.appendChild(item);
      return control;
    });
    const overall = document.createElement("span");
    overall.className = "recovery-overall-progress";
    content.append(list);
    let modal;
    let terminalHandled = false;
    const update = (current) => {
      const index = Math.max(0, STAGES.indexOf(current.stage));
      const isReady = status(current) === "Ready";
      const isFailed = status(current) === "Failed";
      const currentPercent = Number(current.stage_progress_percent) || 0;
      STAGES.forEach((_label, stageIndex) => {
        const value = isReady || stageIndex < index ? 100 : stageIndex === index ? currentPercent : 0;
        controls[stageIndex].setValue(value);
        controls[stageIndex].update({}, { style: value === 100 ? "gradient" : "striped", animate: value < 100 });
      });
      overall.textContent = `Overall progress: ${isReady ? 100 : progress(current)}%`;
      if (isReady && !terminalHandled && modal?.getState?.().open) {
        terminalHandled = true;
        void modal.close({ reason: "backup-ready", operationId: current.operation_id });
      } else if (isFailed && !terminalHandled && modal?.getState?.().open) {
        terminalHandled = true;
        void modal.close({ reason: "backup-failed", operationId: current.operation_id,
          failureSummary: current.failure_summary || "The backup process failed." });
      }
    };
    modal = factories.createActionModal({ title: "Backup Now", size: "lg", className: "recovery-backup-preview-modal", content,
      actions: [{ id: "close", label: "Close" }],
      onClose(context = {}) {
        if (running?.modal === modal) running = null;
        controls.forEach((control) => control.destroy());
        modal.destroy();
        if (alive && context.reason === "backup-ready") {
          void factories.uiAlert("The backup completed successfully and is ready to download.", {
            title: "Backup successful",
            variant: "success",
          });
        } else if (alive && context.reason === "backup-failed") {
          void factories.uiAlert(context.failureSummary || "The backup process failed.", {
            title: "Backup failed",
            variant: "error",
            description: "Address the reported issue, then start a new backup.",
          });
        }
      } });
    running = { id: job.operation_id, modal, update };
    modal.open(); update(job);
    modal.refs.footer.prepend(overall);
  }

  function startBackup() {
    if (startModal) return;
    if (!realtime?.state?.().joined) {
      toast.error(realtime?.state?.().error || "PBB Realtime is not connected to the Backup room.");
      return;
    }
    let startedJob = null;
    startModal = factories.createFormModal({
      title: "Backup Now", size: "sm", className: "ui-dialog ui-dialog--warning recovery-backup-warning-dialog",
      submitLabel: "Start backup", cancelLabel: "Cancel",
      manageBusyOnSubmit: false,
      initialValues: { package_type: "full_clone" },
      rows: [
        [{ type: "alert", tone: "warning", content: "Create an encrypted, portable package of the current Syndicatum installation. The backup continues if you close this browser." }],
        [{ type: "select", name: "package_type", label: "Package type", required: true, options: [
          { value: "full_clone", label: "Full clone — include user-generated data" },
          { value: "clean_installation", label: "Clean installation — exclude user-generated data" },
        ], help: "Full clone includes user-generated records and referenced assets. Clean installation includes the production runtime, current database baseline, system settings, presets, and protected configuration without user-generated data or assets." }],
      ],
      async onSubmit(values, context) {
        if (!['full_clone', 'clean_installation'].includes(values.package_type)) {
          context.setErrors({ package_type: "Choose Full clone or Clean installation." });
          context.setFormError("Please address the following issues before continuing:\n• Package type — required");
          return false;
        }
        const idempotencyKey = crypto.randomUUID();
        context.setBusy(true, { message: "Starting backup…" });
        try {
          const payload = await request(api.backups, { method: "POST", headers: csrfHeaders({ "Idempotency-Key": idempotencyKey }),
            body: JSON.stringify({ include_data: values.package_type === "full_clone" }) });
          const job = payload?.data?.backup;
          if (!job?.operation_id) throw new Error("Backup start did not return an operation ID. Refresh the list before retrying.");
          applyJob(job);
          startedJob = job;
          return true;
        } catch (error) {
          if (context.modal.getState().open) context.setBusy(false);
          const definitelyNotStarted = error.status === 503
            && /no backup was started/i.test(String(error.message || ""));
          const formError = definitelyNotStarted
            ? error.message
            : `${error.message} If the request outcome is uncertain, refresh the list before starting another backup.`;
          await factories.uiAlert(error.message, {
            title: "Backup could not start",
            variant: "error",
            description: definitelyNotStarted
              ? "No backup was started. Address the reported service issue, then try again."
              : "Check the backup list before retrying if the request outcome is uncertain.",
          });
          if (context.modal.getState().open) context.setFormError(formError);
          return false;
        } finally {
          if (context.modal.getState().open) context.setBusy(false);
        }
      },
      onClose() { startModal = null; if (alive && startedJob) showProgress(startedJob); },
    });
    const warning = startModal.refs.rows.querySelector(".ui-form-modal-alert.is-warning");
    const warningIcon = factories.createIcon("status.warning", { size: 28, decorative: true });
    if (warning && warningIcon) warning.prepend(warningIcon);
    startModal.open();
  }

  function removeDeletedBackups(operationIds) {
    const ids = new Set(operationIds.map(String));
    ids.forEach((operationId) => deletedJobIds.add(operationId));
    jobs = jobs.filter((job) => !ids.has(String(job.operation_id)));
    grid?.setRows?.(jobs);
    if (details && ids.has(String(details.id))) void details.modal.close({ reason: "backup-cleared" });
  }

  async function clearBackups(scope) {
    if (clearing) return null;
    clearing = true;
    if (clearButton) clearButton.disabled = true;
    try {
      const confirmation = scope === "all" ? "CLEAR ALL BACKUPS" : "CLEAR FAILED";
      const payload = await request(api.backups, { method: "DELETE", headers: csrfHeaders(),
        body: JSON.stringify({ clear: scope, confirmation }) });
      const ids = Array.isArray(payload?.data?.deleted_operation_ids) ? payload.data.deleted_operation_ids : [];
      removeDeletedBackups(ids);
      toast.success(ids.length === 0 ? "There were no matching backups to clear."
        : scope === "all" ? `${ids.length} backups permanently deleted.` : `${ids.length} failed backups cleared.`);
      return ids;
    } catch (error) {
      toast.error(error.message);
      return null;
    } finally {
      clearing = false;
      if (clearButton) clearButton.disabled = false;
    }
  }

  async function confirmClearFailed() {
    const count = jobs.filter((job) => status(job) === "Failed").length;
    if (count === 0) { toast.success("There are no failed backups to clear."); return; }
    const confirmed = await factories.uiConfirm(
      `Permanently delete all ${count} failed backup records and any associated files? This cannot be undone.`,
      { title: "Clear all failed backups?", variant: "warning", confirmText: "Clear Failed", confirmVariant: "danger" },
    );
    if (confirmed && alive) await clearBackups("failed");
  }

  function confirmClearAll() {
    if (clearModal) return;
    const completedCount = jobs.filter((job) => complete(job)).length;
    if (completedCount === 0) { toast.success("There are no completed backups to clear."); return; }
    clearModal = factories.createFormModal({
      title: "Permanently clear all backups", size: "md", submitLabel: "Clear All Backups", cancelLabel: "Cancel",
      className: "ui-dialog ui-dialog--danger recovery-backup-clear-all-dialog", manageBusyOnSubmit: false,
      initialValues: { acknowledge: false, confirmation: "" },
      rows: [
        [{ type: "alert", tone: "warning", content: `This permanently deletes ${completedCount} completed backup package${completedCount === 1 ? "" : "s"}, failed records, inspection copies, and active download authorizations from this server. This cannot be undone. Downloaded copies will not be affected.` }],
        [{ type: "checkbox", name: "acknowledge", label: "I understand that every stored backup will be permanently deleted.", required: true }],
        [{ type: "input", name: "confirmation", label: "Type CLEAR ALL BACKUPS to continue", required: true, autocomplete: "off", help: "Enter exactly CLEAR ALL BACKUPS (uppercase, with single spaces)." }],
      ],
      async onSubmit(values, context) {
        const errors = {};
        if (!values.acknowledge) errors.acknowledge = "Confirm that every stored backup will be permanently deleted.";
        if (String(values.confirmation || "") !== "CLEAR ALL BACKUPS") errors.confirmation = "Enter CLEAR ALL BACKUPS exactly.";
        if (Object.keys(errors).length) {
          context.setErrors(errors);
          context.setFormError(`Please address the following issues before continuing:\n${!values.acknowledge ? "• Permanent deletion acknowledgement — required\n" : ""}${String(values.confirmation || "") !== "CLEAR ALL BACKUPS" ? "• Confirmation text — enter CLEAR ALL BACKUPS exactly" : ""}`.trim());
          return false;
        }
        context.setBusy(true, { message: "Permanently deleting backups…" });
        try { return (await clearBackups("all")) !== null; }
        finally { if (context.modal.getState().open) context.setBusy(false); }
      },
      onClose() { clearModal = null; },
    });
    clearModal.open();
  }

  function makeGrid(panel) {
    const error = document.createElement("p");
    error.className = "recovery-backup-load-error";
    error.hidden = true;
    error.setAttribute("role", "alert");
    startButton = document.createElement("button");
    startButton.type = "button"; startButton.className = "ui-button ui-button-primary";
    startButton.textContent = "Backup Now";
    startButton.addEventListener("click", startBackup);
    clearButton = document.createElement("button");
    clearButton.type = "button"; clearButton.className = "ui-button ui-button-ghost";
    clearButton.textContent = "Clear";
    clearMenu = factories.createDropdown(clearButton, [
      { id: "failed", label: "Clear all Failed" },
      { id: "all", label: "Clear all Backups" },
    ], { align: "right", ariaLabel: "Clear backups", onSelect(item) {
      if (item.id === "failed") void confirmClearFailed();
      if (item.id === "all") confirmClearAll();
    } });
    realtimeNotice = document.createElement("p");
    realtimeNotice.className = "recovery-backup-realtime-error";
    realtimeNotice.setAttribute("role", "alert");
    realtimeNotice.hidden = true;
    const gridHost = document.createElement("div");
    gridHost.className = "recovery-backup-grid";
    gridHost.appendChild(error);
    const controls = document.createElement("div");
    controls.className = "recovery-backup-controls";
    const actions = document.createElement("div");
    actions.className = "recovery-backup-actions";
    actions.append(startButton, clearButton);
    controls.append(actions, realtimeNotice);
    panel.append(controls, gridHost);
    const width = Math.max(800, (host.clientWidth || panel.clientWidth || 1016) - 16);
    columnWidths ||= { initiated_at: Math.round(width * .21), artifact_created_at: Math.round(width * .21),
      artifact_size_bytes: Math.round(width * .12), status: Math.round(width * .12),
      include_data: Math.round(width * .12), initiated_by_name: Math.round(width * .22) };
    grid = factories.createGrid(gridHost, jobs, { className: "recovery-backups-grid", chrome: false,
      columns: [
        { key: "initiated_at", label: "Initiated", format: (value) => value ? formatDate(value) : "" },
        { key: "artifact_created_at", label: "Created", sortable: false,
          renderCell({ row }) { return status(row) === "Running" || status(row) === "Queued"
            ? indeterminate("Backup file being created") : row.artifact_created_at ? formatDate(row.artifact_created_at) : ""; } },
        { key: "artifact_size_bytes", label: "Size", format: (_value, row) => complete(row)
          ? displaySize(row.artifact_size_bytes) : `${progress(row)}%` },
        { key: "status", label: "Status", format: (value) => String(value || "Queued") },
        { key: "include_data", label: "Data", sortable: false, renderCell({ row }) {
          if (!complete(row)) return indeterminate("Backup in progress");
          if (status(row) === "Failed") return "";
          const marker = document.createElement("span");
          const includesData = row.include_data === true;
          marker.className = `recovery-backup-data-marker ${includesData ? "is-included" : "is-excluded"}`;
          marker.setAttribute("role", "img");
          marker.setAttribute("aria-label", includesData ? "Includes user-generated data" : "Excludes user-generated data");
          marker.title = includesData ? "Full clone" : "Clean installation";
          marker.innerHTML = icon(includesData ? "status.success" : "status.error"); return marker;
        } },
        { key: "initiated_by_name", label: "Initiated by", format: (value) => value || "Unavailable" },
      ], columnWidths, enableColumnResize: true, minColumnWidth: 100,
      enableSort: false, enableSearch: false, enablePagination: false,
      emptyText: "No backups yet.", onRowClick: makeDetails,
      onColumnResize({ columnWidths: resized }) { columnWidths = resized; },
    });
  }

  function syncRealtimeState(message = null) {
    const connection = realtime?.state?.() || {};
    const joined = connection.joined === true;
    if (startButton) startButton.disabled = !joined;
    if (realtimeNotice) {
      realtimeNotice.hidden = joined;
      realtimeNotice.textContent = joined ? "" : (message || connection.error || "Connecting to PBB Realtime Backup updates…");
    }
  }

  const onBackupUpdated = (event) => { if (alive) applyJob(event?.detail?.backup); };
  const onRealtimeReady = (event) => {
    if (!alive || !Array.isArray(event?.detail?.rooms) || !event.detail.rooms.includes(realtime?.room)) return;
    syncRealtimeState();
    void refresh();
  };
  const onRealtimeError = (event) => { if (alive) syncRealtimeState(event?.detail?.message); };

  makeGrid(host);
  window.addEventListener("syndicatum:backup-updated", onBackupUpdated);
  window.addEventListener("syndicatum:realtime-ready", onRealtimeReady);
  window.addEventListener("syndicatum:realtime-error", onRealtimeError);
  syncRealtimeState();
  void refresh();
  return { destroy() {
    alive = false; loadController?.abort();
    window.removeEventListener("syndicatum:backup-updated", onBackupUpdated);
    window.removeEventListener("syndicatum:realtime-ready", onRealtimeReady);
    window.removeEventListener("syndicatum:realtime-error", onRealtimeError);
    grid?.destroy?.();
    details?.modal?.close?.({ reason: "navigation" });
    running?.modal?.close?.({ reason: "navigation" });
    startModal?.close?.({ reason: "navigation" });
    clearModal?.close?.({ reason: "navigation" });
    clearMenu?.destroy?.();
  } };
}
