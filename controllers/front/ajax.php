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
        $ipa = 0;
        $qty = max(1, (int) Tools::getValue('qty', 1));
        if ($idProduct) {
            // Combinación elegida: la que manda PrestaShop en el evento o, si no, la de los atributos del formulario.
            $ipa = (int) Tools::getValue('jeg_ipa');
            $groups = Tools::getValue('group');
            if (!$ipa && is_array($groups) && $groups) {
                $ipa = JosraEnvioGratisEngine::resolveCombination($idProduct, $groups);
            }
            if ($ipa) {
                $belongs = (int) Db::getInstance()->getValue(
                    'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . (int) $ipa
                );
                if ($belongs !== $idProduct) {
                    $ipa = JosraEnvioGratisEngine::resolveCombination($idProduct, []);
                }
            }
        }
        $html = [];
        foreach ($positions as $pos) {
            $pos = trim($pos);
            if (!in_array($pos, $allowed) || isset($html[$pos])) {
                continue;
            }
            $html[$pos] = $pos === 'product'
                ? $this->module->renderInner($pos, $idProduct, $ipa, $qty)
                : $this->module->renderInner($pos);
        }
        if (ob_get_level()) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        die(json_encode(['html' => $html]));
    }
}
