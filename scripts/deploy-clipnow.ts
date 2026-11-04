/**
 * Publishes ClipNow (the clipper/ folder) to its own domain, clipnow.vip, on the same
 * Bluehost account as the main site, via the cPanel API (same credentials as deploy.ts).
 *
 * One-time setup in Bluehost: add clipnow.vip as an additional domain, then copy its
 * document root from cPanel → Domains and put it in the env file as
 *   CLIPNOW_DOCROOT=/home1/thrypjmy/public_html/clipnow.vip
 * (that path is the usual default; use whatever cPanel shows).
 *
 * Cloud sessions: set CPANEL_HOST, CPANEL_USER, CPANEL_TOKEN and CLIPNOW_DOCROOT as
 * environment variables, and allow the cPanel host and clipnow.vip in network access.
 *
 * Run: npx tsx scripts/deploy-clipnow.ts
 */
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';

// Settings come from environment variables (cloud sessions) or, on Joseph's PC, the env file.
const ENV_FILE = 'C:/Users/memph/.bluehost-cpanel.env';
const fileEnv = existsSync(ENV_FILE) ? readFileSync(ENV_FILE, 'utf-8') : '';
const get = (k: string) => process.env[k]?.trim() || fileEnv.match(new RegExp(`${k}=(.*)`))?.[1].trim();
for (const k of ['CPANEL_HOST', 'CPANEL_USER', 'CPANEL_TOKEN']) {
  if (!get(k)) throw new Error(`Missing ${k}: set it as an environment variable or in ${ENV_FILE}`);
}
const HOST = `https://${get('CPANEL_HOST')}:2083`;
const AUTH = { Authorization: `cpanel ${get('CPANEL_USER')}:${get('CPANEL_TOKEN')}` };
const REMOTE_ROOT = get('CLIPNOW_DOCROOT') || '/home1/thrypjmy/public_html/clipnow.vip';
const LOCAL_ROOT = path.resolve(__dirname, '..', 'clipper');

async function upload(file: string): Promise<boolean> {
  const name = path.basename(file);
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const form = new FormData();
      form.append('dir', REMOTE_ROOT);
      form.append('overwrite', '1');
      form.append('file-1', new Blob([readFileSync(file)]), name);
      const res = await fetch(`${HOST}/execute/Fileman/upload_files`, { method: 'POST', headers: AUTH, body: form });
      const j: any = await res.json();
      if (j.data?.succeeded === 1) return true;
    } catch {
      // transient connection reset; wait and retry
    }
    await new Promise((r) => setTimeout(r, 3000 * attempt));
  }
  return false;
}

async function main() {
  // clipper/ is flat: index.html, manifest, icons, .htaccess
  const files = readdirSync(LOCAL_ROOT)
    .map((n) => path.join(LOCAL_ROOT, n))
    .filter((f) => statSync(f).isFile() && !path.basename(f).startsWith('_'));
  console.log(`publishing ${files.length} files to ${REMOTE_ROOT} ...`);
  const failures: string[] = [];
  for (const f of files) {
    const ok = await upload(f);
    console.log(` ${ok ? 'ok' : 'FAILED'}: ${path.basename(f)}`);
    if (!ok) failures.push(path.basename(f));
  }
  if (failures.length) {
    console.log(`Done with ${failures.length} failure(s): ${failures.join(', ')}`);
    process.exitCode = 1;
  } else {
    console.log('Done. Verify: https://clipnow.vip/');
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
