<?php

namespace CAG\DealFeed\Pub\Controller;

use XF\Pub\Controller\AbstractController;

/**
 * Standalone landing page for the deal feed.
 *
 * Renders the widget inside the standard forum header/footer chrome but
 * bypasses the forum-node breadcrumb, page-title heading, share buttons,
 * and block frame that a Page-node-based URL would impose.
 *
 * Mirrors the pattern the existing CAG/FrontPage add-on uses to render the
 * cag_home template outside the node system. For testing this widget lives
 * at /dealfeed-preview/ — re-route it (or copy the template into the
 * FrontPage add-on's cag_home template) when promoting to the homepage.
 */
class Feed extends AbstractController
{
    /**
     * Shared secret matched against the worker's XF_REFRESH_TOKEN env var.
     * The deal-feed editor (on deals.cheapassgamer.com) posts here when an
     * editor wants the homepage widget to pick up edits immediately rather
     * than waiting for the 15-min cache TTL or the 5-min cron.
     *
     * To rotate: change this value AND update the worker secret via
     * `wrangler secret put XF_REFRESH_TOKEN`.
     */
    const REFRESH_TOKEN = '441d9149afe1e76a6c5c2685d677f1551c48fff02e36706444a12c81cad0d8f3';

    public function actionIndex()
    {
        // Refresh-cache shortcut piggybacks on actionIndex because XF's default
        // route dispatcher for an empty-sub_name route doesn't auto-map
        // sub-actions like /dealfeed-preview/refresh-cache. Using a query
        // param avoids needing a routes.xml change + addon rebuild.
        $refreshToken = $this->filter('refresh_token', 'str');
        if ($refreshToken !== '') {
            if (!hash_equals(self::REFRESH_TOKEN, $refreshToken)) {
                return $this->error('Bad token', 403);
            }
            \CAG\DealFeed\Cron\DealFeed::refreshCache();
            return $this->message('Cache refreshed');
        }

        $viewParams = [
            'pageTitle' => 'CAG Deal Feed',
        ];
        return $this->view('CAG\DealFeed:Feed\Index', 'cag_deal_feed_page', $viewParams);
    }
}
