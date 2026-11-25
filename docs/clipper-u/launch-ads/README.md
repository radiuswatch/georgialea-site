# "ClipNow is live" launch ads (Nov 25, 2026)

Six 30-second vertical ads announcing that ClipNow is live, two per tagline. All are also in the affiliate Promo videos library (`clipper/promo/`).

| File | Style | Voice | Tagline |
|---|---|---|---|
| `clipnow-live-ad01.mp4` | Animated (Clipper U cast), campus launch party | T-Bug (Benji, from the owner's audition clip) | Clip Your Heart Away |
| `clipnow-live-ad02.mp4` | Animated, clock tower | T-Bug | The Time to Clip Is Now |
| `clipnow-live-ad03.mp4` | Animated, Dean Townsel tries it | T-Bug | Clipping Made Easy. Even You Can Do It. |
| `clipnow-live-ad04.mp4` | Real people: couch, lunch break, barber, sidewalk | Evie (narrator) | Clip Your Heart Away |
| `clipnow-live-ad05.mp4` | Real people: bus stop, nurse's break, delivery driver, rooftop | Evie | The Time to Clip Is Now |
| `clipnow-live-ad06.mp4` | Real people: grandpa learns from his granddaughter | Evie | Clipping Made Easy. Even You Can Do It. |

- Animated sources: `animated-source/` (`lbuild.py` builds one ad: `python3 lbuild.py <folder> docs/clipper-u/ad1-source <ad11 folder> a|b|c`).
- Real-people sources: `real-source/` (stills, narration and `rbuild.py`). The 5-second video shots were made with Seedance 2.5 (1080p) from the stills and aren't stored here; the finished ads are.
- Real-people rule: people on screen don't speak, Evie narrates (see `../why-ads/VOICES.md`).
