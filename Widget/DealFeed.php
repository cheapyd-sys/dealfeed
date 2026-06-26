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
        'title' => 'CAG Deal Feed',
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
            // SSR the first 12 cards so non-JS crawlers (Bing, social
            // unfurlers) and Googlebot's first-pass indexer see real
            // content, not an empty grid.
            'cardsHtml' => $this->buildCardsHtml($dealsArray),
            // Schema.org Product listing for Google rich-snippet eligibility.
            'jsonLd'    => $this->buildJsonLd($dealsArray),
        ]);
    }

    /**
     * SSR the first 12 deal cards — must produce the same DOM shape the
     * client-side renderGrid() does so JS can skip the initial render and
     * avoid a flash. Hero / secondary / small / grid sizes by position match
     * the JS layout (index 0 / 1 / 2 / 3+).
     */
    protected function buildCardsHtml(array $deals): string
    {
        $html = '';
        $position = 0;
        foreach (array_slice($deals, 0, 12) as $deal) {
            // Same client-side filter: skip image-less deals so SSR matches
            // what JS would render after filtering.
            if (empty($deal['image_url'])) continue;
            $size = $position === 0 ? 'size-hero'
                  : ($position === 1 ? 'size-secondary'
                  : ($position === 2 ? 'size-small' : 'size-grid'));
            $html .= $this->renderCard($deal, $size);
            $position++;
        }
        return $html;
    }

    protected function renderCard(array $deal, string $size): string
    {
        $url = htmlspecialchars((string)($deal['deal_link'] ?? '#'), ENT_QUOTES, 'UTF-8');
        $img = htmlspecialchars((string)($deal['image_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars((string)($deal['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $price = htmlspecialchars((string)($deal['price'] ?? ''), ENT_QUOTES, 'UTF-8');
        $retailer = htmlspecialchars((string)($deal['retailer'] ?? ''), ENT_QUOTES, 'UTF-8');
        $isNews = empty($deal['is_ad']);

        $classes = 'cag-df-card ' . $size;
        if (!empty($deal['zoom_image'])) $classes .= ' cag-df-zoom';
        if (!empty($deal['shrink_image'])) $classes .= ' cag-df-fit';

        $date = '';
        if (!empty($deal['post_date'])) {
            $ts = strtotime(str_replace('+0000', 'Z', (string)$deal['post_date']));
            if ($ts) $date = date('n/j', $ts);
        }

        $h = '<a class="' . $classes . '" href="' . $url . '" target="_blank" rel="noopener">';
        if ($img !== '') {
            $h .= '<img class="cag-df-bg" src="' . $img . '" alt="' . $title . '" loading="lazy"/>';
        } else {
            $h .= '<div class="cag-df-noimg">&#9633;</div>';
        }
        $h .= '<div class="cag-df-grad cag-df-grad-strong"></div>';
        if ($date !== '') $h .= '<div class="cag-df-date">' . $date . '</div>';
        $h .= '<div class="cag-df-content">';

        // Hero card: retailer in ptags ABOVE the title; secondary/small/grid skip ptags.
        if ($size === 'size-hero' && $retailer !== '') {
            $h .= '<div class="cag-df-ptags"><span class="cag-df-ptag">' . $retailer . '</span></div>';
        }

        if ($size === 'size-hero') {
            if ($isNews) {
                $h .= '<h2 style="font-size:20px;">' . $title . '</h2>';
            } else {
                $h .= '<h2>' . $title . '</h2>';
                if ($price !== '') {
                    $h .= '<div style="margin-top:14px;"><span class="cag-df-price">' . $price . '</span></div>';
                }
            }
        } else {
            $heading = $size === 'size-secondary' ? 'h3' : 'h4';
            $h .= '<' . $heading . '>' . $title . '</' . $heading . '>';
            if (!$isNews && ($price !== '' || $retailer !== '')) {
                $mt = $size === 'size-secondary' ? 10 : ($size === 'size-small' ? 8 : 6);
                $h .= '<div class="cag-df-priceline" style="margin-top:' . $mt . 'px;">';
                if ($price !== '') $h .= '<span class="cag-df-price">' . $price . '</span>';
                if ($retailer !== '') $h .= '<span class="cag-df-retailer">' . $retailer . '</span>';
                $h .= '</div>';
            }
        }

        $h .= '</div></a>';
        return $h;
    }

    /**
     * Schema.org ItemList of Product entries. Google uses this to show
     * price/availability rich snippets in search results — high-CTR boost
     * for shopping-intent queries.
     *
     * Price extraction: keeps only deals where we can pull a clean numeric
     * value. Ranges, "Free", "10% off" etc. become Product entries WITHOUT
     * an Offer (still useful for indexing, just no price snippet).
     */
    protected function buildJsonLd(array $deals): string
    {
        $items = [];
        $position = 1;
        foreach (array_slice($deals, 0, 12) as $deal) {
            if (empty($deal['title']) || empty($deal['image_url'])) continue;

            $product = [
                '@type' => 'Product',
                'name'  => (string)$deal['title'],
                'image' => (string)$deal['image_url'],
            ];

            $priceStr = (string)($deal['price'] ?? '');
            if (preg_match('/^\$([0-9,]+(?:\.[0-9]+)?)$/', trim($priceStr), $m)) {
                $offer = [
                    '@type'         => 'Offer',
                    'price'         => str_replace(',', '', $m[1]),
                    'priceCurrency' => 'USD',
                    'availability'  => 'https://schema.org/InStock',
                ];
                if (!empty($deal['deal_link'])) $offer['url'] = (string)$deal['deal_link'];
                if (!empty($deal['retailer'])) {
                    $offer['seller'] = ['@type' => 'Organization', 'name' => (string)$deal['retailer']];
                }
                $product['offers'] = $offer;
            } elseif (stripos($priceStr, 'free') !== false) {
                $product['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => '0',
                    'priceCurrency' => 'USD',
                    'availability'  => 'https://schema.org/InStock',
                    'url'           => (string)($deal['deal_link'] ?? ''),
                ];
            }

            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'item'     => $product,
            ];
        }

        $payload = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'itemListElement' => $items,
        ];
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
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
