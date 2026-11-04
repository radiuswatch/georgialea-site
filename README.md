# Georgia Lea — app-building service website

Public marketing website for Joseph's app-building business (working name "App Launch" —
final business name still to be decided; it appears only in `index.html` and is a
one-line swap).

- Single static page: `index.html`, no build step, no dependencies.
- Hosted on GitHub Pages.
- Contact links go to `info@georgialea.com`.
- Pricing shown: Starter $4,500 · Full build $9,500 · Care plan $400/mo.
- Case studies: Bird Cage and CourtWatch — keep their status lines accurate
  (update to "On the App Store" only once Apple approves them).

## ClipNow (`clipper/`, domain: clipnow.vip)

Subscription clipping product, deployed at `/apps/clipper/`. Static page, no build step:
a trending-video search (streamer name or YouTube link; set `CONFIG.youtubeKey` for in-page results), an in-browser studio (find highlights → 9:16 clips with hooks → per-platform captions),
daily plan, earnings estimator and pricing (Starter $19 · Pro $49 · Agency $149 per month).
Paste Stripe Payment Links into `CONFIG.stripe` at the top of the page script to take payments.
Product and build plan: `docs/clipnow-plan.md` (not deployed).
