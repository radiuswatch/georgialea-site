# ClipNow: product & build plan

Internal planning doc. Not deployed (`docs/` is in the deploy script's SKIP list).
Live prototype: `clipper/index.html` → will live at `https://clipnow.vip/` (until then: `georgialea.com/apps/clipper/`).

## 1. The product in one sentence

Upload one long video, get the best moments as vertical clips with hooks and captions,
and post them to TikTok, YouTube Shorts, Instagram Reels, Facebook Reels, Snapchat
Spotlight and X in three steps, for a monthly subscription.

## 2. Who pays and why

| Customer | What they want | Plan |
|---|---|---|
| New clipper (side income) | A simple daily routine that turns campaign footage into paid views | Starter $19 |
| Daily clipper | Post more clips to more platforms in less time, track payouts | Pro $49 |
| Clipping team / agency | Many accounts, team seats, per-account payout reports | Agency $149 |
| Creators clipping their own content | Repurpose streams/podcasts without an editor | Starter or Pro |

**How clippers earn (we don't pay them, we make them faster):**
1. **Paid clipping campaigns.** Creators and brands pay per 1,000 views on clips of their content.
   Rates are commonly ~$0.50–$3 per 1K. Campaigns set rules (required tags, minimum views, caps).
2. **Platform creator programs.** These have follower, view and region requirements.
3. **Affiliate / referral links** in bio.

Never promise income on the site. The estimator is user-entered arithmetic with a disclaimer.
FTC rules on earnings claims apply to the marketing.

## 3. What exists today (prototype, no backend)

- Landing page, how-it-works, daily plan checklist (localStorage), earnings estimator, pricing, FAQ.
- **Working studio, 100% in the browser:**
  - Highlight finder: decodes the audio track and scores every 0.5 s for loudness, a strong
    opening (first 3 s), peaks and dead air. Picks the top N non-overlapping windows.
  - 9:16 render with fill-crop (drag to reframe) or fit-with-blurred-bars, hook text burned
    in, progress bar. No watermark.
  - Export via canvas + MediaRecorder in real time (MP4 on Chrome/Safari, WebM elsewhere).
  - Per-platform captions with character limits, campaign tags, length warnings, copy
    button and a link to each platform's upload page.
- Pricing buttons read Stripe Payment Links from `CONFIG.stripe` at the top of the script.
  Empty links fall back to an email to info@georgialea.com.

**To start charging today (no code):** create 3 recurring products in Stripe (monthly +
yearly prices, 7-day trial), make a Payment Link for each, paste the 6 URLs into `CONFIG.stripe`.
In each Payment Link, set "After payment" → redirect to
`https://clipnow.vip/?welcome=starter` (or `pro` / `agency`).

**Trial (client-side for now):** the studio's 7-day trial starts at the first export and is
tracked in the browser. After day 7 exports are locked until the `?welcome=` redirect marks
the device as a member. This is easy to get around (clear site data, another browser), so
real enforcement needs sign-in plus a Stripe webhook that checks subscription status.
That comes first in the weeks 1–3 build.

## 3b. Trending search (live on the page)

The hero search takes a streamer name, `@handle`, YouTube video/channel link, or a
Twitch/Kick link. Results are ranked by **views per hour since upload**, so new videos
taking off beat old ones with big totals. Pills show YouTube's most-popular chart by category.

- **Without a key** (today): it opens YouTube (sorted by views, uploaded today), Twitch
  (top clips, last 24 h) and Kick with the filters already applied. Pasted YouTube links
  show the video's thumbnail with Watch / Clip this.
- **With a key:** results show right on the page. Setup: Google Cloud console → new
  project → enable "YouTube Data API v3" → Credentials → API key → restrict to HTTP
  referrer `georgialea.com/*` and to that one API → paste into `CONFIG.youtubeKey`.
- **Quota:** the free 10,000 units/day covers about 50 streamer searches a day (a search costs
  ~101–201 units; category pills cost 1). Results are cached for 1 hour per browser and
  channel lookups are cached permanently. Before launch, move search to a small server
  that caches results for all users, and request a quota increase from Google.
- **Twitch in-page results** need a server: Twitch's API requires a client secret, which
  can't be put in a public page. Add a serverless function `/api/twitch-clips?login=` later.
- "Clip this" doesn't download from YouTube (that's against YouTube's terms). It shows the
  picked video in the studio and asks for the file: the campaign's download link or the
  user's own original.
- **Studio link box (live):** step 1 has "Have a YouTube link? Paste it here" (also catches
  Ctrl/Cmd+V anywhere on the step). It shows the video's title and thumbnail (title via the
  YouTube key if set, else noembed.com), fills the topic with the channel name, and guides the
  user to the file: their own video → YouTube Studio ⋮ → Download (allowed for owners);
  a campaign → the campaign's download link.
- **Full link import (not built, owner's decision pending):** paste a link and the video loads by
  itself, like Opus Clip. Needs a separate server running yt-dlp plus a proxy or paid download API
  (~$10–50/month; YouTube blocks most hosting IPs), breaks YouTube's terms, and puts copyright
  complaints on ClipNow. Get legal advice before building it.

## 4. Full platform architecture (paid tiers)

```
Browser (Next.js app)
  ├─ Auth (Clerk or Supabase Auth)
  ├─ Upload direct to storage (S3/R2 presigned, resumable via tus)
  └─ Dashboard: clips, schedule, connected accounts, payouts
API (Node/TypeScript)
  ├─ Stripe Billing + webhooks → plan limits
  ├─ OAuth connections per platform (tokens encrypted at rest)
  └─ Job queue (BullMQ on Redis)
Workers (GPU optional)
  ├─ ffmpeg: cut, 9:16 reframe, captions burn-in, loudness normalize
  ├─ Whisper (or a speech-to-text API): word-level transcript → animated captions
  ├─ Highlight model: transcript + audio energy + scene cuts + face tracking
  ├─ LLM (Claude API): hook text, titles, hashtags per platform, "why this clip" notes
  └─ Publisher: per-platform upload + status polling + retries
Postgres: users, plans, videos, clips, posts, campaigns, view snapshots
```

### Highlight scoring v2
Score = audio energy (have) + speech rate + laughter/applause detection + transcript
"quotability" (LLM rates each candidate segment as a standalone hook) + scene-change
density + face presence. Always cut on sentence boundaries from the transcript.

### Smart reframing
Face/body tracking (MediaPipe or similar) keeps the speaker centered in 9:16. Two speakers →
split-screen layout.

## 5. Posting to each platform: what it actually takes

Plan 3–8 weeks for API approvals. Ship "download + copy caption" first (the prototype already does it).

| Platform | API | Gate to pass |
|---|---|---|
| TikTok | Content Posting API (direct post) | App review + audit. Unaudited apps can only post privately. |
| YouTube Shorts | YouTube Data API v3 `videos.insert` | Google OAuth verification. Default quota is enough for ~6 uploads/day per project, so request a quota increase. |
| Instagram Reels | Instagram Graph API (content publishing) | Meta app review. User needs a Business/Creator account. Rate limits per account per 24 h. |
| Facebook Reels | Graph API Reels publishing on Pages | Meta app review. Posts to Pages, not personal profiles. |
| Snapchat Spotlight | Limited public API | Start with manual upload. Revisit if API access opens up. |
| X | X API v2 media upload + post | Paid API tier required for write access at volume. |

Rules to build in from day one: per-platform length/size/aspect checks, rate-limit-aware
scheduler, token refresh, clear "posted / failed / needs reconnect" states.

## 6. Pricing & unit economics

| | Starter | Pro | Agency |
|---|---|---|---|
| Monthly | $19 | $49 | $149 |
| Yearly | $190 | $490 | $1,490 |
| Clips / month | 60 | Unlimited (fair use ~600) | Unlimited |
| Platforms | 3 | 6 | 6 × 10 accounts |

Rough cost per clip (30 s, 1080p): transcription ~$0.003, LLM captions ~$0.005, render
compute ~$0.01, storage/egress ~$0.005 → **≈ $0.02–0.03**. A Pro user at 300 clips/month
costs ~$9. Margins stay healthy if fair-use limits apply and long uploads get transcoded once.

Growth levers: 7-day free trial on every plan (no free-forever tier for now), referral credit (1 free month per paid referral), and a campaign marketplace where
creators post paid campaigns and ClipNow takes a listing fee or a % of the campaign
budget (not of clipper earnings).

## 7. Legal & trust checklist

- Terms of Service: users must own or have permission for uploaded content. DMCA agent +
  takedown process.
- Earnings disclaimer on every page that mentions money.
- Privacy policy: what's stored, 30-day deletion of renders, OAuth token handling.
- Each platform's developer policies: no posting without user action/consent, no
  engagement manipulation, honest "posted via" disclosure where required.
- Stripe: clear cancellation (FTC click-to-cancel), trial reminder email before first charge.

## 8. Roadmap

1. **Week 0 (now):** prototype live, Stripe Payment Links wired, collect emails/trials.
2. **Weeks 1–3:** accounts + uploads + server-side ffmpeg render + Whisper captions + Stripe
   Billing portal. Apply for TikTok, Google and Meta API access on day 1.
3. **Weeks 4–6:** one-click posting to YouTube + Instagram/Facebook as approvals land;
   scheduler; LLM hooks/titles.
4. **Weeks 6–8:** TikTok direct post after the audit; view tracking (pull stats via each API);
   payout report per campaign.
5. **Later:** campaign marketplace, smart reframing, team seats, mobile app (App Planet
   can build the iOS client).

## 9. Explainer videos: house style

Every ClipNow explainer video uses the same narrator so they sound like one brand.

- **Voice:** Higgsfield preset voice **Evie** (`voice_id 7a6845a2-5865-5669-a0ca-8fc8d8e96528`,
  `voice_type preset`), model `seed_audio`, default speed/pitch, WAV 48 kHz.
- **Loudness:** normalise the final mix to about **-16 LUFS** (true peak -1.5 dB).
- **Timing:** one short narration line per scene; each scene lasts at least as long as its line,
  and the line starts as the scene's caption appears.
- **Look:** dark ClipNow theme, caption bar at the top with a green step number, green tap markers,
  end card with the CN mark.
- **Current videos:** `clipper/explainer.mp4` (how ClipNow works, 16:9, 67 s) and
  `clipper/schedule-explainer.mp4` (how to schedule a post, 9:16, 52 s, including batch scheduling) and
  `clipper/campaign-explainer.mp4` (how to create a campaign, 9:16, 60 s).

## 10. Campaigns (Platinum only, live)

`clipnow.vip/campaigns`. Only active **Platinum** members can create or join campaigns; everyone
else sees an explainer with Sign in / Upgrade buttons.

- **Creators** post a campaign: title, creator name, what to make, rules, a content link
  (Drive/Dropbox/channel), rate per 1,000 views, optional budget and end date, platforms,
  required tags, and how/when they pay. They review each post (link, reported views, screenshot),
  approve views (never more than reported, never past the budget), and record payments.
- **Clippers** join with a display name and payout handle (only the creator sees it), get the
  content link, use "Clip it in the Studio" (carries the required tags into the Studio), then submit
  each post's link, views and a screenshot. Updating views sends the post back for review; already
  approved views keep counting.
- **Money:** creators pay clippers directly. ClipNow never holds or sends money (Terms §7).
- **Admin:** Admin portal → Campaigns: see reports, hide/show, clear reports, delete (removes
  screenshots too). Events go to `~/clipnow-data/campaign-log.jsonl`.
- **Data:** `~/clipnow-data/campaigns/<id>.json`, screenshots in `~/clipnow-data/campaign-shots/`
  (private, served only to the creator and the clipper who sent them via `api/campaigns.php`).
- **Later:** automatic view tracking via platform APIs; payouts through Stripe Connect if wanted.

## 11. AI Top 10 trending campaigns (Gold & Platinum, live)

On the Campaigns page. Once a day (`admin/cron.php`, skips if it ran in the last 20 h) Claude
(`claude-opus-5-5`, effort medium, server-side fallbacks on) uses web search (up to 8 searches) to
find paid clipping campaigns open now on public pages, and returns a ranked Top 10 through a
strict `save_campaigns` tool. Saved to `~/clipnow-data/trending-campaigns.json`; served to Gold and
Platinum by `api/trending.php`. Silver/visitors see a blurred, locked preview.

- **Key:** Admin portal → Settings → Anthropic (stored in config.json, never in the repo).
- **Run now / status / cost per run:** Admin portal → Campaigns → "AI: Top 10 trending campaigns".
- **Cost:** web search $10 per 1,000 searches plus Opus 5.5 tokens ($4 / $20 per MTok); expect
  roughly $0.30–0.70 a day. Switching `CN_AI_MODEL` in `admin/lib.php` to `claude-sonnet-5-5`
  would roughly halve the token part.
- Every item links to its source page; the page warns that ClipNow can't confirm campaigns pay.

## 12. My stats (all paid plans, live)

`clipnow.vip/stats`: views on a member's clips and potential earnings per campaign.

- **Clips posted with ClipNow** (Post now / Schedule) are imported from the post log and update by
  themselves through Upload-Post `GET /api/uploadposts/post-analytics/{request_id}` (views, likes,
  comments per platform; Instagram uses `crossposted_views` when present, like the app).
- **Clips added by link:** YouTube, TikTok and X links on the member's connected accounts update
  through `GET /api/uploadposts/post-analytics?platform_post_id=&platform=&user=`; anything else
  (Instagram/Facebook links, other people's accounts) uses typed-in views.
- **Refresh:** each clip at most every 6 h, only when the member opens Stats, a few clips per call;
  all members share Upload-Post's 100 calls / 5 min, so ClipNow keeps under 80
  (`~/clipnow-data/up-analytics-rate.json`).
- **Campaigns:** tag a clip with a ClipNow campaign the member joined, or an outside one (name +
  pay per 1,000). Potential = views × rate, capped at the campaign budget; ClipNow campaigns also
  show approved / paid / owed. Data in `~/clipnow-data/stats/<customer>.json`.
- Later: let a campaign submission pull its views from Stats instead of typing them.
