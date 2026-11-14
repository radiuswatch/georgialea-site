# ClipNow app: to-do list (later project, not started)

Goes with `docs/clipnow-plan.md` section 14 (on branch `claude/video-clipping-platform-eghmf6`).
Mockup of the two options: https://clipnow.vip/preview/app-options.html

**Decided so far (2026-11-14)**
- Approach: **option 1, wrap the website (Capacitor)**. React Native can be looked at later.
- Owner has an **iPhone** and a **Windows PC, no Mac**. The iPhone app is built on GitHub's online Macs
  and installed through **TestFlight** (needs the $99/year Apple account).
- The website and the Home Screen version stay exactly as they are. The app is a separate way in.
- Never delete or overwrite server-side data (member data, `config.json`, logs).

## Owner's to-dos
- [ ] Decide: Apple account **as yourself** (fastest, your name shows as seller) or **as a business**
      (company name shows; needs a free D-U-N-S number, which can take 1–2 weeks).
- [ ] Sign up for the Apple Developer Program ($99/year) at developer.apple.com (works on a Windows PC).
- [ ] Install the **TestFlight** app on your iPhone.
- [ ] When Claude asks: make the App Store Connect API key and the push (APNs) key
      (Claude gives click-by-click steps).
- [ ] Later, for Android: Google Play developer account ($25 once) and a free Firebase account (for push).
- [ ] Later, before the App Store: app icon, screenshots, short description, privacy policy and
      support page on clipnow.vip.

## Claude's to-dos
### Step 1: test app on your iPhone (no store listing)
- [ ] Set up the Capacitor app project in its own new folder (e.g. `clipper-app/`), separate from the
      website, loading https://clipnow.vip.
- [ ] Set up the automatic iPhone build on GitHub (online Mac) that uploads each version to TestFlight.
- [ ] Check sign-in (email code + staying signed in) still works after the app is closed and reopened.
- [ ] Make picking a video from the phone, the Share button and links that open new windows work
      properly inside the app.
- [ ] Check offline caching (`sw.js`) behaves inside the app.
- [ ] Only for the app: hide the Stripe **Subscribe** button (members subscribe on the website, then
      sign in). Website keeps Stripe unchanged.

### Step 2: push alerts in the app
- [ ] Add native push in the app (Apple's APNs on iPhone, Google's FCM on Android).
- [ ] Add sending to app phones next to the existing Web Push (`cn_push*` in `admin/lib.php`,
      `api/push.php`). Website users keep Web Push. Only add new data; never remove existing subscriptions.

### Step 3: Android
- [ ] Automatic Android build on GitHub; a test download link on clipnow.vip (never a claude.ai link).

### Step 4: into the stores
- [ ] Final decision on subscriptions in the app: keep "subscribe on the website" or add Apple/Google
      in-app purchase (they take 15–30%).
- [ ] Add anything Apple asks for in review (e.g. delete-account option, privacy details).
- [ ] Submit to the App Store and Google Play.
- [ ] Add how app releases work to the plan's "Deploying" section. Website deploys stay the same one
      PowerShell line; a new app version is only needed when the app shell itself changes.
