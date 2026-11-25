# Read this first

This repo is **public**. Never commit keys, passwords, personal email addresses, customer data or server data.

## Start every session here
1. Read **MASTER.md** in the owner's Google Drive folder "Card Studio" (starred). It lists every project, where each one lives,
   all standing rules and the open to-do list. Then read **PLAYBOOK.md** in the same folder for the full history.
2. The owner works in one master session ("ClipNow deployment to clipnow.vip"). Do not archive "Card Studio – Phase 1 gate":
   it hosts the hourly Card Designer task.
3. After any change: commit it here (ClipNow) or add a PLAYBOOK section (App Planet USA, cards, GALEA), and save MASTER.md /
   PLAYBOOK.md back to Drive.

## Golden rules for live sites
1. **Sync before you edit.** Download the live file and compare it with this repo. If the server has changes the repo doesn't,
   three-way merge them in first. Never upload over work you haven't seen (this lost server-side work once, on 2026-11-25).
2. **ClipNow's source of truth is this repo** (`clipper/`). Deploy with `scripts/deploy-clipnow.ts`
   (`CPANEL_HOST=cpanel.clipnow.vip:443 CLIPNOW_DOCROOT=/home1/thrypjmy/public_html/website_05f463c5`). It uploads only changed
   files and never deletes server files.
   **Always pass `CLIPNOW_DOCROOT` on the command line:** this environment has a `CLIPNOW_DOCROOT` variable that points at the
   unused folder `public_html/clipnow.vip` (blocked from the web), so a deploy without it goes to the wrong place. App Planet USA, cards and GALEA are NOT in this repo (see MASTER.md).
3. Back up before uploading (outside public_html), and test locally first (`CLIPNOW_HOME`, `CLIPNOW_STRIPE_BASE`,
   `CLIPNOW_MAIL_FILE` let the admin run against a fake Stripe and write emails to a file).
4. Never delete or overwrite server data (`/home1/thrypjmy/clipnow-data/`, member data, logs, analytics).
5. Bump `VERSION` in `clipper/sw.js` when pages change so phones load the new version.

## ClipNow ads
Rules and voices: `docs/clipper-u/why-ads/VOICES.md` and `TODO.md`. Every finished ad also goes into the affiliate library
(`clipper/promo/` + `clipper/promo/videos.json`).

## Commits
End commit messages with the attribution lines the session gives; no model names in commits or code.
