# Digital business cards

Live (uploaded straight to Bluehost `public_html`, not deployed from this repo):
- GLEA: https://georgialea.com/card/ → `public_html/card/`
- App Planet: https://georgialea.com/apps/card/ → `public_html/apps/card/`

`current/` is what's on the server now (2026-11-17): each card has a **Save my contact** button that opens
`contact.vcf` (name, phone, email, website, card link). `.htaccess` makes phones treat the .vcf as a contact.
`before-2026-11-17/` is each `index.html` as it was before that change.

Tap to share: write the card's address onto an NFC card or sticker (NTAG215/216) with the free NFC Tools app.
