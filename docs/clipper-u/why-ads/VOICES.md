# Clipper U voices (use these for every Clipper U video)

All voices are Higgsfield **Seed Audio 1.0** (`seed_audio`) preset voices: `voice_type: "preset"` plus the `voice_id` below.

| Character | Voice | voice_id | Notes |
|---|---|---|---|
| **T-Bug** | **Benji** | `e6f9b893-51b1-51d3-afe9-9e0482cb7ac1` | Chosen by the owner on Nov 17, 2026 for all T-Bug videos going forward. Replaces the cloned voice used in the first ten ads. |
| Tavin Jr. | Kevin | `f1373f24-3b96-433f-9a68-e595810ef608` | Same as the first series. |
| Bridgette | Remy | `b9c5c5db-4eb7-468a-a0b7-d06423af0335` | Same as the first series. |
| Dean Townsel | Vesper | `c3204739-4084-41a3-9dc5-c805b307ec18` | Same as the first series. |
| Dre (gas station, Ad 2) | **Xavier** | `43173c95-3ec8-446a-a162-6504332c578b` | Picked by the owner on Nov 17, 2026 (audition #3). Delivered at 1.07x pace. |
| Narrator (explainers) | Evie | `7a6845a2-5865-5669-a0ca-8fc8d8e96528` | ClipNow how-to explainer videos. |

Mix: voices at -16 LUFS overall with the music bed underneath (see `ad01-source/build.py`).

## Series rules ("Why aren't you clipping now?")
- **T-Bug's closing line in every ad:** "What are you waiting for? Go clip now." with **GO CLIP NOW.** popping on screen (above his head, never over his face).
- Every ad ends on the ClipNow end card with **Start your 7-day free trial** and the small print (earnings not guaranteed; only clip content you may use).
- Audience: anyone with a phone or computer, not only students. Scenes can be anywhere in the town of Clip.

## Owner rules (Nov 17, 2026)
- **The owner picks the voice for every new character.** Before building an ad with a new character, record an audition of all the young male (or matching) voices reading that character's lines, saved as `<Character>-voice-<N>-<Voice>.mp3` in `<character>-voice-audition/`, and wait for the owner's pick.
- T-Bug's question to the other character is **"Why aren't you clipping?"** (no "now"). The only "now" in the ad is the closing "What are you waiting for? Go clip now."
