<?php

namespace CAG\DealFeed\Widget;

use XF\Widget\AbstractWidget;

/**
 * Reads the cached deal feed and hands it to the public template.
 *
 * Falls back to a live fetch ONLY if the cache is empty or expired (first
 * page load after install, before the 5-min cron has run). On subsequent
 * loads this is a single cache read — no HTTP.
 *
 * Storage: XF's simpleCache (DB-backed) rather than $app->cache(), because
 * many XF installs leave the latter unconfigured (returns null). simpleCache
 * is always available and persists across requests via xf_simple_cache.
 */
class DealFeed extends AbstractWidget
{
    public const ADDON_ID = 'CAG/DealFeed';
    // Cache key versioned by pool-size so bumping POOL_SIZE invalidates stale
    // smaller-pool cache entries without waiting for TTL expiry.
    public const CACHE_KEY = 'cag_deal_feed_48h_p60';
    public const CACHE_TTL = 900; // 15 minutes — matches cron cadence (5 min) ×3.

    // How many deals to ship in the initial server-rendered JSON. The JS widget
    // displays 12 cards initially then paginates client-side through this pool.
    // Larger = more pagination room without a fresh /api/deals call, but bigger
    // initial HTML payload.
    public const POOL_SIZE = 60;

    protected $defaultOptions = [
        'title' => 'Hottest Deals',
        'limit' => self::POOL_SIZE,
    ];

    public function render()
    {
        $app = $this->app;
        $deals = $this->fetchFromSimpleCache($app);

        if (!$deals) {
            /** @var \CAG\DealFeed\Service\DealApiClient $client */
            $client = $app->service('CAG\DealFeed:DealApiClient');
            $deals = $client->fetch(48, self::POOL_SIZE);
            if ($deals) {
                $this->writeToSimpleCache($app, $deals);
            }
        }

        $limit = (int) ($this->options['limit'] ?? self::POOL_SIZE);
        if ($limit > 0 && is_array($deals)) {
            $deals = array_slice($deals, 0, $limit);
        }

        $dealsArray = is_array($deals) ? $deals : [];

        return $this->renderer('widget_deal_feed', [
            'title'     => $this->options['title'] ?? 'Hottest Deals',
            'deals'     => $dealsArray,
            // Pre-encode JSON in PHP because XF 2.2 templates don't have a
            // built-in json() function — `{{ json($var) }}` silently returns
            // nothing. We pass the encoded string and the template outputs
            // it raw inside the <script type="application/json"> block.
            'dealsJson' => json_encode($dealsArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        ]);
    }

    public function getOptionsTemplate()
    {
        return 'widget_deal_feed_options';
    }

    protected function fetchFromSimpleCache(\XF\App $app): ?array
    {
        $entry = $app->simpleCache()[self::ADDON_ID][self::CACHE_KEY] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        if (($entry['expires'] ?? 0) <= time()) {
            return null;
        }
        $data = $entry['data'] ?? null;
        return is_array($data) ? $data : null;
    }

    protected function writeToSimpleCache(\XF\App $app, array $deals): void
    {
        $app->simpleCache()[self::ADDON_ID][self::CACHE_KEY] = [
            'data'    => $deals,
            'expires' => time() + self::CACHE_TTL,
        ];
    }
}
