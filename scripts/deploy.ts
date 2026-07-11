/**
 * Publishes this site to Bluehost (georgialea.com hosting) via the cPanel API.
 * Credentials: C:/Users/memph/.bluehost-cpanel.env (never in this repo).
 * Run: npx tsx scripts/deploy.ts
 *
 * Why the API and not FTP: the assistant's execution environment can only
 * reach the web (443/2083); FTP/SSH ports are blocked. Never pass paths like
 * /home1/... through Git Bash args — MSYS mangles leading-slash paths; this
 * script keeps all paths inside Node where they're safe.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';

const env = readFileSync('C:/Users/memph/.bluehost-cpanel.env', 'utf-8');
const get = (k: string) => env.match(new RegExp(`${k}=(.*)`))![1].trim();
const HOST = `https://${get('CPANEL_HOST')}:2083`;
const AUTH = { Authorization: `cpanel ${get('CPANEL_USER')}:${get('CPANEL_TOKEN')}` };
// The domain root belongs to the GALEA page (georgialea.com is Georgia Law
// Enforcement Associates' home) — this app-business site lives under /apps.
const REMOTE_ROOT = '/home1/thrypjmy/public_html/apps';
const LOCAL_ROOT = path.resolve(__dirname, '..');
const SKIP = new Set(['.git', 'scripts', 'README.md', 'node_modules']);

async function api2(module: string, func: string, params: Record<string, string>) {
  const q = new URLSearchParams({
    cpanel_jsonapi_apiversion: '2',
    cpanel_jsonapi_module: module,
    cpanel_jsonapi_func: func,
    ...params,
  });
  const res = await fetch(`${HOST}/json-api/cpanel?${q}`, { headers: AUTH });
  return res.json() as Promise<any>;
}

function walk(dir: string): string[] {
  const out: string[] = [];
  for (const name of readdirSync(dir)) {
    if (SKIP.has(name)) continue;
    const full = path.join(dir, name);
    if (statSync(full).isDirectory()) out.push(...walk(full));
    else out.push(full);
  }
  return out;
}

async function main() {
  const files = walk(LOCAL_ROOT);
  console.log(`publishing ${files.length} files to ${REMOTE_ROOT} ...`);
  const failures: string[] = [];
  const madeDirs = new Set<string>();
  for (const file of files) {
    const rel = path.relative(LOCAL_ROOT, file).replaceAll('\\', '/');
    const remoteDir = path.posix.join(REMOTE_ROOT, path.posix.dirname(rel));
    if (remoteDir !== REMOTE_ROOT && !madeDirs.has(remoteDir)) {
      await api2('Fileman', 'mkdir', {
        path: path.posix.dirname(remoteDir),
        name: path.posix.basename(remoteDir),
      });
      madeDirs.add(remoteDir);
    }
    let ok = false;
    for (let attempt = 1; attempt <= 3 && !ok; attempt++) {
      try {
        const form = new FormData();
        form.append('dir', remoteDir);
        form.append('file-1', new Blob([readFileSync(file)]), path.posix.basename(rel));
        const res = await fetch(`${HOST}/execute/Fileman/upload_files`, {
          method: 'POST',
          headers: AUTH,
          body: form,
        });
        const j: any = await res.json();
        ok = j.data?.succeeded === 1;
      } catch {
        // transient network reset — brief pause, then retry
        await new Promise((r) => setTimeout(r, 3000 * attempt));
      }
    }
    console.log(` ${ok ? 'ok' : 'FAILED'}: ${rel}`);
    if (!ok) failures.push(rel);
  }
  if (failures.length > 0) {
    console.log(`Done with ${failures.length} failure(s): ${failures.join(', ')}`);
    console.log('(Large files can exceed the upload limit — if the file is unchanged on the server, this is harmless.)');
    process.exitCode = 1;
  } else {
    console.log('Done. Verify: https://georgialea.com/apps/');
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
