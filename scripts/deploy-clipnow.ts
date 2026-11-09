/**
 * Publishes ClipNow (the clipper/ folder, including clipper/admin/) to its own domain,
 * clipnow.vip, on the same Bluehost account as the main site, via the cPanel API
 * (same credentials as deploy.ts).
 *
 * clipnow.vip's document root in cPanel → Domains is
 *   /home1/thrypjmy/public_html/website_05f463c5
 * (not public_html/clipnow.vip). CLIPNOW_DOCROOT overrides it.
 *
 * CPANEL_HOST is the cPanel host, optionally with a port:
 *   sh00529.bluehost.com            → port 2083 (works from a PC)
 *   cpanel.clipnow.vip:443          → cPanel's proxy address on the normal web port
 *                                     (use this from cloud sessions, where 2083 is blocked)
 *
 * The admin portal's private settings live in ~/clipnow-data/ on the server and are
 * never uploaded or overwritten by this script.
 *
 * Run: npx tsx scripts/deploy-clipnow.ts
 */
import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import path from 'node:path';

// Settings come from environment variables (cloud sessions) or, on Joseph's PC, the env file.
const ENV_FILE = 'C:/Users/memph/.bluehost-cpanel.env';
const fileEnv = existsSync(ENV_FILE) ? readFileSync(ENV_FILE, 'utf-8') : '';
const get = (k: string) => process.env[k]?.trim() || fileEnv.match(new RegExp(`${k}=(.*)`))?.[1].trim();
for (const k of ['CPANEL_HOST', 'CPANEL_USER', 'CPANEL_TOKEN']) {
  if (!get(k)) throw new Error(`Missing ${k}: set it as an environment variable or in ${ENV_FILE}`);
}
const hostSetting = get('CPANEL_HOST')!.replace(/^https?:\/\//, '').replace(/\/+$/, '');
const HOST = `https://${/:\d+$/.test(hostSetting) ? hostSetting : `${hostSetting}:2083`}`;
const AUTH = { Authorization: `cpanel ${get('CPANEL_USER')}:${get('CPANEL_TOKEN')}` };
const REMOTE_ROOT = get('CLIPNOW_DOCROOT') || '/home1/thrypjmy/public_html/website_05f463c5';
const LOCAL_ROOT = path.resolve(__dirname, '..', 'clipper');
// Remembers what was last uploaded so unchanged files (like the big videos) are skipped next time.
// Delete this file, or run with CLIPNOW_FULL=1, to upload everything again.
const CACHE_FILE = path.resolve(__dirname, '..', '.clipnow-deploy-cache.json');
const hashOf = (rel: string) => createHash('sha256').update(readFileSync(path.join(LOCAL_ROOT, rel))).digest('hex');

/** Every file to publish, as paths relative to clipper/ (skips names starting with "_"). */
function listFiles(dir = ''): string[] {
  return readdirSync(path.join(LOCAL_ROOT, dir)).flatMap((n) => {
    if (n.startsWith('_')) return [];
    const rel = dir ? `${dir}/${n}` : n;
    return statSync(path.join(LOCAL_ROOT, rel)).isDirectory() ? listFiles(rel) : [rel];
  });
}

async function api2(module: string, func: string, params: Record<string, string>): Promise<any> {
  const body = new URLSearchParams({ cpanel_jsonapi_version: '2', cpanel_jsonapi_module: module, cpanel_jsonapi_func: func, ...params });
  const res = await fetch(`${HOST}/json-api/cpanel`, { method: 'POST', headers: AUTH, body });
  return (await res.json()).cpanelresult;
}

async function ensureDir(rel: string): Promise<void> {
  if (!rel) return;
  const parent = path.posix.dirname(rel);
  await ensureDir(parent === '.' ? '' : parent);
  // Fails harmlessly when the folder already exists.
  await api2('Fileman', 'mkdir', { path: `${REMOTE_ROOT}/${parent === '.' ? '' : parent}`.replace(/\/$/, ''), name: path.posix.basename(rel), permissions: '0755' });
}

async function upload(rel: string): Promise<boolean> {
  const dir = path.posix.dirname(rel);
  const remoteDir = dir === '.' ? REMOTE_ROOT : `${REMOTE_ROOT}/${dir}`;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const form = new FormData();
      form.append('dir', remoteDir);
      form.append('overwrite', '1');
      form.append('file-1', new Blob([readFileSync(path.join(LOCAL_ROOT, rel))]), path.posix.basename(rel));
      const res = await fetch(`${HOST}/execute/Fileman/upload_files`, { method: 'POST', headers: AUTH, body: form });
      const j: any = await res.json();
      if (j.data?.succeeded === 1) {
        await api2('Fileman', 'fileop', { op: 'chmod', metadata: '0644', sourcefiles: `${remoteDir}/${path.posix.basename(rel)}` });
        return true;
      }
    } catch {
      // transient connection reset; wait and retry
    }
    await new Promise((r) => setTimeout(r, 3000 * attempt));
  }
  return false;
}

async function main() {
  const files = listFiles();
  console.log(`publishing ${files.length} files to ${REMOTE_ROOT} via ${HOST} ...`);
  for (const d of new Set(files.map((f) => path.posix.dirname(f)).filter((d) => d !== '.'))) await ensureDir(d);
  const cacheKey = `${HOST}|${REMOTE_ROOT}`;
  let cache: Record<string, Record<string, string>> = {};
  try { cache = JSON.parse(readFileSync(CACHE_FILE, 'utf-8')); } catch {}
  const done: Record<string, string> = process.env.CLIPNOW_FULL ? {} : { ...(cache[cacheKey] || {}) };
  const failures: string[] = [];
  let skipped = 0;
  for (const f of files) {
    const h = hashOf(f);
    if (done[f] === h) { skipped++; continue; }
    const ok = await upload(f);
    console.log(` ${ok ? 'ok' : 'FAILED'}: ${f}`);
    if (ok) {
      done[f] = h;
      cache[cacheKey] = done;
      writeFileSync(CACHE_FILE, JSON.stringify(cache));
    } else failures.push(f);
  }
  if (skipped) console.log(` (${skipped} unchanged file(s) skipped)`);
  if (failures.length) {
    console.log(`Done with ${failures.length} failure(s): ${failures.join(', ')}`);
    process.exitCode = 1;
  } else {
    console.log('Done. Verify: https://clipnow.vip/ and https://clipnow.vip/admin/');
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
