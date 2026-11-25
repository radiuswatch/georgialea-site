# Clipper U voices (use these for every Clipper U video)

All voices are Higgsfield **Seed Audio 1.0** (`seed_audio`) preset voices: `voice_type: "preset"` plus the `voice_id` below.

| Character | Voice | voice_id | Notes |
|---|---|---|---|
| **T-Bug** | **Benji** | `e6f9b893-51b1-51d3-afe9-9e0482cb7ac1` | Chosen by the owner on Nov 17, 2026 for all T-Bug videos going forward. Replaces the cloned voice used in the first ten ads. |
| Tavin Jr. | Kevin | `f1373f24-3b96-433f-9a68-e595810ef608` | Same as the first series. |
| Bridgette | Remy | `b9c5c5db-4eb7-468a-a0b7-d06423af0335` | Same as the first series. |
| Dean Townsel | Vesper | `c3204739-4084-41a3-9dc5-c805b307ec18` | Same as the first series. |
| Dre (gas station, Ad 2) | **Xavier** | `43173c95-3ec8-446a-a162-6504332c578b` | Picked by the owner on Nov 17, 2026 (audition #3). Lines are recorded with `dre-voice-audition/Dre-voice-3-Xavier.mp3` as the voice reference (Higgsfield media c6cefe79-0220-4223-8456-26f1b20d315c), not the bare preset, because preset takes drift from the audition sound. |
| Narrator (explainers) | Evie | `7a6845a2-5865-5669-a0ca-8fc8d8e96528` | ClipNow how-to explainer videos. |

Mix: voices at -16 LUFS overall with the music bed underneath (see `ad01-source/build.py`).

## Series rules ("Why aren't you clipping now?")
- **T-Bug's closing line in every ad:** "What are you waiting for? Go clip now." with **GO CLIP NOW.** popping on screen (above his head, never over his face).
- Every ad ends on the ClipNow end card with **Start your 7-day free trial** and the small print (earnings not guaranteed; only clip content you may use).
- **Link in bio (Nov 25, 2026):** the end card also shows the yellow "Tap the link in bio" banner at the top, and the closing voice says "Link in bio. clipnow dot V-I-P." (T-Bug in cartoon ads, Evie in real-people ads). TikTok captions can't be tapped, so viewers need to be told where the link is.
- Audience: anyone with a phone or computer, not only students. Scenes can be anywhere in the town of Clip.

## Owner rules (Nov 17, 2026)
- **The owner picks the voice for every new character.** Before building an ad with a new character, record an audition of all the young male (or matching) voices reading that character's lines, saved as `<Character>-voice-<N>-<Voice>.mp3` in `<character>-voice-audition/`, and wait for the owner's pick.
- T-Bug's question to the other character is **"Why aren't you clipping?"** (no "now"). The only "now" in the ad is the closing "What are you waiting for? Go clip now."

## Real-people ads (owner rule, Nov 25, 2026)
For every ad made with real-looking people (not the Clipper U cartoon cast):
- **The people on screen don't speak.** They act out the story (scrolling, tapping, reacting) with no lip-synced lines.
- **Evie narrates.** All voiceover is the narrator voice Evie (`7a6845a2-5865-5669-a0ca-8fc8d8e96528`), the owner's pick for narration, so no new voice audition is needed. Check every take for repeated or jumbled words before building.
- **How they're made:** a photorealistic still for each shot, animated into a 5-second shot with Seedance 2.5 at 1080p, 9:16, no generated audio (about 80 credits per shot). Each still keeps the top of the frame plain for captions.
- **Same ending as every ad:** the tagline pops, then the CN keychain zooms into the end card with "Start your 7-day free trial" and the small print.
- If a real-people ad ever needs someone to speak on camera, treat that person as a new character: send the owner a voice audition first.
