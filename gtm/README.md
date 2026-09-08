# TagPilot — GTM container import

`tagpilot-container.json` is a Google Tag Manager **container export** that creates the same
GA4 setup as TagPilot's built-in OAuth auto-configurator, without connecting a Google account.

Use it if you would rather not grant the module `tagmanager.edit.containers` access, if your
GTM account is managed by someone else, or if you want to review every tag before it exists.

It contains **18 variables, 15 triggers and 16 tags**, and no shop-specific data — the only
thing you edit after import is one constant variable.

| | |
|---|---|
| 16 Data Layer Variables | `DLV - <field>`, dataLayer version 2 |
| 1 User-Provided Data variable | `TagPilot - User Provided Data` (Enhanced Conversions) |
| 1 Constant | `TagPilot - GA4 Measurement ID` ← **you edit this** |
| 15 Custom Event triggers | `CE - <event>`, one per TagPilot dataLayer event |
| 1 Google tag | `TagPilot - GA4 Configuration`, All Pages |
| 15 GA4 Event tags | `GA4 - <event>` |

---

## Import

1. In GTM, open **Admin → Container → Import Container** and upload
   `tagpilot-container.json`.
2. **Choose Workspace:** `Existing` → **Default Workspace**.
3. **Choose an import option:** **Merge**, and inside Merge pick
   **"Rename conflicting tags, triggers and variables"**.
   - **Do not use Overwrite.** Overwrite replaces the *entire* container and discards
     everything already in it.
   - Inside Merge, prefer **Rename** over "Overwrite conflicting". Anything that already
     exists under the same name is then created alongside it with a suffix, so *you* decide
     what to keep instead of the import silently replacing your work. On a blank container
     the two behave the same; on a container with existing tags the difference matters.
4. Review the **diff preview** GTM shows — it lists the counts of tags, triggers and
   variables that will be created, modified or deleted. Confirm those match the table above
   before clicking through.

## After import — required

5. **Set your Measurement ID.** Go to **Variables → `TagPilot - GA4 Measurement ID` → Edit**
   and replace `G-XXXXXXXXXX` with your real GA4 Measurement ID.

   This single constant is the reason the file carries no shop-specific data. All 16 GA4 tags
   reference `{{TagPilot - GA4 Measurement ID}}` rather than a hardcoded ID, so this is the
   only place the ID appears. (This is an improvement over the module's auto-configurator,
   which writes the ID into every tag.)

6. **Configure the module.** In PrestaShop: **TagPilot → Configuration**
   - **GTM Container ID** — your `GTM-XXXXXXX`
   - **GA4 Measurement ID** — the same `G-...` as above
   - **Measurement Protocol API Secret** — create it in
     GA4 **Admin → Data streams → your web stream → Measurement Protocol API secrets → Create**
   - Enable the module.

   The OAuth **GTM Setup** wizard is **not needed at all** when you import this file. Skip it.

7. **Verify, then publish.** Open GTM **Preview** and walk a real journey —
   product page → add to cart → cart → checkout → place order — checking that each event
   fires its matching tag. Then **Submit → Publish**.

---

## Notes

**One `page_view` per pageview.** The module pushes its own `page_view` dataLayer event and
this container has a `GA4 - page_view` tag for it, so the Google tag's *automatic* pageview
must stay off or every pageview is counted twice. The JSON already sets
`send_page_view = false` on `TagPilot - GA4 Configuration`. Confirm it survived the import:
if Preview shows two `page_view` hits, open **Tags → `TagPilot - GA4 Configuration` →
Configuration settings** and add `send_page_view` = `false` by hand.

**`GA4 - refund` will not fire in the browser.** The module builds refunds in back-office
hooks (order slip / status change to cancelled or refunded), where no customer cookies exist,
and sends them straight to GA4 via the Measurement Protocol — bypassing GTM entirely. The tag
is included for parity and for anyone who wants to push `refund` client-side.

**Five events come from JavaScript, not PHP.** `add_to_cart`, `remove_from_cart`,
`select_item`, `add_shipping_info` and `add_payment_info` are pushed by
`views/js/tagpilot-front.js`. If those are the *only* events missing in Preview, the front-end
JS is not loading — check for a theme or asset-bundling conflict rather than a GTM problem.

**Enhanced Conversions.** `TagPilot - User Provided Data` collects email, phone, first/last
name, postal code and country from the `DLV - user_data.*` variables and is attached to the
`purchase` tag as the `user_data` event parameter. The module pushes these values raw; gtag
hashes them client-side per Google's spec — the variable carries no hashing settings because
GTM does not need any. TagPilot collects six of the ten fields GTM supports; `street`, `city`
and `region` are intentionally absent because the module does not push them.

**Seven variables are intentionally unreferenced.** `DLV - ecommerce.transaction_id`,
`.value`, `.currency`, `.tax`, `.shipping`, `.items` and `DLV - user_id` are not used by any
tag in this file. The GA4 event tags read the whole `ecommerce` block automatically via
*Send Ecommerce Data → Data Layer*. They are shipped because they are what you need if you add
Google Ads tags or your own custom tags.

**Ecommerce data is deliberately off for four tags.** `page_view`, `login`, `sign_up` and
`search` have `sendEcommerceData` **false**, because those dataLayer pushes carry no
`ecommerce` block and GA4 logs *"Invalid Ecommerce event name"* if you send one anyway. Leave
them off.

---

## Not included: Google Ads

Google Ads tags are **deliberately excluded** — they need your own conversion ID and label,
which cannot be shipped in a shared file. If you want them, the module's
`GtmApiService::createAdsConversionTag()` and `createAdsRemarketingTag()` create exactly this,
and you can reproduce it by hand:

**`TagPilot - Google Ads Conversion`** — tag type *Google Ads Conversion Tracking* (`awct`),
firing on **`CE - purchase`**:

| Field | Value |
|---|---|
| Conversion ID | your `AW-...` ID |
| Conversion Label | your conversion label |
| Conversion Value | `{{DLV - ecommerce.value}}` |
| Currency Code | `{{DLV - ecommerce.currency}}` |
| Order ID | `{{DLV - ecommerce.transaction_id}}` |

**`TagPilot - Google Ads Remarketing`** — tag type *Google Ads Remarketing* (`sp`), firing on
**All Pages**, with the same Conversion ID.

All five variables those tags need are already in this container.

---

## Regenerating / editing

The file is the specification in `src/Service/GtmApiService.php` (`autoConfigureContainer()`
and the `create*` helpers) expressed in GTM's container-export format. If you change the
events the module pushes, update both.

Placeholder `accountId` / `containerId` / `publicId` values in the file are remapped by GTM on
import and can be ignored.
