<?php
/**
 * josraenviogratis 1.0.6: aviso de licencia: el cartel con el botón sale desde la primera vez.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_6($module)
{
    return $module->getUpdater()->registerHooks();
}
