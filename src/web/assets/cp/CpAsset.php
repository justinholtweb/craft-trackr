<?php

namespace justinholtweb\trackr\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * The control panel screens' layout, on Craft's own CSS variables so they follow the CP's theme.
 */
class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CraftCpAsset::class];
        $this->css = ['cp.css'];

        parent::init();
    }
}
