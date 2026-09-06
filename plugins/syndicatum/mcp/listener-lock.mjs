import { mkdir, open, readFile, rm } from "node:fs/promises";
import path from "node:path";

export class ListenerLock {
  constructor(file, pid = process.pid) { this.file = file; this.pid = pid; this.handle = null; }

  async acquire() {
    await mkdir(path.dirname(this.file), { recursive: true });
    for (let attempt = 0; attempt < 2; attempt += 1) {
      try {
        this.handle = await open(this.file, "wx", 0o600);
        await this.handle.writeFile(`${this.pid}\n`, "utf8");
        return { acquired: true };
      } catch (error) {
        if (error.code !== "EEXIST") throw error;
        const ownerPid = await this.ownerPid();
        if (ownerPid && isProcessAlive(ownerPid)) return { acquired: false, ownerPid };
        await rm(this.file, { force: true });
      }
    }
    return { acquired: false, ownerPid: await this.ownerPid() };
  }

  async release() {
    if (!this.handle) return;
    await this.handle.close();
    this.handle = null;
    if (await this.ownerPid() === this.pid) await rm(this.file, { force: true });
  }

  async ownerPid() {
    try {
      const value = Number.parseInt((await readFile(this.file, "utf8")).trim(), 10);
      return Number.isSafeInteger(value) && value > 0 ? value : null;
    } catch (error) {
      if (error.code === "ENOENT") return null;
      throw error;
    }
  }
}

function isProcessAlive(pid) {
  try { process.kill(pid, 0); return true; }
  catch (error) { return error.code === "EPERM"; }
}
