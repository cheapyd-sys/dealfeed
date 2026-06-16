<?php

namespace CAG\DealFeed\Widget;

use XF\Widget\AbstractWidget;

/**
 * Reads the cached deal feed and hands it to the public template.
 *
 * Falls back to a live fetch ONLY if the cache is completely empty (first
 * page load after install, before the 5-min cron has run). On subsequent
 * loads this is a single cache read — no HTTP.
 */
class DealFeed extends AbstractWidget
{
    protected $defaultOptions = [
        'title' => 'Hottest Deals',
        'limit' => 12,
    ];

    public function render()
    {
        $app = $this->app;
        $cache = $app->cache();

        $deals = $cache ? $cache->fetch('cag_deal_feed_48h') : null;

        if (!$deals) {
            /** @var \CAG\DealFeed\Service\DealApiClient $client */
            $client = $app->service('CAG\DealFeed:DealApiClient');
            $deals = $client->fetch(48, (int) ($this->options['limit'] ?? 12));
            if ($deals && $cache) {
                $cache->save('cag_deal_feed_48h', $deals, 900);
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
}
