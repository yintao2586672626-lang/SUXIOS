import { randomUUID } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

export const FRONTEND_TEMPLATE_LOCK_RELATIVE_PATH = 'runtime/locks/frontend-template.lock';

const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

function readLockRecord(lockPath) {
  try {
    const stat = fs.statSync(lockPath);
    const raw = fs.readFileSync(lockPath, 'utf8');
    let metadata = null;
    try {
      metadata = JSON.parse(raw);
    } catch {
      metadata = null;
    }
    return { stat, raw, metadata };
  } catch (error) {
    if (error?.code === 'ENOENT') return null;
    throw error;
  }
}

function processIsAlive(pid) {
  if (!Number.isSafeInteger(pid) || pid < 1) return false;
  try {
    process.kill(pid, 0);
    return true;
  } catch (error) {
    return error?.code === 'EPERM';
  }
}

function removeObservedLock(lockPath, observed) {
  const current = readLockRecord(lockPath);
  if (!current) return true;
  const observedToken = observed?.metadata?.token;
  if (observedToken) {
    if (current.metadata?.token !== observedToken) return false;
  } else if (current.stat.mtimeMs !== observed?.stat?.mtimeMs || current.stat.size !== observed?.stat?.size) {
    return false;
  }
  try {
    fs.unlinkSync(lockPath);
    return true;
  } catch (error) {
    if (error?.code === 'ENOENT') return true;
    return false;
  }
}

export async function acquireFrontendTemplateLock(repoRoot, {
  owner = 'frontend-template-operation',
  waitTimeoutMs = 30_000,
  pollIntervalMs = 100,
  staleAfterMs = 300_000,
} = {}) {
  const lockPath = path.resolve(repoRoot, FRONTEND_TEMPLATE_LOCK_RELATIVE_PATH);
  const lockRoot = path.dirname(lockPath);
  fs.mkdirSync(lockRoot, { recursive: true });
  const token = randomUUID();
  const startedAt = Date.now();

  while (true) {
    let descriptor = null;
    try {
      descriptor = fs.openSync(lockPath, 'wx');
      const metadata = {
        schema_version: 1,
        token,
        pid: process.pid,
        owner: String(owner || 'frontend-template-operation'),
        acquired_at: new Date().toISOString(),
      };
      fs.writeFileSync(descriptor, `${JSON.stringify(metadata)}\n`, 'utf8');
      fs.closeSync(descriptor);
      descriptor = null;

      let released = false;
      return () => {
        if (released) return;
        released = true;
        const current = readLockRecord(lockPath);
        if (current?.metadata?.token !== token) return;
        try {
          fs.unlinkSync(lockPath);
        } catch (error) {
          if (error?.code !== 'ENOENT') throw error;
        }
      };
    } catch (error) {
      if (descriptor !== null) {
        try {
          fs.closeSync(descriptor);
        } catch {
          // The original lock acquisition error is more useful.
        }
        try {
          fs.unlinkSync(lockPath);
        } catch {
          // Another process may already have removed the incomplete lock.
        }
      }
      if (error?.code !== 'EEXIST') throw error;

      const observed = readLockRecord(lockPath);
      if (!observed) continue;
      const lockAgeMs = Date.now() - observed.stat.mtimeMs;
      const ownerPid = Number(observed.metadata?.pid || 0);
      const stale = ownerPid > 0
        ? !processIsAlive(ownerPid)
        : lockAgeMs >= staleAfterMs;
      if (stale && removeObservedLock(lockPath, observed)) continue;

      if (Date.now() - startedAt >= waitTimeoutMs) {
        const ownerText = observed.metadata?.owner || 'unknown-owner';
        const pidText = ownerPid > 0 ? ownerPid : 'unknown';
        throw new Error(`Frontend template workspace is locked by pid ${pidText} (${ownerText}).`);
      }
      await wait(Math.max(10, pollIntervalMs));
    }
  }
}

export async function withFrontendTemplateLock(repoRoot, action, options = {}) {
  const release = await acquireFrontendTemplateLock(repoRoot, options);
  try {
    return await action();
  } finally {
    release();
  }
}

export function writeFileAtomic(file, content) {
  const buffer = Buffer.isBuffer(content) ? content : Buffer.from(String(content), 'utf8');
  const directory = path.dirname(file);
  fs.mkdirSync(directory, { recursive: true });
  const temporary = path.join(directory, `.${path.basename(file)}.${process.pid}.${randomUUID()}.tmp`);
  try {
    fs.writeFileSync(temporary, buffer);
    fs.renameSync(temporary, file);
  } finally {
    if (fs.existsSync(temporary)) fs.unlinkSync(temporary);
  }
}
