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
    public function actionIndex()
    {
        $viewParams = [
            'pageTitle' => 'CAG Deal Feed',
        ];
        return $this->view('CAG\DealFeed:Feed\Index', 'cag_deal_feed_page', $viewParams);
    }
}
