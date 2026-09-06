import { mkdir, readFile, rename, writeFile } from "node:fs/promises";
import path from "node:path";

export class StateStore {
  constructor(file) {
    this.file = file;
    this.state = { processedMessageIds: [], pendingNotifications: [], lastSequence: 0 };
  }
  async load() {
    try {
      const value = JSON.parse(await readFile(this.file, "utf8"));
      this.state.processedMessageIds = Array.isArray(value.processedMessageIds) ? value.processedMessageIds.map(String).slice(-1000) : [];
      this.state.pendingNotifications = Array.isArray(value.pendingNotifications) ? value.pendingNotifications.map(normalize).filter(Boolean).slice(-1000) : [];
      this.state.lastSequence = Math.max(0, Number(value.lastSequence) || 0);
    } catch (error) { if (error.code !== "ENOENT") throw error; }
  }
  has(id) { return this.state.processedMessageIds.includes(String(id)); }
  pending() { return this.state.pendingNotifications.map(item => ({ ...item })); }
  async markPending(message) {
    const item = normalize(message);
    if (!item || this.has(item.id) || this.state.pendingNotifications.some(entry => entry.id === item.id)) return;
    this.state.pendingNotifications.push(item);
    this.state.pendingNotifications = this.state.pendingNotifications.slice(-1000);
    await this.save();
  }
  async markProcessed(message) {
    this.state.processedMessageIds = [...this.state.processedMessageIds.filter(id => id !== String(message.id)), String(message.id)].slice(-1000);
    this.state.pendingNotifications = this.state.pendingNotifications.filter(item => item.id !== String(message.id));
    this.state.lastSequence = Math.max(this.state.lastSequence, Number(message.project_sequence ?? message.sequence) || 0);
    await this.save();
  }
  async observeSequence(message) {
    const sequence = Number(message.project_sequence ?? message.sequence) || 0;
    if (sequence > this.state.lastSequence) { this.state.lastSequence = sequence; await this.save(); }
  }
  async save() {
    await mkdir(path.dirname(this.file), { recursive: true });
    const temporary = `${this.file}.${process.pid}.tmp`;
    await writeFile(temporary, `${JSON.stringify(this.state, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
    await rename(temporary, this.file);
  }
}

function normalize(message) {
  const id = String(message?.id ?? "").trim();
  if (!id) return null;
  return {
    id,
    project_id: message.project_id ?? null,
    project_sequence: Number(message.project_sequence ?? message.sequence) || 0,
    sender: { id: String(message.sender?.id ?? message.sender?.participant_id ?? "") },
    addressees: Array.isArray(message.addressees) ? message.addressees.map(entry => ({ participant_id: String(entry.participant_id ?? entry.id ?? "") })).filter(entry => entry.participant_id) : [],
  };
}
