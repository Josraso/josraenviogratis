<?php
/**
 * Envío gratis con reglas — portes gratis o rebajados sin reglas de descuento.
 *
 * PrestaShop 9:   hook actionCartGetPackageShippingCost (todos los transportistas).
 * PrestaShop 1.7/8: los transportistas se calculan a través del módulo (sin override).
 *
 * @author    josra
 * @copyright josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/JosraEnvioGratisRule.php';
require_once dirname(__FILE__) . '/classes/JosraEnvioGratisCarriers.php';
require_once dirname(__FILE__) . '/classes/JosraEnvioGratisEngine.php';
require_once dirname(__FILE__) . '/classes/JosraenviogratisUpdater.php';

class Josraenviogratis extends Module
{
    const DB_VERSION = '1.0.0';

    /** @var int Lo rellena PrestaShop (1.7/8) antes de pedir el porte de un transportista adoptado */
    public $id_carrier;

    protected static $rendered = [];
    protected static $barCache = [];
    protected static $enabledCache = null;

    public function __construct()
    {
        $this->name = 'josraenviogratis';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.6';
        $this->author = 'josra';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Envío gratis con reglas');
        $this->description = $this->l('Portes gratis o rebajados por importe, productos, zona, transportista y más, sin reglas de descuento: el transporte sale a 0 € en el pedido y en la factura.');
        $this->confirmUninstall = $this->l('Se borrarán las reglas y los transportistas volverán a como estaban. ¿Seguro?');
    }

    public function getUpdater()
    {
        return new JosraenviogratisUpdater($this);
    }

    /** Aviso periódico al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }

    /* ======================================================================
     * Instalación y autorreparación
     * ==================================================================== */

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        if (!$this->ensureSchema()) {
            $this->_errors[] = $this->l('No se han podido crear las tablas del módulo en la base de datos.');

            return false;
        }
        $this->ensureHooks();
        $this->getUpdater()->registerHooks();
        $this->ensureConfig();
        Configuration::updateGlobalValue('JEG_DB_VERSION', self::DB_VERSION);
        JosraEnvioGratisCarriers::sync();

        return true;
    }

    public function uninstall()
    {
        $this->getUpdater()->notifyUninstall();
        Configuration::deleteByName('JOSRAENVIOGRATIS_UPD_TS');
        Configuration::deleteByName('JOSRAENVIOGRATIS_UPD_INFO');
        Configuration::deleteByName('JOSRAENVIOGRATIS_UPD_SU');
        Configuration::deleteByName('JOSRAENVIOGRATIS_UPD_LT');

        // Primero se devuelven los transportistas a su estado original.
        JosraEnvioGratisCarriers::releaseAll();

        $tables = ['josraenviogratis_rule', 'josraenviogratis_rule_lang', 'josraenviogratis_carrier', 'josraenviogratis_order'];
        foreach ($tables as $t) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $t . '`');
        }
        foreach (array_keys($this->getConfigDefaults()) as $key) {
            Configuration::deleteByName($key);
        }
        foreach (array_merge($this->getTextKeys(), ['JEG_CARRIER_MODE', 'JEG_DB_VERSION']) as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /**
     * Si el módulo se desactiva, los transportistas adoptados siguen funcionando con su precio normal
     * (el módulo devuelve el porte sin tocar). No hace falta liberarlos.
     */
    protected function getHookList()
    {
        $hooks = [
            'actionFrontControllerSetMedia',
            'displayShoppingCartFooter',
            'displayCartModalFooter',
            'displayBeforeCarrier',
            'displayProductAdditionalInfo',
            'displayJosraEnvioGratis',
            'actionValidateOrder',
        ];
        if (version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            $hooks[] = 'actionCartGetPackageShippingCost';
        } else {
            $hooks[] = 'actionCarrierUpdate';
            $hooks[] = 'actionObjectCarrierAddAfter';
            $hooks[] = 'actionObjectCarrierUpdateAfter';
        }
        if (version_compare(_PS_VERSION_, '1.7.7.0', '>=')) {
            $hooks[] = 'displayAdminOrderSide';
        } else {
            $hooks[] = 'displayAdminOrder';
        }

        return $hooks;
    }

    protected function ensureHooks()
    {
        $fixed = 0;
        foreach ($this->getHookList() as $hook) {
            if (!$this->isRegisteredInHook($hook)) {
                if ($this->registerHook($hook)) {
                    ++$fixed;
                }
            }
        }

        return $fixed;
    }

    protected function getConfigDefaults()
    {
        return [
            'JEG_SHOW_CART' => 1,
            'JEG_SHOW_MODAL' => 1,
            'JEG_SHOW_CHECKOUT' => 1,
            'JEG_SHOW_PRODUCT_BAR' => 0,
            'JEG_SHOW_PROGRESS' => 1,
            'JEG_UNKNOWN_MAX' => 3,
        ];
    }

    protected function getTextKeys()
    {
        return ['JEG_TXT_REMAINING', 'JEG_TXT_ACHIEVED', 'JEG_TXT_PRODUCT', 'JEG_TXT_PRODUCT_ACHIEVED', 'JEG_TXT_PRODUCT_REMAINING'];
    }

    protected function getTextDefaults($iso)
    {
        $es = [
            'JEG_TXT_REMAINING' => 'Te faltan {amount} para tener portes gratis',
            'JEG_TXT_ACHIEVED' => '¡Tienes portes gratis en este pedido!',
            'JEG_TXT_PRODUCT' => 'Portes gratis con este producto',
            'JEG_TXT_PRODUCT_ACHIEVED' => 'Con este producto tienes portes gratis',
            'JEG_TXT_PRODUCT_REMAINING' => 'Añadiendo este producto te faltarían {amount} para tener portes gratis',
        ];
        $en = [
            'JEG_TXT_REMAINING' => 'Add {amount} more to get free shipping',
            'JEG_TXT_ACHIEVED' => 'Your order ships for free!',
            'JEG_TXT_PRODUCT' => 'Free shipping with this product',
            'JEG_TXT_PRODUCT_ACHIEVED' => 'With this product your order ships for free',
            'JEG_TXT_PRODUCT_REMAINING' => 'With this product you would need {amount} more for free shipping',
        ];
        $ca = [
            'JEG_TXT_REMAINING' => 'Et falten {amount} per tenir ports gratuïts',
            'JEG_TXT_ACHIEVED' => 'Tens ports gratuïts en aquesta comanda!',
            'JEG_TXT_PRODUCT' => 'Ports gratuïts amb aquest producte',
            'JEG_TXT_PRODUCT_ACHIEVED' => 'Amb aquest producte tens ports gratuïts',
            'JEG_TXT_PRODUCT_REMAINING' => 'Afegint aquest producte et faltarien {amount} per tenir ports gratuïts',
        ];
        $gl = [
            'JEG_TXT_REMAINING' => 'Fáltanche {amount} para ter portes gratis',
            'JEG_TXT_ACHIEVED' => 'Tes portes gratis neste pedido!',
            'JEG_TXT_PRODUCT' => 'Portes gratis con este produto',
            'JEG_TXT_PRODUCT_ACHIEVED' => 'Con este produto tes portes gratis',
            'JEG_TXT_PRODUCT_REMAINING' => 'Engadindo este produto faltaríanche {amount} para ter portes gratis',
        ];
        $map = ['es' => $es, 'ca' => $ca, 'gl' => $gl, 'eu' => $es, 'en' => $en];

        return isset($map[$iso]) ? $map[$iso] : $en;
    }

    protected function ensureConfig()
    {
        foreach ($this->getConfigDefaults() as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }
        foreach ($this->getTextKeys() as $key) {
            $values = [];
            $changed = false;
            foreach (Language::getLanguages(false) as $lang) {
                $current = Configuration::get($key, (int) $lang['id_lang']);
                if ($current === false || $current === '') {
                    $def = $this->getTextDefaults($lang['iso_code']);
                    $values[(int) $lang['id_lang']] = $def[$key];
                    $changed = true;
                } else {
                    $values[(int) $lang['id_lang']] = $current;
                }
            }
            if ($changed) {
                Configuration::updateValue($key, $values);
            }
        }
    }

    /**
     * Ajustes siempre completos: lo guardado mezclado con los valores por defecto.
     */
    public function getSettings()
    {
        $out = [];
        foreach ($this->getConfigDefaults() as $key => $def) {
            $v = Configuration::get($key);
            $out[$key] = ($v === false || $v === null) ? $def : (int) $v;
        }

        return $out;
    }

    protected function ensureSchema()
    {
        $db = Db::getInstance();
        $engine = _MYSQL_ENGINE_;
        $cols = [];
        foreach (JosraEnvioGratisRule::getSqlColumns() as $col => $def) {
            $cols[] = '`' . $col . '` ' . $def;
        }
        $ok = $db->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'josraenviogratis_rule` (
                `id_rule` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                ' . implode(",\n                ", $cols) . ',
                PRIMARY KEY (`id_rule`),
                KEY `active_priority` (`active`, `priority`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4'
        );
        $ok = $db->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'josraenviogratis_rule_lang` (
                `id_rule` INT(10) UNSIGNED NOT NULL,
                `id_lang` INT(10) UNSIGNED NOT NULL,
                `title` VARCHAR(255) NOT NULL DEFAULT \'\',
                PRIMARY KEY (`id_rule`, `id_lang`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4'
        ) && $ok;
        $ok = $db->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'josraenviogratis_carrier` (
                `id_carrier` INT(10) UNSIGNED NOT NULL,
                `id_reference` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `orig_shipping_external` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `orig_need_range` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `orig_external_module_name` VARCHAR(64) NOT NULL DEFAULT \'\',
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_carrier`),
                KEY `id_reference` (`id_reference`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4'
        ) && $ok;
        $ok = $db->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'josraenviogratis_order` (
                `id_order` INT(10) UNSIGNED NOT NULL,
                `id_cart` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `id_rule` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `rule_name` VARCHAR(128) NOT NULL DEFAULT \'\',
                `id_carrier` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `cost_before` DECIMAL(20,6) NULL DEFAULT NULL,
                `cost_after` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_order`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4'
        ) && $ok;

        // Columnas que falten (actualizaciones subidas por FTP sin reinstalar)
        $existing = [];
        $rows = $db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'josraenviogratis_rule`');
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $existing[$r['Field']] = true;
            }
            foreach (JosraEnvioGratisRule::getSqlColumns() as $col => $def) {
                if (!isset($existing[$col])) {
                    $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'josraenviogratis_rule` ADD `' . $col . '` ' . $def);
                }
            }
        }

        return (bool) $ok;
    }

    protected function selfRepair()
    {
        $this->ensureSchema();
        $this->ensureHooks();
        $this->ensureConfig();
        if (Configuration::getGlobalValue('JEG_DB_VERSION') !== self::DB_VERSION) {
            Configuration::updateGlobalValue('JEG_DB_VERSION', self::DB_VERSION);
        }

        return JosraEnvioGratisCarriers::sync();
    }

    /* ======================================================================
     * Cálculo del porte
     * ==================================================================== */

    protected static function moduleEnabled()
    {
        if (self::$enabledCache === null) {
            self::$enabledCache = (bool) Module::isEnabled('josraenviogratis');
        }

        return self::$enabledCache;
    }

    /**
     * PrestaShop 1.7/8: transportista adoptado con rangos (need_range = 1).
     * Recibe el porte calculado por PrestaShop, sin IVA.
     */
    public function getPackageShippingCost($cart, $shipping_cost, $products)
    {
        return $this->moduleShippingCost($cart, $shipping_cost);
    }

    public function getOrderShippingCost($params, $shipping_cost)
    {
        return $this->moduleShippingCost($params, $shipping_cost);
    }

    /**
     * Solo se llamaría si alguien pone need_range = 0 en un transportista adoptado. La autorreparación
     * lo vuelve a poner a 1; mientras tanto el transportista no se ofrece.
     */
    public function getOrderShippingCostExternal($params)
    {
        return false;
    }

    protected function moduleShippingCost($cart, $cost)
    {
        if ($cost === false) {
            return false;
        }
        if (!($cart instanceof Cart) || JosraEnvioGratisEngine::isPs9() || !self::moduleEnabled()) {
            return $cost;
        }
        $idCarrier = (int) $this->id_carrier;
        if (!$idCarrier) {
            return $cost;
        }
        JosraEnvioGratisEngine::rememberModuleRaw($cart, $idCarrier, $cost);

        return JosraEnvioGratisEngine::applyToCost($cart, $idCarrier, (float) $cost, false, 'module');
    }

    /**
     * PrestaShop 9: se ejecuta después de todo el cálculo (también el de los módulos de transporte).
     */
    public function hookActionCartGetPackageShippingCost($params)
    {
        if (!isset($params['cart']) || !($params['cart'] instanceof Cart) || !array_key_exists('shippingCost', $params)) {
            return;
        }
        $cost = $params['shippingCost'];
        if ($cost === false || $cost === null) {
            return;
        }
        $cart = $params['cart'];
        $idCarrier = !empty($params['id_carrier']) ? (int) $params['id_carrier'] : (int) $cart->id_carrier;
        if (!$idCarrier) {
            return;
        }
        $withTax = !empty($params['use_tax']);
        JosraEnvioGratisEngine::rememberRaw($cart, $idCarrier, $withTax, $cost);
        $new = JosraEnvioGratisEngine::applyToCost($cart, $idCarrier, (float) $cost, $withTax, 'hook');
        if ($new !== $cost) {
            $params['shippingCost'] = $new;
        }
    }

    /* ======================================================================
     * Transportistas (1.7/8)
     * ==================================================================== */

    public function hookActionCarrierUpdate($params)
    {
        if (isset($params['carrier']) && Validate::isLoadedObject($params['carrier'])) {
            JosraEnvioGratisCarriers::syncOne((int) $params['carrier']->id);
        }
    }

    public function hookActionObjectCarrierAddAfter($params)
    {
        if (isset($params['object']) && Validate::isLoadedObject($params['object'])) {
            JosraEnvioGratisCarriers::syncOne((int) $params['object']->id);
        }
    }

    public function hookActionObjectCarrierUpdateAfter($params)
    {
        if (isset($params['object']) && Validate::isLoadedObject($params['object'])) {
            JosraEnvioGratisCarriers::syncOne((int) $params['object']->id);
        }
    }

    /* ======================================================================
     * Pedidos
     * ==================================================================== */

    public function hookActionValidateOrder($params)
    {
        if (empty($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return;
        }
        $order = $params['order'];
        $cart = (!empty($params['cart']) && $params['cart'] instanceof Cart) ? $params['cart'] : new Cart((int) $order->id_cart);
        $info = JosraEnvioGratisEngine::getApplied((int) $cart->id, (int) $order->id_carrier);
        $before = null;
        if ($info) {
            if (!$info['id_rule']) {
                return;
            }
            $idRule = (int) $info['id_rule'];
            $name = $info['rule_name'];
            $before = $info['with_tax'] ? $info['before'] : null;
        } else {
            if (!JosraEnvioGratisEngine::getRules() || !Validate::isLoadedObject($cart)) {
                return;
            }
            $hit = JosraEnvioGratisEngine::pickRule($cart, (int) $order->id_carrier);
            if (!$hit) {
                return;
            }
            $idRule = (int) $hit['rule']['id_rule'];
            $name = $hit['rule']['name'];
        }
        if ($before === null && Validate::isLoadedObject($cart)) {
            // Porte que habría cobrado PrestaShop sin la regla, para dejarlo anotado en el pedido.
            $before = JosraEnvioGratisEngine::rawShipping($cart, (int) $order->id_carrier, true);
        }
        Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . 'josraenviogratis_order`
                (`id_order`, `id_cart`, `id_rule`, `rule_name`, `id_carrier`, `cost_before`, `cost_after`, `date_add`)
             VALUES (' . (int) $order->id . ', ' . (int) $cart->id . ', ' . $idRule . ', \'' . pSQL($name) . '\', '
                . (int) $order->id_carrier . ', ' . ($before === null ? 'NULL' : (float) $before) . ', '
                . (float) $order->total_shipping_tax_incl . ', \'' . pSQL(date('Y-m-d H:i:s')) . '\')'
        );
    }

    public function hookDisplayAdminOrderSide($params)
    {
        return $this->renderAdminOrder(isset($params['id_order']) ? (int) $params['id_order'] : 0);
    }

    public function hookDisplayAdminOrder($params)
    {
        if (version_compare(_PS_VERSION_, '1.7.7.0', '>=')) {
            return '';
        }

        return $this->renderAdminOrder(isset($params['id_order']) ? (int) $params['id_order'] : 0);
    }

    protected function renderAdminOrder($idOrder)
    {
        if (!$idOrder) {
            return '';
        }
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josraenviogratis_order` WHERE `id_order` = ' . (int) $idOrder
        );
        if (!$row) {
            return '';
        }
        $order = new Order((int) $idOrder);
        $idCurrency = Validate::isLoadedObject($order) ? (int) $order->id_currency : (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $this->context->smarty->assign([
            'jeg_order' => [
                'rule_name' => $row['rule_name'],
                'id_rule' => (int) $row['id_rule'],
                'before' => ($row['cost_before'] !== null && (float) $row['cost_before'] > (float) $row['cost_after'])
                    ? JosraEnvioGratisEngine::formatPrice($row['cost_before'], $idCurrency) : '',
                'free' => (float) $row['cost_after'] == 0,
                'after' => JosraEnvioGratisEngine::formatPrice($row['cost_after'], $idCurrency),
                'new_layout' => version_compare(_PS_VERSION_, '1.7.7.0', '>='),
            ],
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/hook/admin_order.tpl');
    }

    /* ======================================================================
     * Front
     * ==================================================================== */

    public function hookActionFrontControllerSetMedia($params)
    {
        $ctrl = $this->context->controller;
        if (!$ctrl || !method_exists($ctrl, 'registerStylesheet')) {
            return;
        }
        $ctrl->registerStylesheet('modules-josraenviogratis', 'modules/' . $this->name . '/views/css/front.css', ['media' => 'all', 'priority' => 150]);
        $ctrl->registerJavascript('modules-josraenviogratis', 'modules/' . $this->name . '/views/js/front.js', ['position' => 'bottom', 'priority' => 150]);
        Media::addJsDef([
            'jegAjaxUrl' => $this->context->link->getModuleLink($this->name, 'ajax', [], true),
        ]);
    }

    public function hookDisplayShoppingCartFooter($params)
    {
        $s = $this->getSettings();

        return $s['JEG_SHOW_CART'] ? $this->renderBox('cart') : '';
    }

    public function hookDisplayCartModalFooter($params)
    {
        $s = $this->getSettings();

        return $s['JEG_SHOW_MODAL'] ? $this->renderBox('modal') : '';
    }

    public function hookDisplayBeforeCarrier($params)
    {
        $s = $this->getSettings();

        return $s['JEG_SHOW_CHECKOUT'] ? $this->renderBox('checkout') : '';
    }

    public function hookDisplayJosraEnvioGratis($params)
    {
        return $this->renderBox('custom');
    }

    public function hookDisplayProductAdditionalInfo($params)
    {
        $idProduct = 0;
        if (isset($params['product'])) {
            $p = $params['product'];
            if (is_array($p) || $p instanceof ArrayAccess) {
                $idProduct = isset($p['id_product']) ? (int) $p['id_product'] : (isset($p['id']) ? (int) $p['id'] : 0);
            } elseif (is_object($p) && isset($p->id)) {
                $idProduct = (int) $p->id;
            }
        }
        if (!$idProduct) {
            $idProduct = (int) Tools::getValue('id_product');
        }
        if (!$idProduct) {
            return '';
        }
        $ipa = 0;
        $qty = 1;
        if (isset($params['product'])) {
            $p = $params['product'];
            if (is_array($p) || $p instanceof ArrayAccess) {
                $ipa = isset($p['id_product_attribute']) ? (int) $p['id_product_attribute'] : 0;
                if (isset($p['quantity_wanted']) && (int) $p['quantity_wanted'] > 0) {
                    $qty = (int) $p['quantity_wanted'];
                } elseif (isset($p['minimal_quantity']) && (int) $p['minimal_quantity'] > 0) {
                    $qty = (int) $p['minimal_quantity'];
                }
            }
        }
        if (!$ipa) {
            $ipa = (int) Tools::getValue('id_product_attribute');
        }

        return $this->renderBox('product', $idProduct, $ipa, $qty);
    }

    /**
     * Contenedor que el JS refresca cuando cambia el carrito.
     */
    public function renderBox($pos, $idProduct = 0, $ipa = 0, $qty = 1)
    {
        $key = $pos . '-' . (int) $idProduct;
        if (isset(self::$rendered[$key])) {
            return '';
        }
        self::$rendered[$key] = true;

        return '<div class="josraenviogratis-box" data-jeg-pos="' . htmlspecialchars($pos, ENT_QUOTES, 'UTF-8') . '"'
            . ($idProduct ? ' data-jeg-product="' . (int) $idProduct . '"' : '') . '>'
            . $this->renderInner($pos, $idProduct, $ipa, $qty) . '</div>';
    }

    protected function getBarState()
    {
        $cart = $this->context->cart;
        if (!$cart || !Validate::isLoadedObject($cart)) {
            return null;
        }
        $key = (int) $cart->id . '-' . (int) $cart->nbProducts() . '-' . (int) $cart->id_carrier . '-' . (int) $cart->id_address_delivery;
        if (!array_key_exists($key, self::$barCache)) {
            $s = $this->getSettings();
            self::$barCache[$key] = JosraEnvioGratisEngine::barState($cart, max(1, (int) $s['JEG_UNKNOWN_MAX']));
        }

        return self::$barCache[$key];
    }

    /**
     * HTML interior de una posición (también lo usa el controlador AJAX).
     */
    /**
     * Texto configurable con reserva al texto por defecto (por si aún no se ha abierto la configuración
     * después de actualizar por FTP).
     */
    protected function text($key, $idLang)
    {
        $v = (string) Configuration::get($key, (int) $idLang);
        if ($v === '') {
            $lang = new Language((int) $idLang);
            $def = $this->getTextDefaults(Validate::isLoadedObject($lang) ? $lang->iso_code : 'es');
            $v = isset($def[$key]) ? $def[$key] : '';
        }

        return $v;
    }

    public function renderInner($pos, $idProduct = 0, $ipa = 0, $qty = 1)
    {
        if (!self::moduleEnabled()) {
            return '';
        }
        $s = $this->getSettings();
        $idLang = (int) $this->context->language->id;
        $badge = '';
        $state = null;

        if ($pos === 'product') {
            $rule = JosraEnvioGratisEngine::productBadgeRule(
                (int) $idProduct,
                (int) $this->context->shop->id,
                (int) $this->context->currency->id
            );
            if ($rule) {
                $badge = $this->text('JEG_TXT_PRODUCT', $idLang);
            }
            if ($s['JEG_SHOW_PRODUCT_BAR']) {
                // En la ficha se cuenta el carrito MÁS el producto que se está viendo.
                $state = JosraEnvioGratisEngine::productBarState(
                    $this->context->cart,
                    (int) $idProduct,
                    (int) $ipa,
                    max(1, (int) $qty),
                    max(1, (int) $s['JEG_UNKNOWN_MAX'])
                );
            }
        } else {
            $state = $this->getBarState();
        }

        if (!$state && $badge === '') {
            return '';
        }

        $view = null;
        if ($state) {
            $view = ['mode' => $state['mode']];
            if ($state['mode'] === 'achieved') {
                $view['text'] = $this->text('JEG_TXT_ACHIEVED', $idLang);
            } elseif ($state['mode'] === 'with_product') {
                $view['mode'] = 'achieved';
                $view['text'] = $this->text('JEG_TXT_PRODUCT_ACHIEVED', $idLang);
            } elseif ($state['mode'] === 'remaining' || $state['mode'] === 'remaining_with') {
                $view['mode'] = 'remaining';
                $tpl = $this->text(
                    $state['mode'] === 'remaining_with' ? 'JEG_TXT_PRODUCT_REMAINING' : 'JEG_TXT_REMAINING',
                    $idLang
                );
                $amount = JosraEnvioGratisEngine::formatPrice($state['remaining'], $state['id_currency']);
                $safe = htmlspecialchars($tpl, ENT_QUOTES, 'UTF-8');
                if (strpos($tpl, '{amount}') !== false) {
                    $html = str_replace('{amount}', '<strong>' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') . '</strong>', $safe);
                } else {
                    $html = $safe . ' <strong>' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') . '</strong>';
                }
                $view['html'] = $html;
                $view['show_progress'] = (bool) $s['JEG_SHOW_PROGRESS'];
                $view['progress'] = max(0, min(100, (int) $state['progress']));
                $view['title'] = (string) $state['rule']['title'];
            } elseif ($state['mode'] === 'unknown') {
                $view['titles'] = $state['titles'];
            }
        }

        $this->context->smarty->assign([
            'jeg_view' => $view,
            'jeg_badge' => $badge,
            'jeg_pos' => $pos,
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/hook/box.tpl');
    }

    /* ======================================================================
     * Back-office
     * ==================================================================== */

    /**
     * URL completa de la configuración del módulo (la configuración se abre por ruta Symfony en
     * 8/9 y una URL relativa acabaría en .../configure/index.php).
     */
    public function adminUrl($params = [], $withToken = true)
    {
        $adminDir = defined('_PS_ADMIN_DIR_') ? basename(_PS_ADMIN_DIR_) : 'admin';
        $base = Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . $adminDir . '/index.php';
        $query = [
            'controller' => 'AdminModules',
            'configure' => $this->name,
            'tab_module' => $this->tab,
            'module_name' => $this->name,
        ];
        if ($withToken) {
            $query['token'] = Tools::getAdminTokenLite('AdminModules');
        }

        return $base . '?' . http_build_query(array_merge($query, $params));
    }

    protected function fetchAdmin($tpl, $vars = [])
    {
        $this->context->smarty->assign($vars);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/' . $tpl);
    }

    public function getContent()
    {
        // AJAX antes de cualquier salida
        if (Tools::getValue('jeg_action') === 'searchProducts') {
            $this->ajaxSearchProducts();
        }

        $repair = $this->selfRepair();
        JosraEnvioGratisEngine::resetCache();

        // Servidor de actualizaciones: botones del panel, hooks y upgrades pendientes
        $updater = $this->getUpdater();
        $out = $updater->processRequest();
        $errors = [];
        $confirm = '';

        $tab = Tools::getValue('jeg_tab', 'rules');
        if (!in_array($tab, ['rules', 'carriers', 'simulator', 'display', 'help'])) {
            $tab = 'rules';
        }

        if (Tools::getValue('jeg_conf')) {
            $msgs = [
                1 => $this->l('Regla guardada.'),
                2 => $this->l('Regla borrada.'),
                3 => $this->l('Estado de la regla cambiado.'),
                4 => $this->l('Ajustes guardados.'),
                5 => $this->l('Transportistas actualizados.'),
                6 => $this->l('Todos los transportistas han vuelto a su estado original.'),
                7 => $this->l('Regla duplicada (queda desactivada para que la revises).'),
            ];
            $c = (int) Tools::getValue('jeg_conf');
            if (isset($msgs[$c])) {
                $confirm = $msgs[$c];
            }
        }

        // --- Acciones sobre reglas
        if (Tools::isSubmit('deletejosraenviogratis_rule')) {
            $rule = new JosraEnvioGratisRule((int) Tools::getValue('id_rule'));
            if (Validate::isLoadedObject($rule) && $rule->delete()) {
                Tools::redirectAdmin($this->adminUrl(['jeg_tab' => 'rules', 'jeg_conf' => 2]));
            }
            $errors[] = $this->l('No se ha podido borrar la regla: no existe o la base de datos ha devuelto un error.');
        }
        if (Tools::isSubmit('statusjosraenviogratis_rule')) {
            $rule = new JosraEnvioGratisRule((int) Tools::getValue('id_rule'));
            if (Validate::isLoadedObject($rule)) {
                $rule->active = $rule->active ? 0 : 1;
                $this->normalizeDates($rule);
                if ($rule->update()) {
                    Tools::redirectAdmin($this->adminUrl(['jeg_tab' => 'rules', 'jeg_conf' => 3]));
                }
            }
            $errors[] = $this->l('No se ha podido cambiar el estado de la regla.');
        }
        if (Tools::isSubmit('jeg_duplicate')) {
            if ($this->duplicateRule((int) Tools::getValue('id_rule'))) {
                Tools::redirectAdmin($this->adminUrl(['jeg_tab' => 'rules', 'jeg_conf' => 7]));
            }
            $errors[] = $this->l('No se ha podido duplicar la regla.');
        }
        if (Tools::isSubmit('submitJegRule')) {
            $result = $this->processSaveRule();
            if ($result === true) {
                Tools::redirectAdmin($this->adminUrl(['jeg_tab' => 'rules', 'jeg_conf' => 1]));
            }
            $errors = array_merge($errors, $result);
        }

        // --- Ajustes
        if (Tools::isSubmit('submitJegDisplay')) {
            $this->processSaveDisplay();
            Tools::redirectAdmin($this->adminUrl(['jeg_tab' => 'display', 'jeg_conf' => 4]));
        }

        // --- Transportistas
        if (Tools::isSubmit('submitJegCarriers')) {
            $modes = JosraEnvioGratisCarriers::getModes();
            $posted = Tools::getValue('jeg_carrier', []);
            if (is_array($posted)) {
                foreach ($posted as $ref => $v) {
                    $modes[(int) $ref] = (int) $v ? 1 : 0;
                }
            }
            JosraEnvioGratisCarriers::saveModes($modes);
            JosraEnvioGratisCarriers::sync();
            Tools::redirectAdmin($this->adminUrl(['jeg_tab' => 'carriers', 'jeg_conf' => 5]
                + (Tools::getValue('jeg_cfilter') === 'all' ? ['jeg_cfilter' => 'all'] : [])));
        }
        if (Tools::isSubmit('submitJegReleaseAll')) {
            // Se guardan todos como "no gestionar" para que la autorreparación no los vuelva a coger.
            $modes = JosraEnvioGratisCarriers::getModes();
            foreach (JosraEnvioGratisCarriers::listCarriers(false) as $row) {
                $modes[(int) $row['id_reference']] = 0;
            }
            JosraEnvioGratisCarriers::saveModes($modes);
            JosraEnvioGratisCarriers::releaseAll();
            Tools::redirectAdmin($this->adminUrl(['jeg_tab' => 'carriers', 'jeg_conf' => 6]));
        }

        // --- Formulario de regla
        $showForm = Tools::isSubmit('addjosraenviogratis_rule') || Tools::isSubmit('updatejosraenviogratis_rule')
            || (Tools::isSubmit('submitJegRule') && $errors);

        $alerts = $this->buildAlerts($repair);

        if ($showForm) {
            $content = $this->renderRuleForm($errors ? true : false);
            $tab = 'rules';
        } else {
            switch ($tab) {
                case 'carriers':
                    $content = $this->renderCarriersTab();
                    break;
                case 'simulator':
                    $content = $this->renderSimulator();
                    break;
                case 'display':
                    $content = $this->renderDisplayForm();
                    break;
                case 'help':
                    $content = $this->fetchAdmin('help.tpl', [
                        'jeg_ps9' => JosraEnvioGratisEngine::isPs9(),
                        'jeg_ps_version' => _PS_VERSION_,
                    ]);
                    break;
                default:
                    $content = $this->renderRulesTab();
            }
        }

        $this->context->controller->addJqueryUI('ui.datepicker');
        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');

        $tabs = [
            'rules' => $this->l('Reglas'),
            'carriers' => $this->l('Transportistas'),
            'simulator' => $this->l('Simulador'),
            'display' => $this->l('Textos y visualización'),
            'help' => $this->l('Ayuda'),
        ];
        $tabLinks = [];
        foreach ($tabs as $k => $label) {
            $tabLinks[] = ['key' => $k, 'label' => $label, 'url' => $this->adminUrl(['jeg_tab' => $k])];
        }

        $js = '<script type="text/javascript">var jegAdmin = ' . json_encode([
            'ajaxUrl' => $this->adminUrl(['ajax' => 1, 'jeg_action' => 'searchProducts']),
            'txtSearch' => $this->l('Buscar…'),
            'txtNoResults' => $this->l('Sin resultados'),
            'txtRemove' => $this->l('Quitar'),
        ]) . ';</script>';

        $forms = $js . $this->fetchAdmin('configure.tpl', [
            'jeg_tabs' => $tabLinks,
            'jeg_active_tab' => $tab,
            'jeg_content' => $content,
            'jeg_errors' => $errors,
            'jeg_confirm' => $confirm,
            'jeg_alerts' => $alerts,
            'jeg_engine_label' => JosraEnvioGratisEngine::isPs9()
                ? sprintf($this->l('PrestaShop %s: el porte se ajusta con el hook del carrito y vale para todos los transportistas.'), _PS_VERSION_)
                : sprintf($this->l('PrestaShop %s: el porte se ajusta a través de los transportistas gestionados por el módulo (pestaña Transportistas).'), _PS_VERSION_),
        ]);

        // Panel de actualizaciones: arriba si hay versión nueva, abajo si no
        $info = $updater->getInfo();
        $panel = $updater->renderPanel();

        return !empty($info['update_available'])
            ? $out . $panel . $forms
            : $out . $forms . $panel;
    }

    protected function buildAlerts($repair)
    {
        $alerts = [];
        $activeRules = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'josraenviogratis_rule` WHERE `active` = 1'
        );

        if ($activeRules) {
            $rows = Db::getInstance()->executeS(
                'SELECT cr.`id_cart_rule`, cr.`code`, crl.`name`
                 FROM `' . _DB_PREFIX_ . 'cart_rule` cr
                 LEFT JOIN `' . _DB_PREFIX_ . 'cart_rule_lang` crl
                    ON (crl.`id_cart_rule` = cr.`id_cart_rule` AND crl.`id_lang` = ' . (int) $this->context->language->id . ')
                 WHERE cr.`free_shipping` = 1 AND cr.`active` = 1 AND cr.`quantity` > 0
                   AND cr.`date_to` >= \'' . pSQL(date('Y-m-d H:i:s')) . '\'
                 ORDER BY cr.`id_cart_rule` ASC'
            );
            if (is_array($rows) && $rows) {
                $names = [];
                foreach ($rows as $r) {
                    $names[] = '#' . (int) $r['id_cart_rule'] . ' ' . htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8')
                        . ($r['code'] ? ' (' . htmlspecialchars((string) $r['code'], ENT_QUOTES, 'UTF-8') . ')' : '');
                }
                $alerts[] = [
                    'type' => 'warning',
                    'text' => sprintf(
                        $this->l('Tienes %d regla(s) de carrito con envío gratis todavía activas: %s. Si estas reglas las sustituyen, desactívalas en Catálogo > Descuentos, o el cliente tendrá las dos cosas a la vez (y la línea de descuento seguirá saliendo en las facturas).'),
                        count($rows),
                        implode(', ', $names)
                    ),
                ];
            }
        }

        if (!JosraEnvioGratisEngine::isPs9()) {
            $managed = 0;
            foreach (JosraEnvioGratisCarriers::listCarriers(false) as $row) {
                if ($row['type'] === JosraEnvioGratisCarriers::TYPE_ADOPTED) {
                    ++$managed;
                }
            }
            $lost = JosraEnvioGratisCarriers::reclaimed();
            if ($lost) {
                $names = [];
                foreach ($lost as $l) {
                    $names[] = htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($l['module'], ENT_QUOTES, 'UTF-8') . ')';
                }
                $alerts[] = [
                    'type' => 'warning',
                    'text' => sprintf(
                        $this->l('Estos transportistas estaban gestionados por este módulo y su propio módulo los ha vuelto a configurar como suyos (normalmente al guardar su configuración de transportistas): %s. Las reglas ya no les afectan. Si quieres que vuelvan a tener portes gratis, avísame: no se recuperan solos para no pisar a ese módulo.'),
                        implode(', ', $names)
                    ),
                ];
            }
            if ($activeRules && !$managed) {
                $alerts[] = [
                    'type' => 'danger',
                    'text' => $this->l('Hay reglas activas pero ningún transportista gestionado por el módulo: en esta versión de PrestaShop las reglas no se aplicarán hasta que actives algún transportista en la pestaña Transportistas.'),
                ];
            }
            if ($repair['adopted'] || $repair['released']) {
                $alerts[] = [
                    'type' => 'info',
                    'text' => sprintf(
                        $this->l('Transportistas revisados al abrir la configuración: %d pasan a estar gestionados por el módulo y %d vuelven a su estado original.'),
                        (int) $repair['adopted'],
                        (int) $repair['released']
                    ),
                ];
            }
        } elseif ($repair['released']) {
            $alerts[] = [
                'type' => 'info',
                'text' => sprintf(
                    $this->l('En PrestaShop 9 no hace falta gestionar transportistas: %d transportista(s) de una versión anterior han vuelto a su estado original.'),
                    (int) $repair['released']
                ),
            ];
        }

        return $alerts;
    }

    /* ---------------------------- Listado de reglas */

    protected function renderRulesTab()
    {
        $idLang = (int) $this->context->language->id;
        $rows = Db::getInstance()->executeS(
            'SELECT r.*, rl.`title` FROM `' . _DB_PREFIX_ . 'josraenviogratis_rule` r
             LEFT JOIN `' . _DB_PREFIX_ . 'josraenviogratis_rule_lang` rl ON (rl.`id_rule` = r.`id_rule` AND rl.`id_lang` = ' . $idLang . ')
             ORDER BY r.`priority` ASC, r.`id_rule` ASC'
        );
        $list = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $rule = JosraEnvioGratisEngine::prepareRule($row);
                $list[] = [
                    'id_rule' => (int) $row['id_rule'],
                    'priority' => (int) $row['priority'],
                    'name' => $row['name'],
                    'effect_label' => $this->effectLabel($rule),
                    'title' => (string) $row['title'],
                    'summary' => $this->ruleSummary($rule),
                    'active' => (int) $row['active'],
                ];
            }
        }

        $fields = [
            'priority' => ['title' => $this->l('Prioridad'), 'class' => 'fixed-width-xs', 'align' => 'center', 'search' => false, 'orderby' => false],
            'name' => ['title' => $this->l('Nombre interno'), 'search' => false, 'orderby' => false],
            'effect_label' => ['title' => $this->l('Efecto'), 'search' => false, 'orderby' => false],
            'title' => ['title' => $this->l('Texto que ve el cliente'), 'search' => false, 'orderby' => false],
            'summary' => ['title' => $this->l('Condiciones'), 'search' => false, 'orderby' => false],
            'active' => ['title' => $this->l('Activa'), 'type' => 'bool', 'active' => 'status', 'align' => 'center', 'class' => 'fixed-width-sm', 'search' => false, 'orderby' => false],
        ];

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->identifier = 'id_rule';
        $helper->actions = ['edit', 'duplicate', 'delete'];
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->title = $this->l('Reglas (se aplica la primera que se cumple, por prioridad)');
        $helper->table = 'josraenviogratis_rule';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->adminUrl(['jeg_tab' => 'rules'], false);
        $helper->listTotal = count($list);
        $helper->no_link = true;

        return $this->fetchAdmin('rules_header.tpl', [
            'jeg_add_url' => $this->adminUrl(['jeg_tab' => 'rules', 'addjosraenviogratis_rule' => 1]),
            'jeg_count' => count($list),
        ]) . $helper->generateList($list, $fields);
    }

    /**
     * Acción "Duplicar" del listado (HelperList llama a displayDuplicateLink).
     */
    public function displayDuplicateLink($token, $id, $name = null)
    {
        $url = $this->adminUrl(['jeg_tab' => 'rules', 'jeg_duplicate' => 1, 'id_rule' => (int) $id]);

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($this->l('Duplicar'), ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="icon-copy"></i> ' . htmlspecialchars($this->l('Duplicar'), ENT_QUOTES, 'UTF-8') . '</a>';
    }

    protected function duplicateRule($idRule)
    {
        $src = new JosraEnvioGratisRule((int) $idRule);
        if (!Validate::isLoadedObject($src)) {
            return false;
        }
        $copy = new JosraEnvioGratisRule();
        foreach (array_keys(JosraEnvioGratisRule::$definition['fields']) as $field) {
            if (in_array($field, ['date_add', 'date_upd'])) {
                continue;
            }
            $copy->{$field} = $src->{$field};
        }
        $copy->name = Tools::substr($src->name . ' ' . $this->l('(copia)'), 0, 128);
        $copy->active = 0;
        $this->normalizeDates($copy);

        return (bool) $copy->add();
    }

    protected function effectLabel($rule)
    {
        $e = (int) $rule['effect'];
        $cur = Currency::getDefaultCurrency();
        if ($e === JosraEnvioGratisRule::EFFECT_FIXED) {
            return sprintf($this->l('Porte a %s (sin IVA)'), JosraEnvioGratisEngine::formatPrice($rule['effect_value'], $cur->id));
        }
        if ($e === JosraEnvioGratisRule::EFFECT_PERCENT) {
            return sprintf($this->l('%s %% menos de porte'), rtrim(rtrim(number_format((float) $rule['effect_value'], 2, ',', ''), '0'), ','));
        }

        return $this->l('Portes gratis');
    }

    protected function ruleSummary($rule)
    {
        $cur = Currency::getDefaultCurrency();
        $parts = [];
        if ((float) $rule['amount_min'] > 0 || (float) $rule['amount_max'] > 0) {
            $base = ((int) $rule['amount_tax'] ? $this->l('con IVA') : $this->l('sin IVA'))
                . ', ' . ((int) $rule['amount_shipping'] ? $this->l('productos + portes') : $this->l('solo productos'))
                . ', ' . ((int) $rule['amount_discounts'] ? $this->l('después de cupones') : $this->l('antes de cupones'));
            $range = [];
            if ((float) $rule['amount_min'] > 0) {
                $range[] = $this->l('desde') . ' ' . JosraEnvioGratisEngine::formatPrice($rule['amount_min'], $cur->id);
            }
            if ((float) $rule['amount_max'] > 0) {
                $range[] = $this->l('hasta') . ' ' . JosraEnvioGratisEngine::formatPrice($rule['amount_max'], $cur->id);
            }
            $parts[] = $this->l('Importe') . ' ' . implode(' ', $range) . ' (' . $base . ')';
        }
        if ((int) $rule['qty_min'] || (int) $rule['qty_max']) {
            $parts[] = $this->l('Unidades') . ' ' . ((int) $rule['qty_min'] ? '≥' . (int) $rule['qty_min'] : '')
                . ((int) $rule['qty_max'] ? ' ≤' . (int) $rule['qty_max'] : '');
        }
        if ((int) $rule['lines_min']) {
            $parts[] = $this->l('Productos distintos') . ' ≥' . (int) $rule['lines_min'];
        }
        if ((float) $rule['weight_min'] > 0 || (float) $rule['weight_max'] > 0) {
            $parts[] = $this->l('Peso') . ' ' . ((float) $rule['weight_min'] > 0 ? '≥' . (float) $rule['weight_min'] : '')
                . ((float) $rule['weight_max'] > 0 ? ' ≤' . (float) $rule['weight_max'] : '') . ' ' . Configuration::get('PS_WEIGHT_UNIT');
        }
        if ($rule['_has_trig']) {
            $parts[] = ((int) $rule['trig_mode'] ? $this->l('Todos los productos de la lista') : $this->l('Lleva productos de la lista'))
                . ((int) $rule['trig_min_qty'] > 1 ? ' (≥' . (int) $rule['trig_min_qty'] . ' uds.)' : '');
        }
        if ($rule['_has_excl']) {
            $parts[] = (int) $rule['excl_mode'] ? $this->l('Con productos que no cuentan') : $this->l('Con productos que la anulan');
        }
        $loc = [];
        if ($rule['_zones']) {
            $loc[] = count($rule['_zones']) . ' ' . $this->l('zona(s)');
        }
        if ($rule['_countries']) {
            $loc[] = count($rule['_countries']) . ' ' . $this->l('país(es)');
        }
        if ($rule['_states']) {
            $loc[] = count($rule['_states']) . ' ' . $this->l('provincia(s)');
        }
        if ($loc) {
            $parts[] = implode(', ', $loc);
        }
        if ($rule['_carriers']) {
            $parts[] = count($rule['_carriers']) . ' ' . $this->l('transportista(s)');
        }
        if ($rule['_groups'] || (int) $rule['customer_type'] || (int) $rule['first_order']) {
            $parts[] = $this->l('Restringida por cliente');
        }
        if (!empty($rule['date_from']) || !empty($rule['date_to']) || $rule['_days'] || $rule['time_from'] || $rule['time_to']) {
            $parts[] = $this->l('Con fechas u horario');
        }

        return $parts ? implode(' · ', $parts) : $this->l('Sin condiciones: se aplica siempre');
    }

    /* ---------------------------- Formulario de regla */

    protected function getDayOptions()
    {
        return [
            ['id' => 1, 'name' => $this->l('Lunes')],
            ['id' => 2, 'name' => $this->l('Martes')],
            ['id' => 3, 'name' => $this->l('Miércoles')],
            ['id' => 4, 'name' => $this->l('Jueves')],
            ['id' => 5, 'name' => $this->l('Viernes')],
            ['id' => 6, 'name' => $this->l('Sábado')],
            ['id' => 7, 'name' => $this->l('Domingo')],
        ];
    }

    /**
     * Transportistas para elegir en una regla: solo los activos, más los desactivados que la regla ya
     * tenga elegidos (para no perderlos al guardar).
     */
    protected function getCarrierOptions($selected = [])
    {
        $ps9 = JosraEnvioGratisEngine::isPs9();
        $out = [];
        $seen = [];
        foreach (JosraEnvioGratisCarriers::listCarriers(false) as $row) {
            $ref = (int) $row['id_reference'] ?: (int) $row['id_carrier'];
            if (isset($seen[$ref])) {
                continue;
            }
            if (!(int) $row['active'] && !in_array($ref, $selected)) {
                continue;
            }
            $seen[$ref] = true;
            $label = $row['name'] . ' (ref. ' . $ref . ')';
            if (!(int) $row['active']) {
                $label .= ' — ' . $this->l('desactivado');
            }
            if (!$ps9 && $row['type'] !== JosraEnvioGratisCarriers::TYPE_ADOPTED) {
                if ($row['type'] === JosraEnvioGratisCarriers::TYPE_MODULE_EXTERNAL) {
                    $label .= ' — ' . sprintf($this->l('calculado por %s: solo PS 9'), $row['external_module_name']);
                } else {
                    $label .= ' — ' . $this->l('no gestionado: actívalo en la pestaña Transportistas');
                }
            }
            $out[] = ['id' => $ref, 'name' => $label];
        }

        return $out;
    }

    protected function getZoneOptions()
    {
        $rows = Db::getInstance()->executeS('SELECT `id_zone` AS id, `name` FROM `' . _DB_PREFIX_ . 'zone` WHERE `active` = 1 ORDER BY `name`');

        return is_array($rows) ? $rows : [];
    }

    protected function getCountryOptions()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT c.`id_country` AS id, cl.`name` FROM `' . _DB_PREFIX_ . 'country` c
             INNER JOIN `' . _DB_PREFIX_ . 'country_lang` cl ON (cl.`id_country` = c.`id_country` AND cl.`id_lang` = ' . (int) $this->context->language->id . ')
             WHERE c.`active` = 1 ORDER BY cl.`name`'
        );

        return is_array($rows) ? $rows : [];
    }

    protected function getStateOptions()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT s.`id_state` AS id, CONCAT(cl.`name`, \' — \', s.`name`) AS name
             FROM `' . _DB_PREFIX_ . 'state` s
             INNER JOIN `' . _DB_PREFIX_ . 'country` c ON (c.`id_country` = s.`id_country` AND c.`active` = 1)
             INNER JOIN `' . _DB_PREFIX_ . 'country_lang` cl ON (cl.`id_country` = c.`id_country` AND cl.`id_lang` = ' . (int) $this->context->language->id . ')
             WHERE s.`active` = 1 ORDER BY cl.`name`, s.`name`'
        );

        return is_array($rows) ? $rows : [];
    }

    protected function getGroupOptions()
    {
        $out = [];
        foreach (Group::getGroups((int) $this->context->language->id) as $g) {
            $out[] = ['id' => (int) $g['id_group'], 'name' => $g['name']];
        }

        return $out;
    }

    protected function getCategoryOptions()
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $memo = $this->getCategoryOptionsRaw();

        return $memo;
    }

    protected function getCategoryOptionsRaw()
    {
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $rows = Db::getInstance()->executeS(
            'SELECT c.`id_category`, c.`id_parent`, cl.`name`
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON (cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop . ')
             WHERE c.`id_parent` > 0
             ORDER BY c.`nleft` ASC'
        );
        if (!is_array($rows)) {
            return [];
        }
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id_category']] = $r;
        }
        $out = [];
        foreach ($rows as $r) {
            $path = [];
            $id = (int) $r['id_category'];
            $guard = 0;
            while ($id && isset($byId[$id]) && $guard++ < 20) {
                array_unshift($path, $byId[$id]['name']);
                $id = (int) $byId[$id]['id_parent'];
            }
            $out[] = ['id' => (int) $r['id_category'], 'name' => implode(' > ', $path)];
        }

        return $out;
    }

    protected function getManufacturerOptions()
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $memo = $this->getManufacturerOptionsRaw();

        return $memo;
    }

    protected function getManufacturerOptionsRaw()
    {
        $rows = Db::getInstance()->executeS('SELECT `id_manufacturer` AS id, `name` FROM `' . _DB_PREFIX_ . 'manufacturer` ORDER BY `name`');

        return is_array($rows) ? $rows : [];
    }

    protected function getSupplierOptions()
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $memo = $this->getSupplierOptionsRaw();

        return $memo;
    }

    protected function getSupplierOptionsRaw()
    {
        $rows = Db::getInstance()->executeS('SELECT `id_supplier` AS id, `name` FROM `' . _DB_PREFIX_ . 'supplier` ORDER BY `name`');

        return is_array($rows) ? $rows : [];
    }

    protected function getShopOptions()
    {
        $out = [];
        foreach (Shop::getShops(false) as $s) {
            $out[] = ['id' => (int) $s['id_shop'], 'name' => $s['name']];
        }

        return $out;
    }

    protected function getCurrencyOptions()
    {
        $rows = Db::getInstance()->executeS('SELECT `id_currency` AS id, `iso_code` AS name FROM `' . _DB_PREFIX_ . 'currency` WHERE `deleted` = 0 ORDER BY `iso_code`');

        return is_array($rows) ? $rows : [];
    }

    protected function getProductsByIds($ids)
    {
        $ids = JosraEnvioGratisEngine::csv(implode(',', (array) $ids));
        if (!$ids) {
            return [];
        }
        $rows = Db::getInstance()->executeS(
            'SELECT p.`id_product`, p.`reference`, pl.`name`
             FROM `' . _DB_PREFIX_ . 'product` p
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.`id_product` = p.`id_product`
                AND pl.`id_lang` = ' . (int) $this->context->language->id . ' AND pl.`id_shop` = ' . (int) $this->context->shop->id . ')
             WHERE p.`id_product` IN (' . implode(',', $ids) . ')'
        );
        $byId = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $byId[(int) $r['id_product']] = $r;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'id' => $id,
                'label' => isset($byId[$id])
                    ? ('#' . $id . ' ' . $byId[$id]['name'] . ($byId[$id]['reference'] ? ' (' . $byId[$id]['reference'] . ')' : ''))
                    : ('#' . $id . ' ' . $this->l('(producto borrado)')),
            ];
        }

        return $out;
    }

    protected function ajaxSearchProducts()
    {
        $q = trim((string) Tools::getValue('q'));
        $out = [];
        if (Tools::strlen($q) >= 2 || ctype_digit($q)) {
            $like = '%' . pSQL($q) . '%';
            $rows = Db::getInstance()->executeS(
                'SELECT p.`id_product`, p.`reference`, pl.`name`
                 FROM `' . _DB_PREFIX_ . 'product` p
                 INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.`id_product` = p.`id_product`
                    AND pl.`id_lang` = ' . (int) $this->context->language->id . ' AND pl.`id_shop` = ' . (int) $this->context->shop->id . ')
                 WHERE pl.`name` LIKE \'' . $like . '\' OR p.`reference` LIKE \'' . $like . '\''
                    . (ctype_digit($q) ? ' OR p.`id_product` = ' . (int) $q : '') . '
                 ORDER BY pl.`name` ASC LIMIT 30'
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $out[] = [
                        'id' => (int) $r['id_product'],
                        'label' => '#' . (int) $r['id_product'] . ' ' . $r['name'] . ($r['reference'] ? ' (' . $r['reference'] . ')' : ''),
                    ];
                }
            }
        }
        if (ob_get_level()) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['results' => $out]));
    }

    protected function productPicker($name, $selected)
    {
        return $this->fetchAdmin('product_picker.tpl', [
            'jeg_picker_name' => $name,
            'jeg_picker_items' => $this->getProductsByIds($selected),
        ]);
    }

    protected function ruleFromPost(JosraEnvioGratisRule $rule)
    {
        $num = function ($key) {
            return (float) str_replace(',', '.', (string) Tools::getValue($key, 0));
        };
        $list = function ($key) {
            $v = Tools::getValue($key, []);

            return implode(',', JosraEnvioGratisEngine::csv(is_array($v) ? implode(',', $v) : (string) $v));
        };

        $rule->name = trim((string) Tools::getValue('name'));
        $rule->active = (int) Tools::getValue('active') ? 1 : 0;
        $rule->priority = (int) Tools::getValue('priority', 1);
        $rule->effect = (int) Tools::getValue('effect');
        $rule->effect_value = $num('effect_value');
        $rule->show_badge = (int) Tools::getValue('show_badge') ? 1 : 0;

        $rule->amount_min = $num('amount_min');
        $rule->amount_max = $num('amount_max');
        $rule->amount_tax = (int) Tools::getValue('amount_tax') ? 1 : 0;
        $rule->amount_shipping = (int) Tools::getValue('amount_shipping') ? 1 : 0;
        $rule->amount_discounts = (int) Tools::getValue('amount_discounts') ? 1 : 0;

        $rule->qty_min = max(0, (int) Tools::getValue('qty_min'));
        $rule->qty_max = max(0, (int) Tools::getValue('qty_max'));
        $rule->lines_min = max(0, (int) Tools::getValue('lines_min'));
        $rule->weight_min = $num('weight_min');
        $rule->weight_max = $num('weight_max');

        $rule->trig_products = $list('trig_products');
        $rule->trig_categories = $list('trig_categories_selected');
        $rule->trig_manufacturers = $list('trig_manufacturers_selected');
        $rule->trig_suppliers = $list('trig_suppliers_selected');
        $rule->trig_mode = (int) Tools::getValue('trig_mode') ? 1 : 0;
        $rule->trig_min_qty = max(0, (int) Tools::getValue('trig_min_qty'));

        $rule->excl_products = $list('excl_products');
        $rule->excl_categories = $list('excl_categories_selected');
        $rule->excl_manufacturers = $list('excl_manufacturers_selected');
        $rule->excl_suppliers = $list('excl_suppliers_selected');
        $rule->excl_mode = (int) Tools::getValue('excl_mode') ? 1 : 0;

        $rule->carriers = $list('carriers_selected');
        $rule->zones = $list('zones_selected');
        $rule->countries = $list('countries_selected');
        $rule->states = $list('states_selected');

        $rule->groups = $list('groups_selected');
        $rule->customer_type = (int) Tools::getValue('customer_type');
        $rule->first_order = (int) Tools::getValue('first_order') ? 1 : 0;

        $rule->date_from = $this->parseDate(Tools::getValue('date_from'), false);
        $rule->date_to = $this->parseDate(Tools::getValue('date_to'), true);
        $rule->days = $list('days');
        $rule->time_from = trim((string) Tools::getValue('time_from'));
        $rule->time_to = trim((string) Tools::getValue('time_to'));
        $rule->shops = $list('shops_selected');
        $rule->currencies = $list('currencies_selected');

        $titles = [];
        foreach (Language::getLanguages(false) as $lang) {
            $titles[(int) $lang['id_lang']] = trim((string) Tools::getValue('title_' . (int) $lang['id_lang']));
        }
        $rule->title = $titles;
    }

    /**
     * "dd/mm/aaaa" -> "aaaa-mm-dd 00:00:00" (o 23:59:59 para la fecha final). Vacío -> null.
     *
     * @return string|null|false false = formato no válido
     */
    protected function parseDate($value, $endOfDay)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d %s', $m[3], $m[2], $m[1], $endOfDay ? '23:59:59' : '00:00:00');
        }

        return false;
    }

    protected function formatDate($value)
    {
        if (!$value || strpos($value, '0000-00-00') === 0) {
            return '';
        }
        $ts = strtotime($value);

        return $ts ? date('d/m/Y', $ts) : '';
    }

    protected function normalizeDates(JosraEnvioGratisRule $rule)
    {
        foreach (['date_from', 'date_to'] as $f) {
            if (!$rule->{$f} || strpos((string) $rule->{$f}, '0000-00-00') === 0) {
                $rule->{$f} = null;
            }
        }
    }

    /**
     * @return true|array errores
     */
    protected function processSaveRule()
    {
        $idRule = (int) Tools::getValue('id_rule');
        $rule = new JosraEnvioGratisRule($idRule ?: null);
        if ($idRule && !Validate::isLoadedObject($rule)) {
            return [$this->l('La regla que intentas guardar ya no existe (puede que se haya borrado en otra pestaña).')];
        }
        $this->ruleFromPost($rule);
        $errors = [];

        if ($rule->name === '') {
            $errors[] = $this->l('Falta el nombre interno de la regla: sirve para reconocerla en el listado y en los pedidos.');
        } elseif (!Validate::isGenericName($rule->name)) {
            $errors[] = $this->l('El nombre interno tiene caracteres no permitidos (< > = { }).');
        }
        if (!in_array((int) $rule->effect, [0, 1, 2])) {
            $errors[] = $this->l('El efecto elegido no es válido.');
        }
        if ((int) $rule->effect === JosraEnvioGratisRule::EFFECT_PERCENT && ($rule->effect_value <= 0 || $rule->effect_value > 100)) {
            $errors[] = $this->l('El porcentaje de rebaja del porte tiene que estar entre 0,01 y 100.');
        }
        if ((int) $rule->effect === JosraEnvioGratisRule::EFFECT_FIXED && $rule->effect_value < 0) {
            $errors[] = $this->l('El precio fijo del porte no puede ser negativo.');
        }
        foreach (['amount_min' => $this->l('importe mínimo'), 'amount_max' => $this->l('importe máximo'),
            'weight_min' => $this->l('peso mínimo'), 'weight_max' => $this->l('peso máximo'), ] as $f => $label) {
            if ($rule->{$f} < 0) {
                $errors[] = sprintf($this->l('El %s no puede ser negativo.'), $label);
            }
        }
        if ($rule->amount_max > 0 && $rule->amount_min > $rule->amount_max) {
            $errors[] = $this->l('El importe mínimo es mayor que el máximo: la regla no se cumpliría nunca.');
        }
        if ($rule->weight_max > 0 && $rule->weight_min > $rule->weight_max) {
            $errors[] = $this->l('El peso mínimo es mayor que el máximo: la regla no se cumpliría nunca.');
        }
        if ($rule->qty_max > 0 && $rule->qty_min > $rule->qty_max) {
            $errors[] = $this->l('Las unidades mínimas son más que las máximas: la regla no se cumpliría nunca.');
        }
        if ($rule->date_from === false) {
            $errors[] = $this->l('La fecha de inicio no tiene el formato dd/mm/aaaa.');
        }
        if ($rule->date_to === false) {
            $errors[] = $this->l('La fecha de fin no tiene el formato dd/mm/aaaa.');
        }
        if ($rule->date_from && $rule->date_to && $rule->date_from > $rule->date_to) {
            $errors[] = $this->l('La fecha de inicio es posterior a la de fin.');
        }
        foreach (['time_from' => $this->l('hora de inicio'), 'time_to' => $this->l('hora de fin')] as $f => $label) {
            if ($rule->{$f} !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $rule->{$f})) {
                $errors[] = sprintf($this->l('La %s debe tener el formato HH:MM (por ejemplo 09:30).'), $label);
            }
        }
        foreach ($rule->title as $t) {
            if (Tools::strlen($t) > 255) {
                $errors[] = $this->l('El texto que ve el cliente no puede pasar de 255 caracteres.');
                break;
            }
        }

        if ($errors) {
            $this->postedRule = $rule;

            return $errors;
        }

        $saved = $rule->id ? $rule->update() : $rule->add();
        if (!$saved) {
            return [$this->l('La base de datos no ha aceptado la regla. Revisa que las tablas del módulo existen (abrir la configuración las repara) y vuelve a intentarlo.')];
        }
        JosraEnvioGratisEngine::resetCache();

        return true;
    }

    /** @var JosraEnvioGratisRule|null regla con los datos enviados cuando hay errores */
    protected $postedRule = null;

    protected function renderRuleForm($fromPost)
    {
        if ($fromPost && $this->postedRule) {
            $rule = $this->postedRule;
        } else {
            $rule = new JosraEnvioGratisRule((int) Tools::getValue('id_rule') ?: null);
            if (!Validate::isLoadedObject($rule)) {
                $rule = new JosraEnvioGratisRule();
                $rule->active = 1;
                $rule->priority = (int) Db::getInstance()->getValue(
                    'SELECT IFNULL(MAX(`priority`), 0) + 1 FROM `' . _DB_PREFIX_ . 'josraenviogratis_rule`'
                );
                $rule->amount_tax = 1;
                $rule->amount_discounts = 1;
            }
        }

        $cur = Currency::getDefaultCurrency();
        $sign = $cur->sign ? $cur->sign : $cur->iso_code;
        $weightUnit = Configuration::get('PS_WEIGHT_UNIT');
        $yesNo = [
            ['id' => 'on', 'value' => 1, 'label' => $this->l('Sí')],
            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
        ];
        $multishop = Shop::isFeatureActive();
        $multiCurrency = count($this->getCurrencyOptions()) > 1;

        $forms = [];

        $forms[] = ['form' => [
            'legend' => ['title' => $this->l('General'), 'icon' => 'icon-cog'],
            'input' => [
                ['type' => 'text', 'name' => 'name', 'label' => $this->l('Nombre interno'), 'required' => true, 'col' => 6,
                    'desc' => $this->l('Solo para ti: sale en el listado, en el simulador y en el pedido.'), ],
                ['type' => 'text', 'name' => 'title', 'label' => $this->l('Texto que ve el cliente'), 'lang' => true, 'col' => 9,
                    'desc' => $this->l('ATENCIÓN: este es el título que verá el cliente en el carrito mientras no sepamos su dirección (por ejemplo "Portes gratis a partir de 50 € en Península"). Déjalo vacío si la regla no debe anunciarse.'), ],
                ['type' => 'switch', 'name' => 'active', 'label' => $this->l('Activa'), 'is_bool' => true, 'values' => $yesNo],
                ['type' => 'text', 'name' => 'priority', 'label' => $this->l('Prioridad'), 'class' => 'fixed-width-sm',
                    'desc' => $this->l('Se revisan de menor a mayor número y se aplica la primera que se cumple. Las demás se ignoran para ese transportista.'), ],
                ['type' => 'select', 'name' => 'effect', 'label' => $this->l('Efecto'), 'options' => ['query' => [
                    ['id' => 0, 'name' => $this->l('Portes gratis')],
                    ['id' => 1, 'name' => $this->l('Porte a precio fijo')],
                    ['id' => 2, 'name' => $this->l('Porcentaje de rebaja del porte')],
                ], 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'text', 'name' => 'effect_value', 'label' => $this->l('Valor'), 'class' => 'fixed-width-md jeg-effect-value',
                    'desc' => sprintf($this->l('Precio fijo en %s sin IVA (como las tarifas de PrestaShop; si el porte normal es más barato se cobra el normal) o porcentaje de 0 a 100. No se usa con "Portes gratis".'), $sign), ],
                ['type' => 'switch', 'name' => 'show_badge', 'label' => $this->l('Etiqueta en la ficha'), 'is_bool' => true, 'values' => $yesNo,
                    'desc' => $this->l('Muestra "Portes gratis con este producto" en la ficha de los productos que activan esta regla (solo si el efecto es portes gratis y basta con una unidad). Actívalo solo si ese producto, por sí mismo, ya da portes gratis.'), ],
            ],
        ]];

        $forms[] = ['form' => [
            'legend' => ['title' => $this->l('Importe y cantidades'), 'icon' => 'icon-money'],
            'description' => $this->l('Deja a 0 lo que no quieras usar.'),
            'input' => [
                ['type' => 'text', 'name' => 'amount_min', 'label' => $this->l('Importe mínimo'), 'suffix' => $sign, 'class' => 'fixed-width-md'],
                ['type' => 'text', 'name' => 'amount_max', 'label' => $this->l('Importe máximo'), 'suffix' => $sign, 'class' => 'fixed-width-md',
                    'desc' => $this->l('Los importes están en la moneda por defecto; con otras monedas se convierten.'), ],
                ['type' => 'select', 'name' => 'amount_tax', 'label' => $this->l('Importe'), 'options' => ['query' => [
                    ['id' => 1, 'name' => $this->l('Con IVA')], ['id' => 0, 'name' => $this->l('Sin IVA')],
                ], 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'select', 'name' => 'amount_shipping', 'label' => $this->l('Qué se suma'), 'options' => ['query' => [
                    ['id' => 0, 'name' => $this->l('Solo productos')], ['id' => 1, 'name' => $this->l('Productos + portes')],
                ], 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'select', 'name' => 'amount_discounts', 'label' => $this->l('Cupones'), 'options' => ['query' => [
                    ['id' => 1, 'name' => $this->l('Después de restar los cupones')], ['id' => 0, 'name' => $this->l('Antes de cupones')],
                ], 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'text', 'name' => 'qty_min', 'label' => $this->l('Unidades mínimas'), 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'name' => 'qty_max', 'label' => $this->l('Unidades máximas'), 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'name' => 'lines_min', 'label' => $this->l('Productos distintos mínimos'), 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'name' => 'weight_min', 'label' => $this->l('Peso mínimo'), 'suffix' => $weightUnit, 'class' => 'fixed-width-md'],
                ['type' => 'text', 'name' => 'weight_max', 'label' => $this->l('Peso máximo'), 'suffix' => $weightUnit, 'class' => 'fixed-width-md'],
            ],
        ]];

        $forms[] = ['form' => [
            'legend' => ['title' => $this->l('Productos'), 'icon' => 'icon-tags'],
            'description' => $this->l('Productos que activan la regla. Si no eliges nada, no se mira. Un producto cuenta si está en la lista, en una de las categorías, o es de una de las marcas o proveedores.'),
            'input' => [
                ['type' => 'html', 'name' => 'trig_products_html', 'label' => $this->l('Productos'),
                    'html_content' => $this->productPicker('trig_products', JosraEnvioGratisEngine::csv($rule->trig_products)), ],
                ['type' => 'swap', 'name' => 'trig_categories', 'label' => $this->l('Categorías'), 'size' => 7,
                    'options' => ['query' => $this->getCategoryOptions(), 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'swap', 'name' => 'trig_manufacturers', 'label' => $this->l('Marcas'), 'size' => 6,
                    'options' => ['query' => $this->getManufacturerOptions(), 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'swap', 'name' => 'trig_suppliers', 'label' => $this->l('Proveedores'), 'size' => 6,
                    'options' => ['query' => $this->getSupplierOptions(), 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'select', 'name' => 'trig_mode', 'label' => $this->l('Cómo se cumple'), 'options' => ['query' => [
                    ['id' => 0, 'name' => $this->l('El carrito lleva alguno de estos productos')],
                    ['id' => 1, 'name' => $this->l('Todos los productos del carrito son de estos')],
                ], 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'text', 'name' => 'trig_min_qty', 'label' => $this->l('Unidades mínimas de estos productos'), 'class' => 'fixed-width-sm',
                    'desc' => $this->l('0 o 1 = basta con una unidad.'), ],
            ],
        ]];

        $forms[] = ['form' => [
            'legend' => ['title' => $this->l('Exclusiones'), 'icon' => 'icon-ban'],
            'description' => $this->l('Productos que anulan la regla o que no cuentan para el importe. Si no eliges nada, no se mira.'),
            'input' => [
                ['type' => 'html', 'name' => 'excl_products_html', 'label' => $this->l('Productos'),
                    'html_content' => $this->productPicker('excl_products', JosraEnvioGratisEngine::csv($rule->excl_products)), ],
                ['type' => 'swap', 'name' => 'excl_categories', 'label' => $this->l('Categorías'), 'size' => 7,
                    'options' => ['query' => $this->getCategoryOptions(), 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'swap', 'name' => 'excl_manufacturers', 'label' => $this->l('Marcas'), 'size' => 6,
                    'options' => ['query' => $this->getManufacturerOptions(), 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'swap', 'name' => 'excl_suppliers', 'label' => $this->l('Proveedores'), 'size' => 6,
                    'options' => ['query' => $this->getSupplierOptions(), 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'select', 'name' => 'excl_mode', 'label' => $this->l('Qué pasa si los lleva'), 'options' => ['query' => [
                    ['id' => 0, 'name' => $this->l('La regla no se aplica (por ejemplo, productos voluminosos)')],
                    ['id' => 1, 'name' => $this->l('Se aplica, pero esos productos no cuentan para el importe ni las unidades')],
                ], 'id' => 'id', 'name' => 'name'], ],
            ],
        ]];

        $forms[] = ['form' => [
            'legend' => ['title' => $this->l('Destino y transporte'), 'icon' => 'icon-globe'],
            'description' => $this->l('Vacío = todos. Si eliges varias cosas tienen que cumplirse todas (por ejemplo, zona Europa y provincia Madrid).'),
            'input' => [
                ['type' => 'swap', 'name' => 'carriers', 'label' => $this->l('Transportistas'), 'size' => 6,
                    'options' => ['query' => $this->getCarrierOptions(JosraEnvioGratisEngine::csv($rule->carriers)), 'id' => 'id', 'name' => 'name'],
                    'desc' => $this->l('Solo salen los transportistas activos. Se guardan por referencia: si editas el transportista en PrestaShop la regla lo sigue reconociendo.'), ],
                ['type' => 'swap', 'name' => 'zones', 'label' => $this->l('Zonas'), 'size' => 6,
                    'options' => ['query' => $this->getZoneOptions(), 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'swap', 'name' => 'countries', 'label' => $this->l('Países'), 'size' => 7,
                    'options' => ['query' => $this->getCountryOptions(), 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'swap', 'name' => 'states', 'label' => $this->l('Provincias'), 'size' => 7,
                    'options' => ['query' => $this->getStateOptions(), 'id' => 'id', 'name' => 'name'], ],
            ],
        ]];

        $forms[] = ['form' => [
            'legend' => ['title' => $this->l('Clientes'), 'icon' => 'icon-user'],
            'input' => [
                ['type' => 'swap', 'name' => 'groups', 'label' => $this->l('Grupos de clientes'), 'size' => 6,
                    'options' => ['query' => $this->getGroupOptions(), 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'select', 'name' => 'customer_type', 'label' => $this->l('Tipo de cliente'), 'options' => ['query' => [
                    ['id' => 0, 'name' => $this->l('Todos')],
                    ['id' => 1, 'name' => $this->l('Solo particulares')],
                    ['id' => 2, 'name' => $this->l('Solo empresas (dirección con empresa y NIF-IVA)')],
                ], 'id' => 'id', 'name' => 'name'], ],
                ['type' => 'switch', 'name' => 'first_order', 'label' => $this->l('Solo primer pedido'), 'is_bool' => true, 'values' => $yesNo,
                    'desc' => $this->l('Solo si el cliente no tiene ningún pedido válido anterior.'), ],
            ],
        ]];

        $timeInputs = [
            ['type' => 'text', 'name' => 'date_from', 'label' => $this->l('Desde'), 'class' => 'jeg-datepicker fixed-width-md', 'desc' => $this->l('dd/mm/aaaa. Vacío = sin inicio.')],
            ['type' => 'text', 'name' => 'date_to', 'label' => $this->l('Hasta'), 'class' => 'jeg-datepicker fixed-width-md', 'desc' => $this->l('dd/mm/aaaa, incluido. Vacío = sin fin.')],
            ['type' => 'select', 'name' => 'days[]', 'label' => $this->l('Días de la semana'), 'multiple' => true, 'size' => 7,
                'options' => ['query' => $this->getDayOptions(), 'id' => 'id', 'name' => 'name'],
                'desc' => $this->l('Ninguno marcado = todos los días. Ctrl/Cmd + clic para marcar varios.'), ],
            ['type' => 'text', 'name' => 'time_from', 'label' => $this->l('Hora desde'), 'class' => 'fixed-width-sm', 'desc' => $this->l('HH:MM. Hora de la tienda.')],
            ['type' => 'text', 'name' => 'time_to', 'label' => $this->l('Hora hasta'), 'class' => 'fixed-width-sm'],
        ];
        if ($multishop) {
            $timeInputs[] = ['type' => 'swap', 'name' => 'shops', 'label' => $this->l('Tiendas'), 'size' => 5,
                'options' => ['query' => $this->getShopOptions(), 'id' => 'id', 'name' => 'name'], ];
        }
        if ($multiCurrency) {
            $timeInputs[] = ['type' => 'swap', 'name' => 'currencies', 'label' => $this->l('Monedas'), 'size' => 5,
                'options' => ['query' => $this->getCurrencyOptions(), 'id' => 'id', 'name' => 'name'], ];
        }
        $forms[] = ['form' => [
            'legend' => ['title' => $this->l('Fechas y horario'), 'icon' => 'icon-calendar'],
            'input' => $timeInputs,
            'submit' => ['title' => $this->l('Guardar'), 'name' => 'submitJegRule'],
            'buttons' => [[
                'href' => $this->adminUrl(['jeg_tab' => 'rules']),
                'title' => $this->l('Volver al listado'),
                'icon' => 'process-icon-back',
            ]],
        ]];

        // Valores
        $values = [
            'id_rule' => (int) $rule->id,
            'name' => $rule->name,
            'active' => (int) $rule->active,
            'priority' => (int) $rule->priority,
            'effect' => (int) $rule->effect,
            'effect_value' => (float) $rule->effect_value ? $this->num($rule->effect_value) : '',
            'show_badge' => (int) $rule->show_badge,
            'amount_min' => $this->num($rule->amount_min),
            'amount_max' => $this->num($rule->amount_max),
            'amount_tax' => (int) $rule->amount_tax,
            'amount_shipping' => (int) $rule->amount_shipping,
            'amount_discounts' => (int) $rule->amount_discounts,
            'qty_min' => (int) $rule->qty_min,
            'qty_max' => (int) $rule->qty_max,
            'lines_min' => (int) $rule->lines_min,
            'weight_min' => $this->num($rule->weight_min),
            'weight_max' => $this->num($rule->weight_max),
            'trig_mode' => (int) $rule->trig_mode,
            'trig_min_qty' => (int) $rule->trig_min_qty,
            'excl_mode' => (int) $rule->excl_mode,
            'customer_type' => (int) $rule->customer_type,
            'first_order' => (int) $rule->first_order,
            'date_from' => $rule->date_from ? $this->formatDate($rule->date_from) : '',
            'date_to' => $rule->date_to ? $this->formatDate($rule->date_to) : '',
            'days[]' => JosraEnvioGratisEngine::csv($rule->days),
            'time_from' => $rule->time_from,
            'time_to' => $rule->time_to,
        ];
        foreach (['trig_categories', 'trig_manufacturers', 'trig_suppliers', 'excl_categories', 'excl_manufacturers',
            'excl_suppliers', 'carriers', 'zones', 'countries', 'states', 'groups', 'shops', 'currencies', ] as $f) {
            $values[$f] = JosraEnvioGratisEngine::csv($rule->{$f});
        }
        $values['title'] = [];
        foreach (Language::getLanguages(false) as $lang) {
            $id = (int) $lang['id_lang'];
            $t = is_array($rule->title) ? (isset($rule->title[$id]) ? $rule->title[$id] : '') : '';
            $values['title'][$id] = $t;
        }

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = 'id_rule';
        $helper->id = (int) $rule->id;
        $helper->table = 'josraenviogratis_rule';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->adminUrl(['jeg_tab' => 'rules'], false);
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->languages = $this->context->controller->getLanguages();
        $helper->submit_action = 'submitJegRule';
        $helper->show_toolbar = false;
        $helper->fields_value = $values;

        $heading = $rule->id
            ? sprintf($this->l('Editando la regla #%d'), (int) $rule->id) . ($rule->name !== '' ? ' · ' . $rule->name : '')
            : $this->l('Nueva regla');

        return '<div class="jeg-rule-form" data-heading="' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '">'
            . $helper->generateForm($forms) . '</div>';
    }

    protected function num($v)
    {
        $v = (float) $v;
        if ($v == 0) {
            return '0';
        }

        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }

    /* ---------------------------- Transportistas */

    protected function renderCarriersTab()
    {
        $ps9 = JosraEnvioGratisEngine::isPs9();
        $modes = JosraEnvioGratisCarriers::getModes();
        $showAll = Tools::getValue('jeg_cfilter') === 'all';
        $rows = [];
        $total = 0;
        $active = 0;
        foreach (JosraEnvioGratisCarriers::listCarriers(false) as $row) {
            ++$total;
            if ((int) $row['active']) {
                ++$active;
            } elseif (!$showAll) {
                continue;
            }
            $origType = $row['type'] === JosraEnvioGratisCarriers::TYPE_ADOPTED
                ? JosraEnvioGratisCarriers::originalType((int) $row['id_carrier'])
                : $row['type'];
            $origModule = '';
            if ($row['type'] === JosraEnvioGratisCarriers::TYPE_ADOPTED) {
                $orig = Db::getInstance()->getValue(
                    'SELECT `orig_external_module_name` FROM `' . _DB_PREFIX_ . 'josraenviogratis_carrier`
                     WHERE `id_carrier` = ' . (int) $row['id_carrier']
                );
                $origModule = (string) $orig;
            } elseif ($row['type'] !== JosraEnvioGratisCarriers::TYPE_NATIVE) {
                $origModule = (string) $row['external_module_name'];
            }
            $rows[] = [
                'id_carrier' => (int) $row['id_carrier'],
                'id_reference' => (int) $row['id_reference'],
                'name' => $row['name'],
                'active' => (int) $row['active'],
                'is_free' => (int) $row['is_free'],
                'type' => $row['type'],
                'orig_type' => $origType,
                'module' => $origModule,
                'managed' => $row['type'] === JosraEnvioGratisCarriers::TYPE_ADOPTED,
                'wanted' => JosraEnvioGratisCarriers::wanted($row, $modes),
                'can' => $row['type'] !== JosraEnvioGratisCarriers::TYPE_MODULE_EXTERNAL,
            ];
        }

        return $this->fetchAdmin('carriers.tpl', [
            'jeg_ps9' => $ps9,
            'jeg_carriers' => $rows,
            'jeg_show_all' => $showAll,
            'jeg_count_total' => $total,
            'jeg_count_active' => $active,
            'jeg_url_active' => $this->adminUrl(['jeg_tab' => 'carriers']),
            'jeg_url_all' => $this->adminUrl(['jeg_tab' => 'carriers', 'jeg_cfilter' => 'all']),
            'jeg_form_url' => $this->adminUrl(['jeg_tab' => 'carriers'] + ($showAll ? ['jeg_cfilter' => 'all'] : [])),
        ]);
    }

    /* ---------------------------- Simulador */

    protected function renderSimulator()
    {
        $idCart = (int) Tools::getValue('jeg_id_cart');
        $result = null;
        $error = '';
        if ($idCart) {
            $cart = new Cart($idCart);
            if (!Validate::isLoadedObject($cart)) {
                $error = sprintf($this->l('No existe ningún carrito con el ID %d.'), $idCart);
            } elseif (!$cart->nbProducts()) {
                $error = sprintf($this->l('El carrito %d está vacío.'), $idCart);
            } else {
                $result = $this->simulate($cart);
            }
        }
        $lastCarts = Db::getInstance()->executeS(
            'SELECT c.`id_cart`, c.`date_upd`, cu.`firstname`, cu.`lastname`, o.`id_order`
             FROM `' . _DB_PREFIX_ . 'cart` c
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` cu ON (cu.`id_customer` = c.`id_customer`)
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON (o.`id_cart` = c.`id_cart`)
             ORDER BY c.`id_cart` DESC LIMIT 10'
        );
        $recent = [];
        if (is_array($lastCarts)) {
            foreach ($lastCarts as $c) {
                $recent[] = [
                    'id_cart' => (int) $c['id_cart'],
                    'date' => $this->formatDate($c['date_upd']) . ' ' . date('H:i', strtotime($c['date_upd'])),
                    'customer' => trim($c['firstname'] . ' ' . $c['lastname']),
                    'id_order' => (int) $c['id_order'],
                    'url' => $this->adminUrl(['jeg_tab' => 'simulator', 'jeg_id_cart' => (int) $c['id_cart']]),
                ];
            }
        }

        return $this->fetchAdmin('simulator.tpl', [
            'jeg_form_url' => $this->adminUrl(['jeg_tab' => 'simulator']),
            'jeg_id_cart' => $idCart ?: '',
            'jeg_sim' => $result,
            'jeg_sim_error' => $error,
            'jeg_recent' => $recent,
        ]);
    }

    protected function simulate(Cart $cart)
    {
        JosraEnvioGratisEngine::resetCache();
        $data = JosraEnvioGratisEngine::cartData($cart);
        $rules = JosraEnvioGratisEngine::getRules();
        $carriers = JosraEnvioGratisEngine::zoneCarriers($data['id_zone'], $data['id_shop']);
        if ((int) $cart->id_carrier && !in_array((int) $cart->id_carrier, $carriers)) {
            array_unshift($carriers, (int) $cart->id_carrier);
        }

        $country = new Country((int) $data['id_country'], (int) $this->context->language->id);
        $zone = new Zone((int) $data['id_zone']);
        $state = $data['id_state'] ? new State((int) $data['id_state']) : null;

        $rowsOut = [];
        foreach ($carriers as $idCarrier) {
            $carrier = new Carrier((int) $idCarrier, (int) $this->context->language->id);
            $raw = JosraEnvioGratisEngine::rawShipping($cart, $idCarrier, true);
            $final = $cart->getPackageShippingCost((int) $idCarrier, true);
            $hit = JosraEnvioGratisEngine::pickRule($cart, $idCarrier, $data);
            $evals = [];
            foreach ($rules as $rule) {
                JosraEnvioGratisEngine::$guard++;
                try {
                    $ev = JosraEnvioGratisEngine::evaluateRule($rule, $data, $cart, $idCarrier);
                } finally {
                    JosraEnvioGratisEngine::$guard--;
                }
                $evals[] = [
                    'name' => $rule['name'],
                    'priority' => (int) $rule['priority'],
                    'ok' => $ev['ok'],
                    'applied' => $hit && (int) $hit['rule']['id_rule'] === (int) $rule['id_rule'],
                    'reasons' => $ev['reasons'],
                    'amount' => ($ev['min'] > 0 || $ev['max'] > 0) ? JosraEnvioGratisEngine::formatPrice($ev['amount'], $data['id_currency']) : '',
                ];
            }
            $rowsOut[] = [
                'name' => Validate::isLoadedObject($carrier) ? ($carrier->name === '0' ? Configuration::get('PS_SHOP_NAME') : $carrier->name) : '#' . $idCarrier,
                'id_reference' => JosraEnvioGratisCarriers::referenceOf($idCarrier),
                'selected' => (int) $cart->id_carrier === (int) $idCarrier,
                'raw' => $final === false ? '' : JosraEnvioGratisEngine::formatPrice($raw, $data['id_currency']),
                'final' => $final === false ? $this->l('No disponible para este carrito') : JosraEnvioGratisEngine::formatPrice($final, $data['id_currency']),
                'rule' => $hit ? $hit['rule']['name'] : '',
                'evals' => $evals,
            ];
        }

        return [
            'id_cart' => (int) $cart->id,
            'products' => count($data['products']),
            'weight' => round($data['weight'], 3) . ' ' . Configuration::get('PS_WEIGHT_UNIT'),
            'location' => $data['known']
                ? trim($country->name . ($state && Validate::isLoadedObject($state) ? ' — ' . $state->name : '') . ' (' . $zone->name . ')')
                : $this->l('Sin dirección: se usa el país por defecto') . ' (' . $country->name . ')',
            'customer' => $data['is_company'] ? $this->l('Empresa') : $this->l('Particular'),
            'carriers' => $rowsOut,
            'no_rules' => !$rules,
        ];
    }

    /* ---------------------------- Textos y visualización */

    protected function renderDisplayForm()
    {
        $yesNo = [
            ['id' => 'on', 'value' => 1, 'label' => $this->l('Sí')],
            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
        ];
        $form = ['form' => [
            'legend' => ['title' => $this->l('Dónde y cómo se muestra'), 'icon' => 'icon-eye'],
            'input' => [
                ['type' => 'switch', 'name' => 'JEG_SHOW_CART', 'label' => $this->l('En la página del carrito'), 'is_bool' => true, 'values' => $yesNo],
                ['type' => 'switch', 'name' => 'JEG_SHOW_MODAL', 'label' => $this->l('En la ventana de "producto añadido"'), 'is_bool' => true, 'values' => $yesNo,
                    'desc' => $this->l('Usa el hook displayCartModalFooter. Si tu tema no lo llama, no se verá ahí.'), ],
                ['type' => 'switch', 'name' => 'JEG_SHOW_CHECKOUT', 'label' => $this->l('En el paso de transporte'), 'is_bool' => true, 'values' => $yesNo],
                ['type' => 'switch', 'name' => 'JEG_SHOW_PRODUCT_BAR', 'label' => $this->l('Aviso de portes en la ficha de producto'), 'is_bool' => true, 'values' => $yesNo,
                    'desc' => $this->l('Calcula con el carrito más el producto que se está viendo, con la combinación y la cantidad elegidas, y se actualiza al cambiarlas.'), ],
                ['type' => 'switch', 'name' => 'JEG_SHOW_PROGRESS', 'label' => $this->l('Barra de progreso'), 'is_bool' => true, 'values' => $yesNo],
                ['type' => 'text', 'name' => 'JEG_UNKNOWN_MAX', 'label' => $this->l('Títulos a mostrar sin dirección'), 'class' => 'fixed-width-sm',
                    'desc' => $this->l('Mientras no sabemos la dirección del cliente se enseñan los textos públicos de las reglas de portes gratis, como máximo este número.'), ],
                ['type' => 'text', 'name' => 'JEG_TXT_REMAINING', 'label' => $this->l('Texto "te faltan"'), 'lang' => true, 'col' => 8,
                    'desc' => $this->l('{amount} se sustituye por lo que falta. Si no lo pones, el importe va al final.'), ],
                ['type' => 'text', 'name' => 'JEG_TXT_ACHIEVED', 'label' => $this->l('Etiqueta "portes gratis conseguidos"'), 'lang' => true, 'col' => 8,
                    'desc' => $this->l('Sale en el carrito solo cuando el pedido ya tiene portes gratis.'), ],
                ['type' => 'text', 'name' => 'JEG_TXT_PRODUCT', 'label' => $this->l('Etiqueta en la ficha'), 'lang' => true, 'col' => 8,
                    'desc' => $this->l('Para los productos que por sí solos dan portes gratis (se activa en cada regla).'), ],
                ['type' => 'text', 'name' => 'JEG_TXT_PRODUCT_ACHIEVED', 'label' => $this->l('Ficha: con este producto llega'), 'lang' => true, 'col' => 8,
                    'desc' => $this->l('Sale en la ficha cuando el carrito más el producto que se está viendo (con su combinación y cantidad) ya tiene portes gratis.'), ],
                ['type' => 'text', 'name' => 'JEG_TXT_PRODUCT_REMAINING', 'label' => $this->l('Ficha: con este producto aún falta'), 'lang' => true, 'col' => 8,
                    'desc' => $this->l('{amount} se sustituye por lo que faltaría después de añadir el producto.'), ],
            ],
            'submit' => ['title' => $this->l('Guardar'), 'name' => 'submitJegDisplay'],
        ]];

        $values = $this->getSettings();
        foreach ($this->getTextKeys() as $key) {
            $values[$key] = [];
            foreach (Language::getLanguages(false) as $lang) {
                $values[$key][(int) $lang['id_lang']] = (string) Configuration::get($key, (int) $lang['id_lang']);
            }
        }

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->adminUrl(['jeg_tab' => 'display'], false);
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->languages = $this->context->controller->getLanguages();
        $helper->submit_action = 'submitJegDisplay';
        $helper->show_toolbar = false;
        $helper->fields_value = $values;

        return $helper->generateForm([$form]) . $this->fetchAdmin('display_help.tpl', []);
    }

    protected function processSaveDisplay()
    {
        foreach (array_keys($this->getConfigDefaults()) as $key) {
            $v = (int) Tools::getValue($key);
            if ($key === 'JEG_UNKNOWN_MAX') {
                $v = max(1, min(20, $v));
            } else {
                $v = $v ? 1 : 0;
            }
            Configuration::updateValue($key, $v);
        }
        foreach ($this->getTextKeys() as $key) {
            $vals = [];
            foreach (Language::getLanguages(false) as $lang) {
                $id = (int) $lang['id_lang'];
                $vals[$id] = strip_tags(trim((string) Tools::getValue($key . '_' . $id)));
            }
            Configuration::updateValue($key, $vals);
        }
        $this->ensureConfig();
    }
}
