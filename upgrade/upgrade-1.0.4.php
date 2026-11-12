<?php
/**
 * josraenviogratis 1.0.4: conexión con el servidor de actualizaciones de josra.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_4($module)
{
    return $module->getUpdater()->registerHooks();
}
