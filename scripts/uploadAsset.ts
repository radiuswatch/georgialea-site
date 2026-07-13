// Uploads one asset file to the live /apps/assets folder (multipart, with
// retries — see deploy.ts for the full-site version).
// Run: npx tsx scripts/uploadAsset.ts <filename-in-assets>
import { readFileSync } from 'node:fs';

const env = readFileSync('C:/Users/memph/.bluehost-cpanel.env', 'utf-8');
const g = (k: string) => env.match(new RegExp(`${k}=(.*)`))![1].trim();
const HOST = `https://${g('CPANEL_HOST')}:2083`;
const AUTH = { Authorization: `cpanel ${g('CPANEL_USER')}:${g('CPANEL_TOKEN')}` };
const DIR = '/home1/thrypjmy/public_html/apps/assets';

async function main() {
  const file = process.argv[2];
  if (!file) throw new Error('usage: uploadAsset.ts <filename>');
  const bytes = readFileSync(`C:/Users/memph/Desktop/georgialea-site/assets/${file}`);
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const form = new FormData();
      form.append('dir', DIR);
      form.append('file-1', new Blob([bytes]), file);
      const res = await fetch(`${HOST}/execute/Fileman/upload_files`, { method: 'POST', headers: AUTH, body: form });
      const j: any = await res.json();
      if (j.data?.succeeded === 1) {
        console.log(`${file}: uploaded (${bytes.length} bytes)`);
        return;
      }
      console.log(`attempt ${attempt}:`, JSON.stringify(j.errors ?? j).slice(0, 120));
    } catch (e) {
      console.log(`attempt ${attempt}: ${e instanceof Error ? e.message : e}`);
    }
    await new Promise((r) => setTimeout(r, 4000 * attempt));
  }
  throw new Error('upload failed after 3 attempts');
}

main().catch((e) => { console.error(e.message); process.exit(1); });
