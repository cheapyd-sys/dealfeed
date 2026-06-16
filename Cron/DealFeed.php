<?php

namespace CAG\DealFeed\Cron;

use CAG\DealFeed\Widget\DealFeed as Widget;

/**
 * Cron-driven cache refresh for the deal feed.
 * Runs every 5 minutes. Caches the default 48h window into XF's simpleCache
 * (DB-backed) so it survives across requests regardless of XF's cache config.
 *
 * The widget reads this cache synchronously on every page render, so this
 * cron job is the ONLY place where the worker is contacted server-side —
 * keeping page-load latency completely decoupled from worker latency.
 */
class DealFeed
{
    public static function refreshCache(): void
    {
        $app = \XF::app();

        /** @var \CAG\DealFeed\Service\DealApiClient $client */
        $client = $app->service('CAG\DealFeed:DealApiClient');
        $deals = $client->fetch(48, 12);

        // If the fetch failed (empty array), DO NOT overwrite the existing
        // cache — better to serve stale data than nothing.
        if (!$deals) {
            return;
        }

        // 15-min hard TTL via stored timestamp: even if the cron stops firing
        // (server load, disabled, etc.) the widget starts showing empty after
        // 15 min rather than indefinitely-stale data.
        $app->simpleCache()[Widget::ADDON_ID][Widget::CACHE_KEY] = [
            'data'    => $deals,
            'expires' => time() + Widget::CACHE_TTL,
        ];
    }
}
