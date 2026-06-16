# CAG Deal Feed — XenForo 2.2 add-on

Embeds the curated, multi-platform, engagement-ranked deal feed from
`deals.cheapassgamer.com` as a XenForo widget. Same filter set as the `/v2`
page (Date / Type / Platform / Store). Cron-driven server-side cache so page
loads are never blocked by HTTP to the worker.

- **Vendor / AddOn ID:** `CAG/DealFeed`
- **Target XF version:** 2.2.0+
- **PHP:** 8.1+
- **Data source:** `https://deals.cheapassgamer.com/api/deals` (CORS-enabled)
- **No DB schema** — read-only consumer of an external API; results cached in
  XF's cache backend.

## Install

This repo's root **is** the add-on directory — i.e. its contents go directly
to `src/addons/CAG/DealFeed/` inside your XenForo install.

### Recommended: clone + symlink (fast iteration)

```bash
# One-time setup on the XF server
git clone https://github.com/cheapyd-sys/dealfeed.git /opt/cag-dealfeed
mkdir -p /path/to/xenforo/src/addons/CAG
ln -s /opt/cag-dealfeed /path/to/xenforo/src/addons/CAG/DealFeed
```

Then in XF admin: **Add-ons → Install/upgrade → CAG/DealFeed → Install**.

For each update: `cd /opt/cag-dealfeed && git pull`, then in XF admin
**Add-ons → CAG/DealFeed → Upgrade**.

### Alternative: rsync from the clone

If `open_basedir` or other restrictions block symlinks pointing outside the
docroot:
```bash
rsync -a --delete --exclude=.git /opt/cag-dealfeed/ /path/to/xenforo/src/addons/CAG/DealFeed/
```

### Wiring up the widget

1. Confirm the cron entry **CAG Deal Feed: Refresh Cache** is enabled and
   scheduled to run every 5 minutes (Setup → Options → Cron entries).
2. Drop the widget on a page or widget position:
   - **Admin → Setup → Widgets → Add widget → CAG Deal Feed**, OR
   - Embed inline in any template via:
     ```xml
     <xf:widget key="cag_deal_feed_preview" definition="cag_deal_feed" />
     ```

## Test page (per the integration plan)

Create a hidden test page so you can iterate without touching the homepage:

1. **Admin → Setup → Pages → Add page**
2. URL portion: anything unguessable (e.g. `dealfeed-preview-x9k2p`)
3. Template content:
   ```xml
   <xf:widget key="cag_deal_feed_test" definition="cag_deal_feed" />
   ```
4. Save. Visit `https://www.cheapassgamer.com/pages/dealfeed-preview-x9k2p/`.

## How it works

```
                ┌────────────────────────────────────────┐
                │  XF cron entry — every 5 min           │
                │  Cron\DealFeed::refreshCache()         │
                └────────────────┬───────────────────────┘
                                 │ HTTP (2s connect, 5s total)
                                 ▼
                ┌────────────────────────────────────────┐
                │  worker: GET /api/deals?hours=48       │
                └────────────────┬───────────────────────┘
                                 │ JSON
                                 ▼
                ┌────────────────────────────────────────┐
                │  \XF::app()->cache()                   │
                │  key: cag_deal_feed_48h                │
                │  TTL: 900s (15-min hard ceiling)       │
                └────────────────┬───────────────────────┘
                                 │ (no HTTP)
                                 ▼
   ┌─────────────────────────────────────────────────────┐
   │  Widget\DealFeed::render() — every page load        │
   │   → reads cache only                                │
   │   → server-renders cards into HTML (SEO + no-JS)    │
   │   → embeds <script type="application/json"> with    │
   │     the same dataset for client-side filtering      │
   └─────────────────────────────┬───────────────────────┘
                                 │
                                 ▼ (in browser)
   ┌─────────────────────────────────────────────────────┐
   │  Inline JS — ports v2's filter logic:               │
   │   • Type / Platform / Store → client-side filter    │
   │     over the loaded array (no HTTP)                 │
   │   • Date (24h / 48h / 7d / 30d) → fetch() direct to │
   │     deals.cheapassgamer.com/api/deals (CORS-OK)     │
   └─────────────────────────────────────────────────────┘
```

## Failure modes

| What happens | Result |
|---|---|
| Worker is down when cron fires | Cache keeps the prior dataset (cron treats empty response as "do nothing"). Page renders the last good data. |
| Cache backend missing / disabled | Widget falls back to a live HTTP fetch with a 5-second cap. Best-effort. |
| Cache empty AND worker unreachable | Widget renders nothing (the `<xf:if is="$deals">` wrapper hides the entire block; XF widget position collapses silently). |
| User clicks Date filter and worker is unreachable | Grid shows "Failed to load deals." — the page itself isn't affected. |

## Files

```
addon.json                                  — manifest
Setup.php                                   — install/upgrade/uninstall (no-op)
Service/DealApiClient.php                   — Guzzle wrapper around /api/deals
Cron/DealFeed.php                           — refreshes cache every 5 min
Widget/DealFeed.php                         — reads cache, hands to template
_data/widget_definitions.xml                — registers the widget
_data/cron_entries.xml                      — registers the cron
_data/templates/public/widget_deal_feed.html         — markup + CSS + filter JS
_data/templates/admin/widget_deal_feed_options.html  — widget option form
```

## Visual style

CSS is inlined in the widget template and scoped to `.cag-df-*` so it cannot
leak into surrounding XenForo styles. The palette deliberately mirrors the
`/v2` page (dark bg, green primary, pill buttons) so the widget reads as a
distinct "deal block" — not as native XF chrome. To restyle for a more native
look, edit `_data/templates/public/widget_deal_feed.html` directly; the LESS
compiler is not required.

## Future hooks

- Click attribution: add a `/c/home/<dealId>` route on the worker so homepage
  clicks are attributed separately from newsletter/v2 clicks in
  `newsletter_clicks`. The widget already passes `deal_link` through unchanged
  — swap to the tracking redirect when ready.
- Date filter could pre-warm additional windows (24h, 7d, 30d) via the cron if
  on-click latency proves noticeable in practice.
