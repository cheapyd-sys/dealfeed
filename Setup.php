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
}
