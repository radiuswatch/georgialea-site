# ClipNow: issue log

Problems seen on clipnow.vip, what was found, and what's still open. Newest first.

## 2026-11-15 · Studio black below the videos (iPhone Safari only)

**Update (same day): it came back right after the Stats/Campaigns update, so the fix went live**
(commit `fb8c061` on the ClipNow branch), together with error reporting (`92dc2be`): browsers now
send page errors to `~/clipnow-data/client-errors.jsonl`. If the Studio misbehaves again, read that
file (signed_in, ua and msg show what broke on which phone). It went black twice, both times right
after a deploy, which points at the phone loading a mix of the old and new page, or at code that
only runs when signed in on iPhone.

**What the owner saw:** on the iPhone, in Safari, clipnow.vip/studio showed the two how-to videos and then
only black below them. It looked fine on the desktop. Later the same day it was "back up" (working again)
without any change being deployed.

**What was checked:**
- Could not reproduce it in a test browser set up to act like an iPhone (signed out, Safari and Home
  Screen modes, with the preview pass). Every section showed and there were no page errors.
- The live page matched the code on branch `claude/video-clipping-platform-eghmf6`.
- The two `409` answers from `/api/clips.php` and `/api/site-info.php` are Bluehost's "humans" check,
  which the page already retries. Not the cause.

**Most likely cause:** below the videos, the Studio box and everything after it start invisible
(`.reveal`, opacity 0) and fade in when scrolled into view. The fade-in code
(IntersectionObserver near the end of the main script in `clipper/index.html`) runs only after all the
other page code. If anything before it fails on that phone, or the observer doesn't fire in Safari, those
sections stay invisible, which looks black on the dark background. The videos don't fade in, which is why
they still showed. It cleared up on its own, so it may have been a one-off (an old saved copy of the page,
or a slow or failed load).

**Fix prepared, not deployed:** `docs/clipnow-patches/2026-11-15-studio-black-below-videos.patch`
(made against the ClipNow branch at `c041b31`).
- The fade-in now starts in `<head>`, before any other page code. It shows any section that is on screen
  or scrolled past (checked on load, scroll, resize, tap), so nothing can stay blank.
- The same change is made on `clipper/coming-soon.html`, which uses the same fade-in.
- Tested: every section shows on the Studio, home and coming-soon pages, including with the page's other
  code deliberately broken. Lower sections still fade in as you scroll. Not tested in real iPhone Safari.

**If it happens again:**
1. Ask: Safari or Home Screen icon? Signed in? A screenshot? Does pull-to-refresh fix it?
2. Apply the patch on the ClipNow branch (`git am` the patch file), then deploy with the usual one-line
   PowerShell command (see "Deploying" in `docs/clipnow-plan.md`).
3. Optional: apply it anyway as a safety net, since it changes nothing visible when things work.
