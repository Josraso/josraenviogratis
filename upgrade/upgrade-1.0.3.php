<?php
/**
 * 1.0.3: el aviso de la ficha cuenta el carrito más el producto que se está viendo
 * (combinación y cantidad). Añade sus dos textos nuevos.
 *
 * @author    josra
 * @copyright josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_3($module)
{
    $defaults = [
        'es' => ['Con este producto tienes portes gratis', 'Añadiendo este producto te faltarían {amount} para tener portes gratis'],
        'ca' => ['Amb aquest producte tens ports gratuïts', 'Afegint aquest producte et faltarien {amount} per tenir ports gratuïts'],
        'gl' => ['Con este produto tes portes gratis', 'Engadindo este produto faltaríanche {amount} para ter portes gratis'],
        'en' => ['With this product your order ships for free', 'With this product you would need {amount} more for free shipping'],
    ];
    $achieved = [];
    $remaining = [];
    foreach (Language::getLanguages(false) as $lang) {
        $iso = isset($defaults[$lang['iso_code']]) ? $lang['iso_code'] : ($lang['iso_code'] === 'eu' ? 'es' : 'en');
        $id = (int) $lang['id_lang'];
        $cur = Configuration::get('JEG_TXT_PRODUCT_ACHIEVED', $id);
        $achieved[$id] = $cur ? $cur : $defaults[$iso][0];
        $cur = Configuration::get('JEG_TXT_PRODUCT_REMAINING', $id);
        $remaining[$id] = $cur ? $cur : $defaults[$iso][1];
    }
    Configuration::updateValue('JEG_TXT_PRODUCT_ACHIEVED', $achieved);
    Configuration::updateValue('JEG_TXT_PRODUCT_REMAINING', $remaining);

    return true;
}
