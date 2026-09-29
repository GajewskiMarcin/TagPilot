# TagPilot — GTM & GA4 Ecommerce for PrestaShop

**TagPilot** is a free, open-source PrestaShop module that gives you a production-grade Google Tag Manager + Google Analytics 4 setup in minutes — including Enhanced Ecommerce, server-side Measurement Protocol, Consent Mode v2, and Enhanced Conversions.

Built for PrestaShop 8.0 – 9.x. Zero coding required for standard installations.

---

## Highlights

- **One-click GTM auto-configuration** — connect your Google account, pick a container, and TagPilot creates the full set of Data Layer variables, custom event triggers, and GA4 event tags for you via the Tag Manager API v2.
- **Full GA4 Enhanced Ecommerce** — 15 events out of the box: `page_view`, `view_item`, `view_item_list`, `select_item`, `add_to_cart`, `remove_from_cart`, `view_cart`, `begin_checkout`, `add_shipping_info`, `add_payment_info`, `purchase`, `refund`, `login`, `sign_up`, `search`.
- **Server-side purchase tracking** via GA4 Measurement Protocol — with strict Consent Mode enforcement (never sends without a real gtag session) and session stitching (real `client_id` + `session_id` from browser cookies, no fabrication).
- **Enhanced Conversions** — user data (email, phone in E.164, first / last name, postal code, country) attached to `purchase` for higher Google Ads match rate. Values are pushed raw; gtag hashes them client-side per Google's spec.
- **Consent Mode v2 ready** — designed to interoperate with any CMP that emits `gtag('consent', 'default' | 'update', {...})`. Works out of the box with the sister module [ConsentFlow](https://github.com/GajewskiMarcin/ConsentFlow) but does not require it.
- **DataLayer + Order logs** — every event stored in the database with an inspector UI. Diagnose live traffic without touching Tag Assistant.
- **Symfony admin, PSR-4 autoloading, PS 8 / 9 compatible** — modern architecture, not a legacy `ModuleAdminController` port.

---

## Requirements

- PrestaShop **8.0 – 9.x**
- PHP **8.1+**
- A **GA4 property** (Measurement ID `G-XXXXXXXXXX`) — free, [create one here](https://analytics.google.com)
- Optional (for auto-config): a **Google Tag Manager container** and a Google account with edit access to it
- Optional (for server-side purchase): a **GA4 Measurement Protocol API secret** — created in GA4 → Admin → Data streams → your stream → Measurement Protocol API secrets

---

## Installation

### From the release zip (recommended)

1. Download the `tagpilot-vX.Y.Z.zip` asset from the [latest release](https://github.com/GajewskiMarcin/TagPilot/releases) — it is ready to install, no Composer step needed
2. In PrestaShop Back Office: **Modules → Module Catalog → Upload a module**
3. Open **TagPilot** in the admin sidebar

> Do **not** use GitHub's auto-generated "Source code (zip)" link: it is a plain
> snapshot of the repository and the back office will reject the folder name.

### From a git clone

1. `git clone` this repo into `<your-shop>/modules/tagpilot/` (the folder **must** be named `tagpilot`)
2. Optional: run `composer install --no-dev --optimize-autoloader` inside `modules/tagpilot/` for Composer's optimized classmap. The module ships a dependency-free fallback autoloader at `vendor/autoload.php`, so it also runs fine without this step
3. Install from **Modules → Module Manager**
4. Open **TagPilot** in the admin sidebar

### GTM container: import instead of OAuth

The module can auto-configure your GTM container over the Tag Manager API, which needs a
Google OAuth connection. If you would rather not grant that access — or your GTM account is
managed by someone else — `gtm/tagpilot-container.json` is a container export that creates the
same setup by hand: 18 variables, 15 triggers and 16 tags, with no shop-specific data.

Import it with **Merge → Rename conflicting**, never Overwrite, then set your Measurement ID in
the one constant variable. Full instructions in [`gtm/README.md`](gtm/README.md).

### From PrestaShop Marketplace

Not yet — the module currently lives here on GitHub only.

---

## First-time configuration

TagPilot ships with a 3-step wizard. Open the module in the admin and click **GTM Setup**:

1. **Connect** — sign in with your Google account (OAuth 2.0, `tagmanager.edit.containers` + `analytics.readonly` scopes)
2. **Choose** — pick your GTM account + container, and paste your GA4 Measurement ID (+ optional Google Ads Conversion ID / Label if you also want an Ads tag)
3. **Activate** — TagPilot creates in your GTM container:
   - 16 Data Layer variables (`ecommerce.*`, `user_data.*`, `pageCategory`, `user_id`, etc.)
   - 1 User-Provided Data variable (for Enhanced Conversions)
   - 15 Custom Event triggers
   - 1 Google Tag configuration
   - 15 GA4 event tags (page_view, purchase, etc.)
   - Optionally: Google Ads Conversion + Remarketing tags
   - Publishes the container automatically

**No manual GTM work required.** If you already have tags in your container, TagPilot creates its own alongside them — nothing gets overwritten.

### Configuring server-side purchase (optional but recommended)

Server-side Measurement Protocol acts as a fallback for adblock users — it fires from PHP directly to GA4, bypassing the browser. To enable:

1. In your GA4 property: **Admin → Data streams → your stream → Measurement Protocol API secrets → Create**
2. Copy the secret
3. In TagPilot: **Configuration → GA4 → paste the secret**
4. Save

TagPilot's server-side path is Consent-Mode-aware — it will only fire when the visitor's `flavor_cookie_consent` cookie (or your CMP's equivalent) confirms `analytics = true` AND real `_ga` / `_ga_<STREAM>` cookies exist. It never fabricates a `client_id`, never sends without a `session_id`. This guarantees your purchase events stitch to the visitor's real session and never pollute the "Unassigned" channel.

---

## Consent Mode v2 integration

TagPilot follows Google's Advanced Consent Mode contract:
- The Google Tag configuration fires on **All Pages** with `consentStatus: notNeeded` — meaning it initializes gtag regardless of consent, so cookieless pings can be sent for modeling.
- Individual GA4 event tags inherit consent from the Google Tag config.
- TagPilot itself does **NOT** emit `gtag('consent', 'default', ...)` — that's the CMP's job. Install a CMP (like [ConsentFlow](https://github.com/GajewskiMarcin/ConsentFlow) or any other) that emits the default state in `<head>` before GTM loads.

If you don't have a CMP: consent defaults to `granted` in gtag's fallback, which is not GDPR-compliant in the EU. **Install a CMP.**

---

## What TagPilot does NOT do

- **Facebook Pixel / Meta CAPI** — separate concern, use a dedicated module
- **TikTok / Pinterest / other ad networks** — same
- **Server-side GTM (sGTM)** container hosting — TagPilot uses browser-side GTM + direct MP calls; sGTM is a separate infra decision
- **Cookie banner / CMP UI** — that's what [ConsentFlow](https://github.com/GajewskiMarcin/ConsentFlow) is for

---

## Support & contributing

- **Bugs / feature requests** — [open an issue](https://github.com/GajewskiMarcin/TagPilot/issues)
- **Questions** — [start a discussion](https://github.com/GajewskiMarcin/TagPilot/discussions)
- **Pull requests** welcome — please describe what you're solving and reference an issue if there is one

### Like the module? ☕

TagPilot is completely free. If it saved you time or money, consider supporting further development:

**[☕ Buy me a coffee](https://buymeacoffee.com/marcingajewski)**

Every coffee funds bug fixes, PS9 compatibility work, and new features.

---

## License

Released under the **Academic Free License 3.0 (AFL-3.0)** — see [LICENSE](LICENSE) file.

You can use it commercially, modify it, redistribute it. Contributions must be under the same license.

---

## Credits

Built by [Marcin Gajewski](https://marcingajewski.pl) for the PrestaShop community.
