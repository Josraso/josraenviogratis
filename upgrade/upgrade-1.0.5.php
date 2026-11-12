<?php
/**
 * josraenviogratis 1.0.5: licencia decidida desde el panel del servidor de actualizaciones.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_5($module)
{
    return $module->getUpdater()->registerHooks();
}
