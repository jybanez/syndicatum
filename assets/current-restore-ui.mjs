const STAGES = ["Authenticating package", "Checking compatibility", "Preparing user data", "Restoring database batches",
  "Restoring persistent files", "Verifying restored data", "Complete"];

export function mountCurrentRestore({ host, factories, api, request, csrfHeaders, toast, formatDate, realtime }) {
  let alive = true;
  let sourceModal = null;
  let progressModal = null;
  let running = null;
  let abortController = null;

  const realtimeReady = () => realtime?.state?.().joined === true;
  const startGuard = () => {
    if (realtimeReady()) return true;
    toast.error(realtime?.state?.().error || "PBB Realtime is not connected to the Backup room.");
    return false;
  };

  async function loadSources(signal) {
    return request(api.restores, { signal });
  }

  async function startExisting(backup) {
    const idempotencyKey = crypto.randomUUID();
    const payload = await request(api.restores, { method: "POST", headers: csrfHeaders({ "Idempotency-Key": idempotencyKey }),
      body: JSON.stringify({ backup_operation_id: backup.operation_id, confirmation: "RESTORE DATA" }) });
    return payload?.data?.restore;
  }

  function confirmExisting(backup) {
    let started = null;
    const modal = factories.createFormModal({
      title: "Restore user-generated data", size: "md", submitLabel: "Restore user data", cancelLabel: "Cancel",
      manageBusyOnSubmit: false,
      initialValues: { confirmation: "", replace_ack: false },
      rows: [
        [{ type: "alert", tone: "warning", content: "This replaces the current user-generated records with the selected backup. Runtime files, database schema, system settings, integrations, server secrets, and backup history remain unchanged." }],
        [{ type: "display", name: "backup", label: "Backup", value: backup.artifact_filename || backup.operation_id }],
        [{ type: "display", name: "created", label: "Created", value: backup.artifact_created_at ? formatDate(backup.artifact_created_at) : "Unavailable" }],
        [{ type: "checkbox", name: "replace_ack", label: "I understand that current user-generated data will be replaced.", required: true }],
        [{ type: "input", name: "confirmation", label: "Type RESTORE DATA to continue", required: true, autocomplete: "off", help: "Enter exactly RESTORE DATA (uppercase, with one space)." }],
      ],
      async onSubmit(values, context) {
        const errors = {};
        if (!values.replace_ack) errors.replace_ack = "Confirm that current user-generated data will be replaced.";
        if (String(values.confirmation || "") !== "RESTORE DATA") errors.confirmation = "Enter RESTORE DATA exactly.";
        if (Object.keys(errors).length) {
          context.setErrors(errors);
          context.setFormError(`Please address the following issues before continuing:\n${!values.replace_ack ? "• Replacement confirmation — required\n" : ""}${String(values.confirmation || "") !== "RESTORE DATA" ? "• Confirmation text — enter RESTORE DATA exactly" : ""}`.trim());
          return false;
        }
        if (!startGuard()) return false;
        context.setBusy(true, { message: "Starting restore…" });
        try {
          started = await startExisting(backup);
          if (!started?.operation_id) throw new Error("Restore start did not return an operation ID.");
          return true;
        } catch (error) {
          context.setFormError(`${error.message} If the request outcome is uncertain, do not submit a different restore.`);
          return false;
        } finally { if (context.modal.getState().open) context.setBusy(false); }
      },
      onClose() { if (alive && started) showProgress(started); },
    });
    modal.open();
  }

  function chooseExisting() {
    if (!startGuard() || sourceModal) return;
    const content = document.createElement("div");
    let sourceGrid = null;
    const loading = document.createElement("div");
    loading.className = "recovery-restore-loading";
    factories.createProgress(loading, { label: "Loading Ready backups" }, { style: "indeterminate", showLabel: true, showPercent: false, ariaLabel: "Loading Ready backups" });
    content.appendChild(loading);
    sourceModal = factories.createActionModal({ title: "Select existing backup", size: "lg", content,
      actions: [{ id: "close", label: "Cancel" }],
      onClose() { abortController?.abort(); abortController = null; sourceGrid?.destroy?.(); sourceModal?.destroy(); sourceModal = null; },
    });
    sourceModal.open();
    sourceModal.setBusy(true, { message: "Loading Ready backups…" });
    const controller = new AbortController(); abortController = controller;
    loadSources(controller.signal).then((payload) => {
      if (!alive || controller.signal.aborted || !sourceModal) return;
      const rows = Array.isArray(payload?.data?.backups) ? payload.data.backups : [];
      content.replaceChildren();
      const gridHost = document.createElement("div"); content.appendChild(gridHost);
      sourceGrid = factories.createGrid(gridHost, rows, { chrome: false, enableSort: false, enableSearch: false, enablePagination: false,
        emptyText: "No Ready full-clone backups are available.",
        columns: [
          { key: "artifact_created_at", label: "Created", format: (value) => value ? formatDate(value) : "Unavailable" },
          { key: "artifact_filename", label: "Filename", format: (value) => value || "Unavailable" },
          { key: "artifact_size_bytes", label: "Size", format: (value) => `${(Number(value || 0) / 1048576).toFixed(1)} MB` },
          { key: "initiated_by_name", label: "Created by", format: (value) => value || "Unavailable" },
        ],
        onRowClick(backup) { const modal = sourceModal; modal.close({ reason: "selected" }).then(() => { if (alive) confirmExisting(backup); }); },
      });
      sourceModal.setBusy(false);
    }).catch((error) => {
      if (!alive || controller.signal.aborted || !sourceModal) return;
      content.replaceChildren();
      const message = document.createElement("p"); message.className = "recovery-backup-load-error"; message.setAttribute("role", "alert");
      message.textContent = `Ready backups could not be loaded: ${error.message}`; content.appendChild(message);
      sourceModal.setBusy(false);
    });
  }

  async function uploadRestore(item, recoveryKey, controls) {
    const chunkBytes = 1024 * 1024;
    const chunkCount = Math.ceil(item.file.size / chunkBytes);
    if (chunkCount < 1 || chunkCount > 8192) throw new Error("Backup files must be between 1 byte and 8 GiB.");
    const uploadId = crypto.randomUUID();
    const idempotencyKey = crypto.randomUUID();
    let restore = null;
    for (let index = 0; index < chunkCount; index += 1) {
      if (controls.signal.aborted) throw new Error("Upload cancelled before the restore started.");
      restore = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest(); xhr.open("POST", api.restores); xhr.responseType = "json";
        const csrf = csrfHeaders()["X-CSRF-Token"]; if (csrf) xhr.setRequestHeader("X-CSRF-Token", csrf);
        xhr.setRequestHeader("Idempotency-Key", idempotencyKey);
        xhr.upload.addEventListener("progress", (event) => { if (event.lengthComputable) controls.report(Math.min(95, ((index + event.loaded / event.total) / chunkCount) * 95)); });
        xhr.addEventListener("load", () => { const payload = xhr.response; if (xhr.status >= 200 && xhr.status < 300) resolve(payload?.data?.restore || null); else reject(new Error(payload?.message || `Restore upload failed with status ${xhr.status}`)); });
        xhr.addEventListener("error", () => reject(new Error("Upload outcome is uncertain. Check the restore state before trying again.")));
        xhr.addEventListener("abort", () => reject(new Error("Upload cancelled before the restore started.")));
        controls.signal.addEventListener("abort", () => xhr.abort(), { once: true });
        const form = new FormData();
        form.append("backup_chunk", item.file.slice(index * chunkBytes, Math.min(item.file.size, (index + 1) * chunkBytes)), `chunk-${index}.bin`);
        form.append("upload_id", uploadId); form.append("chunk_index", String(index)); form.append("chunk_count", String(chunkCount));
        form.append("recovery_key", recoveryKey); form.append("confirmation", "RESTORE DATA"); xhr.send(form);
      });
    }
    controls.report(100);
    return restore;
  }

  function chooseUpload() {
    if (!startGuard()) return;
    const content = document.createElement("div"); content.className = "recovery-restore-upload";
    const note = document.createElement("p"); note.textContent = "Upload one full-clone .syndicatum-backup package. Only its user-generated data will be restored.";
    const keyLabel = document.createElement("label"); keyLabel.textContent = "Backup recovery key"; keyLabel.htmlFor = "restore-recovery-key";
    const key = document.createElement("input"); key.id = "restore-recovery-key"; key.type = "password"; key.className = "ui-input"; key.autocomplete = "off"; key.required = true;
    const keyError = document.createElement("p"); keyError.className = "ui-field-error"; keyError.id = "restore-recovery-key-error"; keyError.hidden = true;
    const ackLabel = document.createElement("label"); const ack = document.createElement("input"); ack.type = "checkbox";
    ackLabel.append(ack, document.createTextNode(" I understand that current user-generated data will be replaced."));
    const ackError = document.createElement("p"); ackError.className = "ui-field-error"; ackError.id = "restore-replacement-error"; ackError.hidden = true;
    const uploaderHost = document.createElement("div"); content.append(note, keyLabel, key, keyError, ackLabel, ackError, uploaderHost);
    let started = null; let modal;
    const uploader = factories.createFileUploader(uploaderHost, { ariaLabel: "Upload backup for data restore", accept: ".syndicatum-backup", allowedTypes: [".syndicatum-backup"], multiple: false, maxFiles: 1,
      maxFileSize: 2 * 1024 * 1024 * 1024, startText: "Restore user data", dropText: "Drop one encrypted backup here or choose Browse.",
      async onUpload(item, controls) {
        const issues = []; key.classList.remove("is-invalid"); key.removeAttribute("aria-invalid"); keyError.hidden = true;
        if (!key.value.trim()) { issues.push("Backup recovery key — required"); key.classList.add("is-invalid"); key.setAttribute("aria-invalid", "true"); key.setAttribute("aria-describedby", keyError.id); keyError.textContent = "Enter the recovery key shown in the backup details."; keyError.hidden = false; }
        ack.removeAttribute("aria-invalid"); ackError.hidden = true;
        if (!ack.checked) { issues.push("Replacement confirmation — required"); ack.setAttribute("aria-invalid", "true"); ack.setAttribute("aria-describedby", ackError.id); ackError.textContent = "Confirm that current user-generated data will be replaced."; ackError.hidden = false; }
        if (issues.length) { key.focus(); throw new Error(`Please address the following issues before continuing:\n• ${issues.join("\n• ")}`); }
        started = await uploadRestore(item, key.value.trim(), controls); return started;
      },
      onComplete(state) { if (!started || !state.items.some((item) => item.status === "success")) return; modal.close({ reason: "started" }); },
    });
    key.addEventListener("input", () => { if (key.value.trim()) { key.classList.remove("is-invalid"); key.removeAttribute("aria-invalid"); keyError.hidden = true; } });
    ack.addEventListener("change", () => { if (ack.checked) { ack.removeAttribute("aria-invalid"); ackError.hidden = true; } });
    modal = factories.createActionModal({ title: "Upload backup", size: "lg", content, actions: [{ id: "close", label: "Cancel" }],
      onClose(context = {}) { uploader.destroy?.(); modal.destroy(); if (alive && context.reason === "started" && started) showProgress(started); },
    }); modal.open();
  }

  function showProgress(job) {
    running = job;
    const content = document.createElement("div"); content.className = "recovery-workflow-modal";
    const list = document.createElement("ol"); list.className = "recovery-progress";
    const bars = STAGES.map((label) => { const item=document.createElement("li");const mount=document.createElement("div");const bar=factories.createProgress(mount,{label,value:0},{style:"striped",size:"sm",rounded:true,showLabel:true,showPercent:true,ariaLabel:`${label} progress`});item.appendChild(mount);list.appendChild(item);return bar; });
    const overall=document.createElement("span");overall.className="recovery-overall-progress";content.appendChild(list);
    let modal;let terminal=false;
    const update=(next)=>{if(!next||next.operation_id!==job.operation_id)return;running=next;const index=Math.max(0,STAGES.indexOf(next.stage));const success=next.status==="Complete";const failed=next.status==="Failed";STAGES.forEach((_label,i)=>{const value=success||i<index?100:i===index?Number(next.stage_progress_percent||0):0;bars[i].setValue(value);bars[i].update({}, {style:value===100?"gradient":"striped",animate:value<100});});overall.textContent=`Overall progress: ${success?100:Number(next.overall_progress_percent||0)}%`;if((success||failed)&&!terminal){terminal=true;modal.close({reason:success?"complete":"failed",message:next.failure_summary});}};
    modal=factories.createActionModal({title:"Restore user-generated data",size:"lg",content,actions:[{id:"close",label:"Close"}],onClose(context={}){progressModal=null;bars.forEach((bar)=>bar.destroy());modal.destroy();if(!alive)return;if(context.reason==="complete")void factories.uiAlert("User-generated data was restored successfully. You may need to sign in again.",{title:"Restore complete",variant:"success"});if(context.reason==="failed")void factories.uiAlert(context.message||"The restore failed.",{title:"Restore failed",variant:"error",description:"Address the reported issue, then start a new restore. No automatic retry was attempted."});}});
    progressModal={modal,update};modal.open();modal.refs.footer.prepend(overall);update(job);
  }

  const onRestore=(event)=>{const job=event?.detail?.restore;if(!job)return;if(progressModal)progressModal.update(job);else if(["Queued","Running"].includes(job.status))showProgress(job);};
  const panel=document.createElement("section");panel.className="recovery-restore-choices";
  const heading=document.createElement("h2");heading.textContent="Restore user-generated data";
  const intro=document.createElement("p");intro.textContent="Restore users, projects, conversations, agents, and referenced user assets without replacing the installed application, schema, settings, integrations, or server secrets.";
  const choices=document.createElement("div");choices.className="recovery-restore-choice-grid";
  const makeChoice=(title,text,label,handler)=>{const card=document.createElement("section");card.className="ui-panel recovery-restore-choice";const h=document.createElement("h3");h.textContent=title;const p=document.createElement("p");p.textContent=text;const button=document.createElement("button");button.type="button";button.className="ui-button ui-button-primary";button.textContent=label;button.addEventListener("click",handler);card.append(h,p,button);return card;};
  choices.append(makeChoice("Select existing backup","Choose from recent Ready full-clone backups stored on this server.","Select existing backup",chooseExisting),makeChoice("Upload backup file","Provide a full-clone backup from another location and its recovery key.","Upload backup file",chooseUpload));
  panel.append(heading,intro,choices);host.replaceChildren(panel);window.addEventListener("syndicatum:restore-updated",onRestore);
  const controller=new AbortController();loadSources(controller.signal).then((payload)=>{if(!alive)return;const active=(payload?.data?.restores||[]).find((job)=>["Queued","Running"].includes(job.status));if(active&&!progressModal)showProgress(active);}).catch(()=>{});
  return {destroy(){alive=false;controller.abort();abortController?.abort();window.removeEventListener("syndicatum:restore-updated",onRestore);sourceModal?.close?.({reason:"navigation"});progressModal?.modal?.close?.({reason:"navigation"});}};
}
