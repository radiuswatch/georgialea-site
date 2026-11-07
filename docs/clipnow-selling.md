# Selling ClipNow: notes for later

Saved Nov 7, 2026. Not legal advice; use a lawyer for the sale contract.

## What a buyer gets

**Code and content** (repo `radiuswatch/georgialea-site`)
- `clipper/`: the whole app (site, Studio, Campaigns, Stats, admin portal, PHP API, legal pages,
  brand assets, the three explainer videos).
- `scripts/deploy-clipnow.ts` and `docs/clipnow-plan.md`.
- The repo also holds georgialea.com, so ClipNow should first move into its own repo.

**Accounts and services**

| Service | Role | How it moves |
|---|---|---|
| clipnow.vip domain | address | transfer at the registrar |
| Bluehost | hosting | buyer usually moves the PHP files to their own host |
| Stripe | subscriptions | accounts can't change owner; Stripe support can copy customers and saved cards to the buyer's account, then subscriptions are recreated |
| Upload-Post | auto-posting and view stats | transfer the account, or buyer uses their own (members reconnect socials) |
| Anthropic | AI Top 10 | buyer uses their own key |
| helpdesk@clipnow.vip | support email | goes with the domain/hosting |

**Business data:** the private `~/clipnow-data` folder on Bluehost (sessions, campaigns, stats,
post history, admin settings incl. API keys). Copied to the buyer's server; keys swapped for theirs.

**Brand and legal:** ClipNow name, logo, videos. The site says "by App Planet" and the Terms name
App Planet; member agreements pass to the new owner with the sale.

## Ways to package it

1. **Whole business** (most common): everything incl. paying members. Priced on revenue/profit.
   Marketplaces: Acquire.com, Flippa, or private sale with escrow.
2. **Code + brand, no members:** a starter kit the buyer launches. Simpler, sells for less.
3. **Licensing / white-label:** sell packaged copies (zip + setup guide) that run on the buyer's own
   Stripe, Upload-Post and Anthropic accounts; can be sold many times.

## Before selling

- Add a business-transfer clause to the Privacy Policy (it has none today) and notify members.
- Gather numbers: monthly revenue, paying members by plan, cancellations, monthly costs
  (Bluehost, Upload-Post, Anthropic, Higgsfield).
- Confirm Higgsfield's terms allow transferring the explainer videos and the Evie voiceovers.
- Lawyer for the sale contract (what's included, price, support period, IP assignment).

## Handover package Claude can prepare when ready

- Move ClipNow into its own repo.
- Handover guide: architecture, every account and key, deploying, the cron job, monthly costs.
- Transfer checklist.
- Business-transfer clause in the Privacy Policy.
- Brand-neutral version (no georgialea.com bits) if licensing.
