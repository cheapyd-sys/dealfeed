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
    public const CACHE_KEY = 'cag_deal_feed_48h';
    public const CACHE_TTL = 900; // 15 minutes — matches cron cadence (5 min) ×3.

    protected $defaultOptions = [
        'title' => 'Hottest Deals',
        'limit' => 12,
    ];

    public function render()
    {
        $app = $this->app;
        $deals = $this->fetchFromSimpleCache($app);

        if (!$deals) {
            /** @var \CAG\DealFeed\Service\DealApiClient $client */
            $client = $app->service('CAG\DealFeed:DealApiClient');
            $deals = $client->fetch(48, (int) ($this->options['limit'] ?? 12));
            if ($deals) {
                $this->writeToSimpleCache($app, $deals);
            }
        }

        $limit = (int) ($this->options['limit'] ?? 12);
        if ($limit > 0 && is_array($deals)) {
            $deals = array_slice($deals, 0, $limit);
        }

        return $this->renderer('widget_deal_feed', [
            'title' => $this->options['title'] ?? 'Hottest Deals',
            'deals' => is_array($deals) ? $deals : [],
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
