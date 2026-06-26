<?php

namespace CAG\DealFeed;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    // No schema needed — this add-on only consumes an external HTTP API and
    // stores results in XF's cache. Install/upgrade/uninstall are no-ops.

    public function installStep1(): void {}
    public function uninstallStep1(): void
    {
        $cache = \XF::app()->cache();
        if ($cache) {
            $cache->delete('cag_deal_feed_48h');
        }
    }

    /**
     * Re-asserts the widget definition row after every install/upgrade/rebuild.
     *
     * Why this exists: `xf-addon:rebuild CAG/DealFeed` has historically dropped
     * the row in `xf_widget_definition` for unclear reasons (suspected: the
     * `_data/widget_definitions.xml` import path is brittle when run repeatedly
     * with no real changes). Without this row, the homepage widget render
     * throws `InvalidArgumentException: No widget definition exists with a
     * definition_class of CAG\DealFeed\Widget\DealFeed` and the entire
     * dealfeed-as-homepage page renders empty.
     *
     * This method is idempotent — it only inserts if the row is missing.
     */
    protected function ensureWidgetDefinition(): void
    {
        $db = \XF::db();
        $exists = $db->fetchOne(
            'SELECT definition_id FROM xf_widget_definition WHERE definition_id = ?',
            ['cag_deal_feed']
        );
        if (!$exists) {
            $db->insert('xf_widget_definition', [
                'definition_id'    => 'cag_deal_feed',
                'definition_class' => 'CAG\\DealFeed\\Widget\\DealFeed',
                'addon_id'         => 'CAG/DealFeed',
            ]);
        }
    }

    public function postInstall(array &$stateChanges): void
    {
        $this->ensureWidgetDefinition();
    }

    public function postUpgrade($previousVersion, array &$stateChanges): void
    {
        $this->ensureWidgetDefinition();
    }

    public function postRebuild(): void
    {
        $this->ensureWidgetDefinition();
    }
}
