// Deploys index.html alone via save_file_content (reliable path — the
// multipart upload endpoint gets throttled; see deploy.ts for full deploys).
import { readFileSync } from 'node:fs';

const env = readFileSync('C:/Users/memph/.bluehost-cpanel.env', 'utf-8');
const g = (k: string) => env.match(new RegExp(`${k}=(.*)`))![1].trim();
const HOST = `https://${g('CPANEL_HOST')}:2083`;
const AUTH = { Authorization: `cpanel ${g('CPANEL_USER')}:${g('CPANEL_TOKEN')}` };
const DIR = '/home1/thrypjmy/public_html/apps';

async function main() {
  const content = readFileSync('C:/Users/memph/Desktop/georgialea-site/index.html', 'utf-8');
  const body = new URLSearchParams({ dir: DIR, file: 'index.html', content, from_charset: 'UTF-8', to_charset: 'UTF-8' });
  const res = await fetch(`${HOST}/execute/Fileman/save_file_content`, {
    method: 'POST',
    headers: { ...AUTH, 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });
  const j: any = await res.json();
  const chk = await fetch(`${HOST}/execute/Fileman/get_file_content?dir=${encodeURIComponent(DIR)}&file=index.html`, { headers: AUTH });
  const disk = ((await chk.json()) as any).data?.content ?? '';
  console.log('save:', j.status === 1 ? 'ok' : 'FAILED', '| bytes:', `${disk.length}/${content.length}`, '| menu live:', disk.includes('Pick a proven recipe'));
}

main().catch((e) => { console.error(e); process.exit(1); });
