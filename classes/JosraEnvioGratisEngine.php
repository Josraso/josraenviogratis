<?php
/**
 * Motor de reglas de josraenviogratis.
 *
 * Evalúa las reglas por orden de prioridad para un carrito y un transportista y
 * devuelve el porte final. No crea reglas de carrito ni descuentos: cambia el precio
 * del transporte, así que en el pedido y en la factura el transporte sale a 0 € (o al
 * precio rebajado) sin ninguna línea de descuento.
 *
 * @author    josra
 * @copyright josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraEnvioGratisEngine
{
    /** @var int Mientras es > 0 el módulo no modifica portes (evita recursión) */
    public static $guard = 0;

    protected static $rules = null;
    protected static $cartData = [];
    protected static $raw = [];
    protected static $applied = [];
    protected static $orderCounts = [];
    protected static $productInfo = [];

    public static function isPs9()
    {
        return version_compare(_PS_VERSION_, '9.0.0', '>=');
    }

    public static function resetCache()
    {
        self::$rules = null;
        self::$cartData = [];
        self::$raw = [];
    }

    /* ------------------------------------------------------------------
     * Reglas
     * ------------------------------------------------------------------ */

    /**
     * Lista de enteros a partir de "1,2,3".
     *
     * @param string $value
     *
     * @return array
     */
    public static function csv($value)
    {
        $out = [];
        foreach (explode(',', (string) $value) as $v) {
            $v = (int) trim($v);
            if ($v > 0) {
                $out[$v] = $v;
            }
        }

        return array_values($out);
    }

    /**
     * Reglas activas ordenadas por prioridad, con las listas ya convertidas en arrays.
     *
     * @param int|null $idLang
     *
     * @return array
     */
    public static function getRules($idLang = null)
    {
        if ($idLang === null) {
            $idLang = (int) Context::getContext()->language->id;
        }
        if (self::$rules !== null && isset(self::$rules[$idLang])) {
            return self::$rules[$idLang];
        }
        if (self::$rules === null) {
            self::$rules = [];
        }
        $rows = Db::getInstance()->executeS(
            'SELECT r.*, rl.`title`
             FROM `' . _DB_PREFIX_ . 'josraenviogratis_rule` r
             LEFT JOIN `' . _DB_PREFIX_ . 'josraenviogratis_rule_lang` rl
                ON (rl.`id_rule` = r.`id_rule` AND rl.`id_lang` = ' . (int) $idLang . ')
             WHERE r.`active` = 1
             ORDER BY r.`priority` ASC, r.`id_rule` ASC'
        );
        $rules = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $rules[] = self::prepareRule($row);
            }
        }
        self::$rules[$idLang] = $rules;

        return $rules;
    }

    public static function prepareRule($row)
    {
        $lists = [
            'trig_products', 'trig_categories', 'trig_manufacturers', 'trig_suppliers',
            'excl_products', 'excl_categories', 'excl_manufacturers', 'excl_suppliers',
            'carriers', 'zones', 'countries', 'states', 'groups', 'shops', 'currencies', 'days',
        ];
        foreach ($lists as $k) {
            $row['_' . $k] = self::csv(isset($row[$k]) ? $row[$k] : '');
        }
        $row['_has_trig'] = (bool) ($row['_trig_products'] || $row['_trig_categories']
            || $row['_trig_manufacturers'] || $row['_trig_suppliers']);
        $row['_has_excl'] = (bool) ($row['_excl_products'] || $row['_excl_categories']
            || $row['_excl_manufacturers'] || $row['_excl_suppliers']);
        if (!isset($row['title'])) {
            $row['title'] = '';
        }

        return $row;
    }

    /* ------------------------------------------------------------------
     * Datos del carrito
     * ------------------------------------------------------------------ */

    protected static function cartSignature(Cart $cart, $products)
    {
        $parts = [];
        foreach ($products as $p) {
            $parts[] = (int) $p['id_product'] . '-' . (int) $p['id_product_attribute'] . '-' . (int) $p['cart_quantity'];
        }

        return md5(
            (int) $cart->id . '|' . (int) $cart->id_currency . '|' . (int) $cart->id_address_delivery . '|'
            . (int) $cart->id_address_invoice . '|' . (int) $cart->id_customer . '|' . (int) $cart->id_shop . '|'
            . implode(',', $parts)
        );
    }

    /**
     * Todo lo que necesitan las reglas sobre el carrito, calculado una vez por petición.
     *
     * @param Cart $cart
     *
     * @return array|null
     */
    public static function cartData(Cart $cart)
    {
        if (!Validate::isLoadedObject($cart)) {
            return null;
        }
        ++self::$guard;
        try {
            $products = $cart->getProducts();
            if (!is_array($products)) {
                $products = [];
            }
            $sig = self::cartSignature($cart, $products);
            if (isset(self::$cartData[$sig])) {
                return self::$cartData[$sig];
            }

            $ids = [];
            foreach ($products as $p) {
                $ids[(int) $p['id_product']] = (int) $p['id_product'];
            }
            $cats = [];
            if ($ids) {
                $rows = Db::getInstance()->executeS(
                    'SELECT `id_product`, `id_category` FROM `' . _DB_PREFIX_ . 'category_product`
                     WHERE `id_product` IN (' . implode(',', $ids) . ')'
                );
                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        $cats[(int) $r['id_product']][] = (int) $r['id_category'];
                    }
                }
            }

            // Cupones: solo reducciones y regalos (los de envío gratis no cuentan como descuento).
            $disc = 0.0;
            $discWt = 0.0;
            foreach ([CartRule::FILTER_ACTION_REDUCTION, CartRule::FILTER_ACTION_GIFT] as $filter) {
                $rules = $cart->getCartRules($filter, false);
                if (is_array($rules)) {
                    foreach ($rules as $cr) {
                        $discWt += isset($cr['value_real']) ? (float) $cr['value_real'] : 0;
                        $disc += isset($cr['value_tax_exc']) ? (float) $cr['value_tax_exc'] : 0;
                    }
                }
            }

            // Ubicación
            $known = false;
            $idCountry = 0;
            $idState = 0;
            $idZone = 0;
            $idAddr = (int) $cart->id_address_delivery;
            if ($idAddr && Address::addressExists($idAddr)) {
                $addr = new Address($idAddr);
                if (Validate::isLoadedObject($addr)) {
                    $known = true;
                    $idCountry = (int) $addr->id_country;
                    $idState = (int) $addr->id_state;
                    $idZone = (int) Address::getZoneById($idAddr);
                }
            }
            if (!$known) {
                $ctx = Context::getContext();
                $idCountry = (isset($ctx->country) && Validate::isLoadedObject($ctx->country))
                    ? (int) $ctx->country->id : (int) Configuration::get('PS_COUNTRY_DEFAULT');
                $idZone = (int) Country::getIdZone($idCountry);
            }

            // Empresa o particular: dirección con empresa y NIF-IVA
            $isCompany = false;
            $idInv = (int) $cart->id_address_invoice ?: $idAddr;
            if ($idInv && Address::addressExists($idInv)) {
                $inv = new Address($idInv);
                $isCompany = trim((string) $inv->company) !== '' && trim((string) $inv->vat_number) !== '';
            }

            $idCustomer = (int) $cart->id_customer;
            $groups = array_map('intval', (array) Customer::getGroupsStatic($idCustomer));

            $data = [
                'sig' => $sig,
                'products' => $products,
                'cats' => $cats,
                'disc' => $disc,
                'disc_wt' => $discWt,
                'weight' => (float) $cart->getTotalWeight(),
                'known' => $known,
                'id_country' => $idCountry,
                'id_state' => $idState,
                'id_zone' => $idZone,
                'is_company' => $isCompany,
                'id_customer' => $idCustomer,
                'groups' => $groups,
                'id_shop' => (int) $cart->id_shop,
                'id_currency' => (int) $cart->id_currency,
            ];
            self::$cartData[$sig] = $data;

            return $data;
        } finally {
            --self::$guard;
        }
    }

    protected static function customerOrderCount($idCustomer)
    {
        $idCustomer = (int) $idCustomer;
        if (!$idCustomer) {
            return 0;
        }
        if (!isset(self::$orderCounts[$idCustomer])) {
            self::$orderCounts[$idCustomer] = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`
                 WHERE `id_customer` = ' . $idCustomer . ' AND `valid` = 1'
            );
        }

        return self::$orderCounts[$idCustomer];
    }

    /* ------------------------------------------------------------------
     * Coste del transporte sin el módulo
     * ------------------------------------------------------------------ */

    protected static function rawKey(Cart $cart, $idCarrier, $withTax)
    {
        $products = $cart->getProducts();

        return self::cartSignature($cart, is_array($products) ? $products : []) . '|' . (int) $idCarrier . '|' . ($withTax ? 1 : 0);
    }

    public static function rememberRaw(Cart $cart, $idCarrier, $withTax, $cost)
    {
        if ($cost === false || $cost === null) {
            return;
        }
        self::$raw[self::rawKey($cart, $idCarrier, $withTax)] = (float) $cost;
    }

    /**
     * En 1.7/8 el módulo recibe el porte sin IVA; se guarda también con IVA.
     */
    public static function rememberModuleRaw(Cart $cart, $idCarrier, $netCost)
    {
        if ($netCost === false || $netCost === null) {
            return;
        }
        $net = (float) $netCost;
        self::rememberRaw($cart, $idCarrier, false, $net);
        $rate = self::carrierTaxRate($cart, $idCarrier);
        self::rememberRaw($cart, $idCarrier, true, self::round($net * (1 + $rate / 100)));
    }

    public static function carrierTaxRate(Cart $cart, $idCarrier)
    {
        static $cache = [];
        if (Tax::excludeTaxeOption()) {
            return 0.0;
        }
        $key = (int) $idCarrier . '-' . (int) $cart->id_address_delivery;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $carrier = new Carrier((int) $idCarrier);
        if (!Validate::isLoadedObject($carrier)) {
            return $cache[$key] = 0.0;
        }
        $address = Address::initialize((int) $cart->id_address_delivery ?: null);

        return $cache[$key] = (float) $carrier->getTaxesRate($address);
    }

    /**
     * Porte del transportista antes de aplicar este módulo.
     *
     * @return float
     */
    public static function rawShipping(Cart $cart, $idCarrier, $withTax)
    {
        $key = self::rawKey($cart, $idCarrier, $withTax);
        if (isset(self::$raw[$key])) {
            return self::$raw[$key];
        }
        if (self::isPs9()) {
            // En 9 la caché del núcleo guarda el valor antes del hook: se puede pedir sin modificar.
            ++self::$guard;
            try {
                $v = $cart->getPackageShippingCost((int) $idCarrier, (bool) $withTax);
            } finally {
                --self::$guard;
            }
        } else {
            if (self::$guard > 0) {
                // En 1.7/8 una llamada aquí guardaría en caché el porte sin modificar.
                return 0.0;
            }
            $v = $cart->getPackageShippingCost((int) $idCarrier, (bool) $withTax);
            if (isset(self::$raw[$key])) {
                return self::$raw[$key];
            }
        }
        $v = ($v === false || $v === null) ? 0.0 : (float) $v;
        self::$raw[$key] = $v;

        return $v;
    }

    /* ------------------------------------------------------------------
     * Evaluación
     * ------------------------------------------------------------------ */

    protected static function lineMatches($line, $data, $prods, $cats, $mans, $sups)
    {
        $idp = (int) $line['id_product'];
        if ($prods && in_array($idp, $prods)) {
            return true;
        }
        if ($mans && in_array((int) $line['id_manufacturer'], $mans)) {
            return true;
        }
        if ($sups && in_array((int) $line['id_supplier'], $sups)) {
            return true;
        }
        if ($cats && isset($data['cats'][$idp]) && array_intersect($data['cats'][$idp], $cats)) {
            return true;
        }

        return false;
    }

    protected static function isExcluded($rule, $line, $data)
    {
        if (!$rule['_has_excl']) {
            return false;
        }

        return self::lineMatches($line, $data, $rule['_excl_products'], $rule['_excl_categories'],
            $rule['_excl_manufacturers'], $rule['_excl_suppliers']);
    }

    public static function convert($amount, $idCurrency)
    {
        $amount = (float) $amount;
        if ($amount == 0) {
            return 0.0;
        }
        $cur = Currency::getCurrencyInstance((int) $idCurrency);
        if (!Validate::isLoadedObject($cur)) {
            return $amount;
        }

        return (float) Tools::convertPrice($amount, $cur);
    }

    public static function round($value)
    {
        $precision = 2;
        $ctx = Context::getContext();
        if (method_exists($ctx, 'getComputingPrecision')) {
            $precision = (int) $ctx->getComputingPrecision();
        } elseif (defined('_PS_PRICE_COMPUTE_PRECISION_')) {
            $precision = (int) _PS_PRICE_COMPUTE_PRECISION_;
        }

        return (float) Tools::ps_round((float) $value, $precision);
    }

    /**
     * Condiciones que no dependen del carrito (tienda, moneda, fechas).
     */
    public static function staticChecks($rule, $idShop, $idCurrency, &$reasons = null)
    {
        $ok = true;
        if ($rule['_shops'] && !in_array((int) $idShop, $rule['_shops'])) {
            $ok = false;
            $reasons[] = 'Tienda no incluida en la regla';
        }
        if ($rule['_currencies'] && !in_array((int) $idCurrency, $rule['_currencies'])) {
            $ok = false;
            $reasons[] = 'Moneda no incluida en la regla';
        }
        $now = date('Y-m-d H:i:s');
        if (!empty($rule['date_from']) && $rule['date_from'] !== '0000-00-00 00:00:00' && $now < $rule['date_from']) {
            $ok = false;
            $reasons[] = 'La regla empieza el ' . date('d/m/Y', strtotime($rule['date_from']));
        }
        if (!empty($rule['date_to']) && $rule['date_to'] !== '0000-00-00 00:00:00' && $now > $rule['date_to']) {
            $ok = false;
            $reasons[] = 'La regla terminó el ' . date('d/m/Y', strtotime($rule['date_to']));
        }
        if ($rule['_days'] && !in_array((int) date('N'), $rule['_days'])) {
            $ok = false;
            $reasons[] = 'Hoy no es un día válido de la regla';
        }
        $tf = (string) $rule['time_from'];
        $tt = (string) $rule['time_to'];
        if ($tf !== '' || $tt !== '') {
            $hm = date('H:i');
            if ($tf !== '' && $tt !== '') {
                $inside = $tf <= $tt ? ($hm >= $tf && $hm <= $tt) : ($hm >= $tf || $hm <= $tt);
            } elseif ($tf !== '') {
                $inside = $hm >= $tf;
            } else {
                $inside = $hm <= $tt;
            }
            if (!$inside) {
                $ok = false;
                $reasons[] = 'Fuera del horario de la regla (' . ($tf ?: '00:00') . '–' . ($tt ?: '23:59') . ')';
            }
        }

        return $ok;
    }

    /**
     * Condiciones del cliente (grupo, tipo, primer pedido).
     */
    public static function customerChecks($rule, $data, &$reasons = null)
    {
        $ok = true;
        if ($rule['_groups'] && !array_intersect($data['groups'], $rule['_groups'])) {
            $ok = false;
            $reasons[] = 'El cliente no pertenece a ninguno de los grupos de la regla';
        }
        $type = (int) $rule['customer_type'];
        if ($type === 1 && $data['is_company']) {
            $ok = false;
            $reasons[] = 'La regla es solo para particulares y la dirección es de empresa';
        } elseif ($type === 2 && !$data['is_company']) {
            $ok = false;
            $reasons[] = 'La regla es solo para empresas (dirección con empresa y NIF-IVA)';
        }
        if ((int) $rule['first_order'] && self::customerOrderCount($data['id_customer']) > 0) {
            $ok = false;
            $reasons[] = 'La regla es solo para el primer pedido y el cliente ya tiene pedidos';
        }

        return $ok;
    }

    public static function locationChecks($rule, $data, &$reasons = null)
    {
        $ok = true;
        if ($rule['_zones'] && !in_array((int) $data['id_zone'], $rule['_zones'])) {
            $ok = false;
            $reasons[] = 'Zona no incluida en la regla';
        }
        if ($rule['_countries'] && !in_array((int) $data['id_country'], $rule['_countries'])) {
            $ok = false;
            $reasons[] = 'País no incluido en la regla';
        }
        if ($rule['_states'] && !in_array((int) $data['id_state'], $rule['_states'])) {
            $ok = false;
            $reasons[] = 'Provincia no incluida en la regla';
        }

        return $ok;
    }

    /**
     * Importe que la regla compara con su mínimo/máximo, según su configuración.
     */
    public static function computeAmount($rule, $data, Cart $cart, $idCarrier)
    {
        $tax = (int) $rule['amount_tax'] === 1;
        $sum = 0.0;
        $ignore = $rule['_has_excl'] && (int) $rule['excl_mode'] === 1;
        foreach ($data['products'] as $line) {
            if ($ignore && self::isExcluded($rule, $line, $data)) {
                continue;
            }
            $sum += $tax ? (float) $line['total_wt'] : (float) $line['total'];
        }
        if ((int) $rule['amount_discounts'] === 1) {
            $sum -= $tax ? $data['disc_wt'] : $data['disc'];
        }
        if ($sum < 0) {
            $sum = 0.0;
        }
        if ((int) $rule['amount_shipping'] === 1 && $idCarrier) {
            $sum += self::rawShipping($cart, $idCarrier, $tax);
        }

        return self::round($sum);
    }

    /**
     * Evalúa una regla.
     *
     * @param array $rule
     * @param array $data cartData()
     * @param Cart $cart
     * @param int|null $idCarrier null = no comprobar transportista
     * @param bool $skipAmountMin true = no comprobar el importe mínimo (para la barra)
     *
     * @return array ['ok' => bool, 'reasons' => [], 'amount' => float, 'min' => float, 'max' => float, 'only_min' => bool]
     */
    public static function evaluateRule($rule, $data, Cart $cart, $idCarrier, $skipAmountMin = false)
    {
        $reasons = [];
        $ok = true;

        $ok = self::staticChecks($rule, $data['id_shop'], $data['id_currency'], $reasons) && $ok;

        if ($idCarrier !== null && $rule['_carriers']) {
            $ref = JosraEnvioGratisCarriers::referenceOf($idCarrier);
            if (!in_array($ref, $rule['_carriers'])) {
                $ok = false;
                $reasons[] = 'Transportista no incluido en la regla';
            }
        }

        $ok = self::locationChecks($rule, $data, $reasons) && $ok;
        $ok = self::customerChecks($rule, $data, $reasons) && $ok;

        // Exclusiones y unidades contables
        $blockExcl = $rule['_has_excl'] && (int) $rule['excl_mode'] === 0;
        $ignoreExcl = $rule['_has_excl'] && (int) $rule['excl_mode'] === 1;
        $qty = 0;
        $lines = 0;
        $counted = [];
        foreach ($data['products'] as $line) {
            $excluded = $rule['_has_excl'] && self::isExcluded($rule, $line, $data);
            if ($excluded && $blockExcl) {
                $ok = false;
                $reasons[] = 'El carrito lleva un producto excluido: ' . $line['name'];
                break;
            }
            if ($excluded && $ignoreExcl) {
                continue;
            }
            $qty += (int) $line['cart_quantity'];
            ++$lines;
            $counted[] = $line;
        }

        // Productos que activan la regla
        if ($rule['_has_trig']) {
            $matchQty = 0;
            $allMatch = true;
            foreach ($counted as $line) {
                if (self::lineMatches($line, $data, $rule['_trig_products'], $rule['_trig_categories'],
                    $rule['_trig_manufacturers'], $rule['_trig_suppliers'])) {
                    $matchQty += (int) $line['cart_quantity'];
                } else {
                    $allMatch = false;
                }
            }
            $need = max(1, (int) $rule['trig_min_qty']);
            if ((int) $rule['trig_mode'] === 1) {
                if (!$counted || !$allMatch) {
                    $ok = false;
                    $reasons[] = 'No todos los productos del carrito son de los que activan la regla';
                }
            }
            if ($matchQty < $need) {
                $ok = false;
                $reasons[] = $need > 1
                    ? 'Hacen falta ' . $need . ' unidades de los productos que activan la regla (hay ' . $matchQty . ')'
                    : 'El carrito no lleva ningún producto de los que activan la regla';
            }
        }

        if ((int) $rule['qty_min'] && $qty < (int) $rule['qty_min']) {
            $ok = false;
            $reasons[] = 'Unidades: ' . $qty . ', mínimo ' . (int) $rule['qty_min'];
        }
        if ((int) $rule['qty_max'] && $qty > (int) $rule['qty_max']) {
            $ok = false;
            $reasons[] = 'Unidades: ' . $qty . ', máximo ' . (int) $rule['qty_max'];
        }
        if ((int) $rule['lines_min'] && $lines < (int) $rule['lines_min']) {
            $ok = false;
            $reasons[] = 'Productos distintos: ' . $lines . ', mínimo ' . (int) $rule['lines_min'];
        }
        $unit = Configuration::get('PS_WEIGHT_UNIT');
        if ((float) $rule['weight_min'] > 0 && $data['weight'] < (float) $rule['weight_min']) {
            $ok = false;
            $reasons[] = 'Peso: ' . round($data['weight'], 3) . ' ' . $unit . ', mínimo ' . (float) $rule['weight_min'];
        }
        if ((float) $rule['weight_max'] > 0 && $data['weight'] > (float) $rule['weight_max']) {
            $ok = false;
            $reasons[] = 'Peso: ' . round($data['weight'], 3) . ' ' . $unit . ', máximo ' . (float) $rule['weight_max'];
        }

        // Importe (al final: puede necesitar el porte)
        $min = self::convert($rule['amount_min'], $data['id_currency']);
        $max = self::convert($rule['amount_max'], $data['id_currency']);
        $amount = 0.0;
        $onlyMin = false;
        if ($ok && ($min > 0 || $max > 0)) {
            $amount = self::computeAmount($rule, $data, $cart, $idCarrier);
            if ($max > 0 && $amount > $max) {
                $ok = false;
                $reasons[] = 'Importe ' . self::formatPrice($amount, $data['id_currency']) . ' por encima del máximo '
                    . self::formatPrice($max, $data['id_currency']);
            } elseif ($min > 0 && $amount < $min) {
                $onlyMin = true;
                if (!$skipAmountMin) {
                    $ok = false;
                    $reasons[] = 'Importe ' . self::formatPrice($amount, $data['id_currency']) . ', mínimo '
                        . self::formatPrice($min, $data['id_currency']);
                }
            }
        }

        return [
            'ok' => $ok,
            'reasons' => $reasons,
            'amount' => $amount,
            'min' => $min,
            'max' => $max,
            'only_min' => $ok && $onlyMin,
        ];
    }

    /**
     * Primera regla (por prioridad) que se cumple para ese transportista.
     *
     * @return array|null ['rule' => array, 'eval' => array]
     */
    public static function pickRule(Cart $cart, $idCarrier, $data = null)
    {
        $rules = self::getRules();
        if (!$rules) {
            return null;
        }
        if ($data === null) {
            $data = self::cartData($cart);
        }
        if (!$data || !$data['products']) {
            return null;
        }
        ++self::$guard;
        try {
            foreach ($rules as $rule) {
                $ev = self::evaluateRule($rule, $data, $cart, $idCarrier);
                if ($ev['ok']) {
                    return ['rule' => $rule, 'eval' => $ev];
                }
            }
        } finally {
            --self::$guard;
        }

        return null;
    }

    /**
     * Aplica la regla que toque al porte calculado por PrestaShop.
     *
     * @param Cart $cart
     * @param int $idCarrier
     * @param float $rawCost porte calculado por PrestaShop
     * @param bool $withTax si $rawCost lleva IVA
     * @param string $source 'hook' (PS 9) o 'module' (1.7/8, siempre sin IVA)
     *
     * @return float
     */
    public static function applyToCost(Cart $cart, $idCarrier, $rawCost, $withTax, $source)
    {
        if ($rawCost === false || $rawCost === null || self::$guard > 0) {
            return $rawCost;
        }
        if (!self::getRules()) {
            return $rawCost;
        }
        $raw = (float) $rawCost;
        $hit = self::pickRule($cart, $idCarrier);
        if (!$hit) {
            self::storeApplied($cart, $idCarrier, null, $raw, $raw, $withTax);

            return $rawCost;
        }
        $rule = $hit['rule'];
        $final = $raw;
        $effect = (int) $rule['effect'];
        if ($effect === JosraEnvioGratisRule::EFFECT_FREE) {
            $final = 0.0;
        } elseif ($effect === JosraEnvioGratisRule::EFFECT_FIXED) {
            $fixed = self::convert($rule['effect_value'], $cart->id_currency);
            if ($withTax && $source === 'hook') {
                $net = self::rawShipping($cart, $idCarrier, false);
                if ($net > 0 && $raw > 0) {
                    $factor = $raw / $net;
                } else {
                    $factor = 1 + self::carrierTaxRate($cart, $idCarrier) / 100;
                }
                $fixed = $fixed * $factor;
            }
            $final = min($raw, $fixed);
        } elseif ($effect === JosraEnvioGratisRule::EFFECT_PERCENT) {
            $pct = max(0, min(100, (float) $rule['effect_value']));
            $final = $raw * (1 - $pct / 100);
        }
        $final = max(0.0, self::round($final));
        self::storeApplied($cart, $idCarrier, $rule, $raw, $final, $withTax);

        return $final;
    }

    protected static function storeApplied(Cart $cart, $idCarrier, $rule, $before, $after, $withTax)
    {
        $key = (int) $cart->id . '|' . (int) $idCarrier;
        if (!$withTax && isset(self::$applied[$key]) && self::$applied[$key]['with_tax']) {
            return;
        }
        self::$applied[$key] = [
            'id_rule' => $rule ? (int) $rule['id_rule'] : 0,
            'rule_name' => $rule ? (string) $rule['name'] : '',
            'before' => (float) $before,
            'after' => (float) $after,
            'with_tax' => (bool) $withTax,
        ];
    }

    public static function getApplied($idCart, $idCarrier)
    {
        $key = (int) $idCart . '|' . (int) $idCarrier;

        return isset(self::$applied[$key]) ? self::$applied[$key] : null;
    }

    /* ------------------------------------------------------------------
     * Front: barra y etiqueta
     * ------------------------------------------------------------------ */

    /**
     * Transportistas activos que llegan a la zona.
     */
    public static function zoneCarriers($idZone, $idShop)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT c.`id_carrier`
             FROM `' . _DB_PREFIX_ . 'carrier` c
             INNER JOIN `' . _DB_PREFIX_ . 'carrier_zone` cz ON (cz.`id_carrier` = c.`id_carrier` AND cz.`id_zone` = ' . (int) $idZone . ')
             INNER JOIN `' . _DB_PREFIX_ . 'carrier_shop` cs ON (cs.`id_carrier` = c.`id_carrier` AND cs.`id_shop` = ' . (int) $idShop . ')
             WHERE c.`deleted` = 0 AND c.`active` = 1
             ORDER BY c.`position` ASC'
        );
        $ids = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $ids[] = (int) $r['id_carrier'];
            }
        }

        return $ids;
    }

    /**
     * Estado de la barra para el carrito actual.
     *
     * @param Cart $cart
     * @param int $maxTitles
     *
     * @return array|null ['mode' => achieved|remaining|unknown, ...]
     */
    public static function barState(Cart $cart, $maxTitles = 3)
    {
        if (!Validate::isLoadedObject($cart) || !$cart->nbProducts()) {
            return null;
        }
        $free = [];
        foreach (self::getRules() as $rule) {
            if ((int) $rule['effect'] === JosraEnvioGratisRule::EFFECT_FREE) {
                $free[] = $rule;
            }
        }
        if (!$free) {
            return null;
        }
        $data = self::cartData($cart);
        if (!$data || !$data['products']) {
            return null;
        }

        // Sin dirección: se enseñan los títulos públicos de las reglas.
        if (!$data['known']) {
            $titles = [];
            foreach ($free as $rule) {
                $t = trim((string) $rule['title']);
                if ($t === '' || isset($titles[$t])) {
                    continue;
                }
                $r = null;
                if (!self::staticChecks($rule, $data['id_shop'], $data['id_currency'], $r)
                    || !self::customerChecks($rule, $data, $r)) {
                    continue;
                }
                $titles[$t] = $t;
                if (count($titles) >= (int) $maxTitles) {
                    break;
                }
            }

            return $titles ? ['mode' => 'unknown', 'titles' => array_values($titles)] : null;
        }

        $candidates = [];
        if ((int) $cart->id_carrier) {
            $candidates[] = (int) $cart->id_carrier;
        } else {
            $candidates = self::zoneCarriers($data['id_zone'], $data['id_shop']);
        }
        if (!$candidates) {
            return null;
        }

        // Si alguna regla cuenta los portes en el importe, se piden antes (fuera de la protección
        // anti-recursión) para que queden memorizados.
        foreach (self::getRules() as $rule) {
            if ((int) $rule['amount_shipping'] === 1) {
                foreach ($candidates as $idCarrier) {
                    self::rawShipping($cart, $idCarrier, (int) $rule['amount_tax'] === 1);
                }
            }
        }

        // ¿Ya tiene portes gratis?
        foreach ($candidates as $idCarrier) {
            $hit = self::pickRule($cart, $idCarrier, $data);
            if ($hit && (int) $hit['rule']['effect'] === JosraEnvioGratisRule::EFFECT_FREE) {
                return ['mode' => 'achieved', 'rule' => $hit['rule']];
            }
        }

        // ¿Cuánto falta para la regla gratis más cercana?
        $best = null;
        foreach ($free as $rule) {
            if ((float) $rule['amount_min'] <= 0) {
                continue;
            }
            foreach ($candidates as $idCarrier) {
                ++self::$guard;
                try {
                    $ev = self::evaluateRule($rule, $data, $cart, $idCarrier, true);
                } finally {
                    --self::$guard;
                }
                if (!$ev['only_min']) {
                    continue;
                }
                $left = self::round($ev['min'] - $ev['amount']);
                if ($left > 0 && ($best === null || $left < $best['remaining'])) {
                    $best = [
                        'mode' => 'remaining',
                        'rule' => $rule,
                        'remaining' => $left,
                        'amount' => $ev['amount'],
                        'min' => $ev['min'],
                        'progress' => $ev['min'] > 0 ? (int) floor($ev['amount'] * 100 / $ev['min']) : 0,
                        'id_currency' => $data['id_currency'],
                    ];
                }
            }
        }

        return $best;
    }

    protected static function productInfo($idProduct)
    {
        $idProduct = (int) $idProduct;
        if (!isset(self::$productInfo[$idProduct])) {
            $row = Db::getInstance()->getRow(
                'SELECT `id_manufacturer`, `id_supplier` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . $idProduct
            );
            $cats = [];
            $rows = Db::getInstance()->executeS(
                'SELECT `id_category` FROM `' . _DB_PREFIX_ . 'category_product` WHERE `id_product` = ' . $idProduct
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $cats[] = (int) $r['id_category'];
                }
            }
            self::$productInfo[$idProduct] = [
                'id_product' => $idProduct,
                'id_manufacturer' => $row ? (int) $row['id_manufacturer'] : 0,
                'id_supplier' => $row ? (int) $row['id_supplier'] : 0,
                'cats' => $cats,
            ];
        }

        return self::$productInfo[$idProduct];
    }

    /**
     * Regla que da portes gratis con este producto y tiene activada la etiqueta en la ficha.
     *
     * @return array|null regla
     */
    public static function productBadgeRule($idProduct, $idShop, $idCurrency)
    {
        $info = self::productInfo($idProduct);
        foreach (self::getRules() as $rule) {
            if (!(int) $rule['show_badge'] || !$rule['_has_trig'] || (int) $rule['trig_min_qty'] > 1
                || (int) $rule['effect'] !== JosraEnvioGratisRule::EFFECT_FREE) {
                continue;
            }
            $r = null;
            if (!self::staticChecks($rule, $idShop, $idCurrency, $r)) {
                continue;
            }
            $data = ['cats' => [$info['id_product'] => $info['cats']]];
            if (self::lineMatches($info, $data, $rule['_trig_products'], $rule['_trig_categories'],
                $rule['_trig_manufacturers'], $rule['_trig_suppliers'])) {
                return $rule;
            }
        }

        return null;
    }

    public static function formatPrice($amount, $idCurrency)
    {
        $cur = Currency::getCurrencyInstance((int) $idCurrency);
        $ctx = Context::getContext();
        if (method_exists($ctx, 'getCurrentLocale')) {
            try {
                $locale = $ctx->getCurrentLocale();
                if ($locale) {
                    return $locale->formatPrice((float) $amount, $cur->iso_code);
                }
            } catch (Exception $e) {
                // se usa el formato de reserva
            }
        }
        if (method_exists('Tools', 'displayPrice')) {
            return call_user_func(['Tools', 'displayPrice'], (float) $amount, $cur);
        }

        return number_format((float) $amount, 2, ',', '.') . ' ' . $cur->sign;
    }
}
