<?php
/**
 * Devuelve el aviso de portes actualizado cuando cambia el carrito (sin recargar la página).
 *
 * @author    josra
 * @copyright josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraenviogratisAjaxModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_column_left = false;
    public $display_column_right = false;

    public function initContent()
    {
        // Sin plantilla: solo JSON.
    }

    public function postProcess()
    {
        $this->sendJson();
    }

    public function displayAjax()
    {
        $this->sendJson();
    }

    protected function sendJson()
    {
        $allowed = ['cart', 'modal', 'checkout', 'product', 'custom'];
        $positions = explode(',', (string) Tools::getValue('positions'));
        $idProduct = (int) Tools::getValue('jeg_product');
        $html = [];
        foreach ($positions as $pos) {
            $pos = trim($pos);
            if (!in_array($pos, $allowed) || isset($html[$pos])) {
                continue;
            }
            $html[$pos] = $this->module->renderInner($pos, $pos === 'product' ? $idProduct : 0);
        }
        if (ob_get_level()) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        die(json_encode(['html' => $html]));
    }
}
