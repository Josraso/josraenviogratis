<?php
/**
 * Regla de portes gratis / portes reducidos.
 *
 * @author    josra
 * @copyright josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraEnvioGratisRule extends ObjectModel
{
    const EFFECT_FREE = 0;
    const EFFECT_FIXED = 1;
    const EFFECT_PERCENT = 2;

    public $name;
    public $active = 1;
    public $priority = 1;
    public $effect = 0;
    public $effect_value = 0;
    public $show_badge = 0;

    public $amount_min = 0;
    public $amount_max = 0;
    public $amount_tax = 1;
    public $amount_shipping = 0;
    public $amount_discounts = 1;

    public $qty_min = 0;
    public $qty_max = 0;
    public $lines_min = 0;
    public $weight_min = 0;
    public $weight_max = 0;

    public $trig_products = '';
    public $trig_categories = '';
    public $trig_manufacturers = '';
    public $trig_suppliers = '';
    public $trig_mode = 0;
    public $trig_min_qty = 0;

    public $excl_products = '';
    public $excl_categories = '';
    public $excl_manufacturers = '';
    public $excl_suppliers = '';
    public $excl_mode = 0;

    public $carriers = '';
    public $zones = '';
    public $countries = '';
    public $states = '';

    public $groups = '';
    public $customer_type = 0;
    public $first_order = 0;

    public $date_from;
    public $date_to;
    public $days = '';
    public $time_from = '';
    public $time_to = '';
    public $shops = '';
    public $currencies = '';

    public $date_add;
    public $date_upd;

    /** @var array|string Texto público (multiidioma) */
    public $title;

    public static $definition = [
        'table' => 'josraenviogratis_rule',
        'primary' => 'id_rule',
        'multilang' => true,
        'fields' => [
            'name' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 128],
            'active' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'priority' => ['type' => self::TYPE_INT, 'validate' => 'isInt'],
            'effect' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'effect_value' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'show_badge' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],

            'amount_min' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'amount_max' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'amount_tax' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'amount_shipping' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'amount_discounts' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],

            'qty_min' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'qty_max' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'lines_min' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'weight_min' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'weight_max' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],

            'trig_products' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'trig_categories' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'trig_manufacturers' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'trig_suppliers' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'trig_mode' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'trig_min_qty' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],

            'excl_products' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'excl_categories' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'excl_manufacturers' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'excl_suppliers' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'excl_mode' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],

            'carriers' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'zones' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'countries' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'states' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],

            'groups' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'customer_type' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'first_order' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],

            'date_from' => ['type' => self::TYPE_DATE, 'validate' => 'isDateOrNull', 'allow_null' => true],
            'date_to' => ['type' => self::TYPE_DATE, 'validate' => 'isDateOrNull', 'allow_null' => true],
            'days' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 32],
            'time_from' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 5],
            'time_to' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 5],
            'shops' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'currencies' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],

            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],

            'title' => ['type' => self::TYPE_STRING, 'lang' => true, 'validate' => 'isString', 'size' => 255],
        ],
    ];

    /**
     * Esquema SQL. Lo usan install() y la autorreparación (ALTER de columnas que falten).
     *
     * @return array nombre_columna => definición
     */
    public static function getSqlColumns()
    {
        return [
            'name' => 'VARCHAR(128) NOT NULL DEFAULT \'\'',
            'active' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 1',
            'priority' => 'INT(11) NOT NULL DEFAULT 1',
            'effect' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'effect_value' => 'DECIMAL(20,6) NOT NULL DEFAULT 0',
            'show_badge' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'amount_min' => 'DECIMAL(20,6) NOT NULL DEFAULT 0',
            'amount_max' => 'DECIMAL(20,6) NOT NULL DEFAULT 0',
            'amount_tax' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 1',
            'amount_shipping' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'amount_discounts' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 1',
            'qty_min' => 'INT(10) UNSIGNED NOT NULL DEFAULT 0',
            'qty_max' => 'INT(10) UNSIGNED NOT NULL DEFAULT 0',
            'lines_min' => 'INT(10) UNSIGNED NOT NULL DEFAULT 0',
            'weight_min' => 'DECIMAL(20,6) NOT NULL DEFAULT 0',
            'weight_max' => 'DECIMAL(20,6) NOT NULL DEFAULT 0',
            'trig_products' => 'TEXT NULL',
            'trig_categories' => 'TEXT NULL',
            'trig_manufacturers' => 'TEXT NULL',
            'trig_suppliers' => 'TEXT NULL',
            'trig_mode' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'trig_min_qty' => 'INT(10) UNSIGNED NOT NULL DEFAULT 0',
            'excl_products' => 'TEXT NULL',
            'excl_categories' => 'TEXT NULL',
            'excl_manufacturers' => 'TEXT NULL',
            'excl_suppliers' => 'TEXT NULL',
            'excl_mode' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'carriers' => 'TEXT NULL',
            'zones' => 'TEXT NULL',
            'countries' => 'TEXT NULL',
            'states' => 'TEXT NULL',
            'groups' => 'TEXT NULL',
            'customer_type' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'first_order' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'date_from' => 'DATETIME NULL DEFAULT NULL',
            'date_to' => 'DATETIME NULL DEFAULT NULL',
            'days' => 'VARCHAR(32) NOT NULL DEFAULT \'\'',
            'time_from' => 'VARCHAR(5) NOT NULL DEFAULT \'\'',
            'time_to' => 'VARCHAR(5) NOT NULL DEFAULT \'\'',
            'shops' => 'TEXT NULL',
            'currencies' => 'TEXT NULL',
            'date_add' => 'DATETIME NOT NULL',
            'date_upd' => 'DATETIME NOT NULL',
        ];
    }
}
