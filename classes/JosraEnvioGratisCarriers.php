<?php
/**
 * Gestión de transportistas en PrestaShop 1.7 y 8 ("adopción").
 *
 * En 1.7/8 no existe el hook actionCartGetPackageShippingCost, así que para poder
 * cambiar el precio sin override ni reglas de carrito el transportista pasa a estar
 * calculado por este módulo: shipping_external = 1, need_range = 1 y
 * external_module_name = 'josraenviogratis'. PrestaShop sigue calculando con sus
 * rangos y le pasa ese precio al módulo, que lo devuelve igual o rebajado.
 * Los valores originales se guardan para restaurarlos al liberar o desinstalar.
 *
 * En PrestaShop 9 no se adopta nada (se usa el hook) y todo se libera.
 *
 * @author    josra
 * @copyright josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraEnvioGratisCarriers
{
    const MODULE = 'josraenviogratis';

    const TYPE_ADOPTED = 'adopted';
    const TYPE_NATIVE = 'native';
    const TYPE_MODULE_RANGES = 'module_ranges';
    const TYPE_MODULE_EXTERNAL = 'module_external';

    /**
     * Transportistas sin borrar, con los datos que hacen falta para clasificarlos.
     *
     * @param bool $withDeleted
     *
     * @return array
     */
    public static function listCarriers($withDeleted = false)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT c.`id_carrier`, c.`id_reference`, c.`name`, c.`active`, c.`deleted`, c.`is_free`,
                    c.`shipping_external`, c.`need_range`, c.`external_module_name`, c.`is_module`
             FROM `' . _DB_PREFIX_ . 'carrier` c
             ' . ($withDeleted ? '' : 'WHERE c.`deleted` = 0') . '
             ORDER BY c.`deleted` ASC, c.`position` ASC, c.`id_carrier` ASC'
        );
        if (!is_array($rows)) {
            return [];
        }
        foreach ($rows as &$row) {
            if ((string) $row['name'] === '0') {
                $row['name'] = (string) Configuration::get('PS_SHOP_NAME');
            }
            $row['type'] = self::classify($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array $row fila de ps_carrier
     *
     * @return string
     */
    public static function classify($row)
    {
        $ext = (string) $row['external_module_name'];
        if ($ext === self::MODULE) {
            return self::TYPE_ADOPTED;
        }
        if ((int) $row['shipping_external'] === 1) {
            return self::TYPE_MODULE_EXTERNAL;
        }
        if ($ext !== '') {
            return self::TYPE_MODULE_RANGES;
        }

        return self::TYPE_NATIVE;
    }

    /**
     * Tipo original de un transportista adoptado (para saber si era nativo o de otro módulo).
     *
     * @param int $idCarrier
     *
     * @return string
     */
    public static function originalType($idCarrier)
    {
        $orig = self::getOrig($idCarrier);
        if (!$orig || (string) $orig['orig_external_module_name'] === '') {
            return self::TYPE_NATIVE;
        }

        return self::TYPE_MODULE_RANGES;
    }

    /**
     * Preferencias por id_reference: 1 = gestionar, 0 = no tocar.
     *
     * @return array
     */
    public static function getModes()
    {
        $raw = Configuration::getGlobalValue('JEG_CARRIER_MODE');
        $modes = $raw ? json_decode($raw, true) : [];

        return is_array($modes) ? $modes : [];
    }

    public static function saveModes(array $modes)
    {
        $clean = [];
        foreach ($modes as $ref => $v) {
            $clean[(int) $ref] = (int) $v ? 1 : 0;
        }
        Configuration::updateGlobalValue('JEG_CARRIER_MODE', json_encode($clean));
    }

    /**
     * ¿Debe estar adoptado este transportista?
     *
     * @param array $row
     * @param array $modes
     *
     * @return bool
     */
    public static function wanted($row, $modes)
    {
        if (version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            return false;
        }
        $type = $row['type'];
        if ($type === self::TYPE_MODULE_EXTERNAL) {
            return false;
        }
        if ($type === self::TYPE_ADOPTED) {
            $type = self::originalType((int) $row['id_carrier']);
        }
        $ref = (int) $row['id_reference'];
        if (isset($modes[$ref])) {
            return (bool) $modes[$ref];
        }

        // Por defecto: los nativos sí, los creados por otros módulos no.
        return $type === self::TYPE_NATIVE;
    }

    /**
     * Deja todos los transportistas como deben estar según la versión y las preferencias.
     *
     * @return array resumen ['adopted' => n, 'released' => n]
     */
    public static function sync()
    {
        $res = ['adopted' => 0, 'released' => 0];
        if (version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            $res['released'] = self::releaseAll();

            return $res;
        }
        $modes = self::getModes();
        foreach (self::listCarriers(false) as $row) {
            $want = self::wanted($row, $modes);
            if ($row['type'] === self::TYPE_ADOPTED) {
                if (!$want) {
                    self::release((int) $row['id_carrier']);
                    ++$res['released'];
                } elseif ((int) $row['need_range'] !== 1 || (int) $row['shipping_external'] !== 1) {
                    // Alguien ha tocado los campos: se reparan.
                    Db::getInstance()->execute(
                        'UPDATE `' . _DB_PREFIX_ . 'carrier` SET `shipping_external` = 1, `need_range` = 1
                         WHERE `id_carrier` = ' . (int) $row['id_carrier']
                    );
                    self::ensureOrig($row);
                } else {
                    self::ensureOrig($row);
                }
            } elseif ($want && ($row['type'] === self::TYPE_NATIVE || $row['type'] === self::TYPE_MODULE_RANGES)) {
                self::adopt($row);
                ++$res['adopted'];
            }
        }

        return $res;
    }

    /**
     * Revisa un solo transportista (hooks de alta/edición de transportista).
     *
     * @param int $idCarrier
     */
    public static function syncOne($idCarrier)
    {
        if (version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            return;
        }
        $row = Db::getInstance()->getRow(
            'SELECT `id_carrier`, `id_reference`, `name`, `active`, `deleted`, `is_free`, `shipping_external`,
                    `need_range`, `external_module_name`, `is_module`
             FROM `' . _DB_PREFIX_ . 'carrier` WHERE `id_carrier` = ' . (int) $idCarrier
        );
        if (!$row) {
            return;
        }
        $row['type'] = self::classify($row);
        if ($row['type'] === self::TYPE_ADOPTED) {
            // Copia creada por PrestaShop al editar: hereda los valores originales de su referencia.
            self::ensureOrig($row);
            if ((int) $row['need_range'] !== 1 || (int) $row['shipping_external'] !== 1) {
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'carrier` SET `shipping_external` = 1, `need_range` = 1
                     WHERE `id_carrier` = ' . (int) $row['id_carrier']
                );
            }

            return;
        }
        if ((int) $row['deleted'] === 1) {
            return;
        }
        if (self::wanted($row, self::getModes())
            && ($row['type'] === self::TYPE_NATIVE || $row['type'] === self::TYPE_MODULE_RANGES)) {
            self::adopt($row);
        }
    }

    protected static function adopt($row)
    {
        $id = (int) $row['id_carrier'];
        Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . 'josraenviogratis_carrier`
                (`id_carrier`, `id_reference`, `orig_shipping_external`, `orig_need_range`, `orig_external_module_name`, `date_add`)
             VALUES (' . $id . ', ' . (int) $row['id_reference'] . ', ' . (int) $row['shipping_external'] . ', '
                . (int) $row['need_range'] . ', \'' . pSQL($row['external_module_name']) . '\', \'' . pSQL(date('Y-m-d H:i:s')) . '\')'
        );
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'carrier`
             SET `shipping_external` = 1, `need_range` = 1, `external_module_name` = \'' . pSQL(self::MODULE) . '\'
             WHERE `id_carrier` = ' . $id
        );
    }

    /**
     * Garantiza que un transportista adoptado tiene guardados sus valores originales.
     */
    protected static function ensureOrig($row)
    {
        $id = (int) $row['id_carrier'];
        if (self::getOrig($id)) {
            return;
        }
        $from = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josraenviogratis_carrier`
             WHERE `id_reference` = ' . (int) $row['id_reference'] . ' ORDER BY `id_carrier` DESC'
        );
        $origExt = $from ? (int) $from['orig_shipping_external'] : 0;
        $origNeed = $from ? (int) $from['orig_need_range'] : 0;
        $origName = $from ? (string) $from['orig_external_module_name'] : '';
        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'josraenviogratis_carrier`
                (`id_carrier`, `id_reference`, `orig_shipping_external`, `orig_need_range`, `orig_external_module_name`, `date_add`)
             VALUES (' . $id . ', ' . (int) $row['id_reference'] . ', ' . $origExt . ', ' . $origNeed . ', \''
                . pSQL($origName) . '\', \'' . pSQL(date('Y-m-d H:i:s')) . '\')'
        );
    }

    protected static function getOrig($idCarrier)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josraenviogratis_carrier` WHERE `id_carrier` = ' . (int) $idCarrier
        );
    }

    /**
     * Devuelve un transportista a como estaba antes de adoptarlo.
     *
     * @param int $idCarrier
     */
    public static function release($idCarrier)
    {
        $id = (int) $idCarrier;
        $orig = self::getOrig($id);
        if (!$orig) {
            $orig = Db::getInstance()->getRow(
                'SELECT j.* FROM `' . _DB_PREFIX_ . 'josraenviogratis_carrier` j
                 INNER JOIN `' . _DB_PREFIX_ . 'carrier` c ON (c.`id_reference` = j.`id_reference`)
                 WHERE c.`id_carrier` = ' . $id . ' ORDER BY j.`id_carrier` DESC'
            );
        }
        if ($orig) {
            $sql = 'UPDATE `' . _DB_PREFIX_ . 'carrier`
                SET `shipping_external` = ' . (int) $orig['orig_shipping_external'] . ',
                    `need_range` = ' . (int) $orig['orig_need_range'] . ',
                    `external_module_name` = \'' . pSQL($orig['orig_external_module_name']) . '\'
                WHERE `id_carrier` = ' . $id . ' AND `external_module_name` = \'' . pSQL(self::MODULE) . '\'';
        } else {
            $sql = 'UPDATE `' . _DB_PREFIX_ . 'carrier`
                SET `shipping_external` = 0, `external_module_name` = \'\'
                WHERE `id_carrier` = ' . $id . ' AND `external_module_name` = \'' . pSQL(self::MODULE) . '\'';
        }
        Db::getInstance()->execute($sql);
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'josraenviogratis_carrier` WHERE `id_carrier` = ' . $id
        );
    }

    /**
     * Libera todos los transportistas adoptados, también los borrados (históricos).
     *
     * @return int cuántos se han liberado
     */
    public static function releaseAll()
    {
        $ids = Db::getInstance()->executeS(
            'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
             WHERE `external_module_name` = \'' . pSQL(self::MODULE) . '\''
        );
        $n = 0;
        if (is_array($ids)) {
            foreach ($ids as $r) {
                self::release((int) $r['id_carrier']);
                ++$n;
            }
        }

        return $n;
    }

    /**
     * Transportistas que estaban adoptados y que su módulo original ha vuelto a reclamar
     * (external_module_name ya no es este módulo). Se quitan de la tabla de originales.
     *
     * @return array [['name' => ..., 'module' => ...]]
     */
    public static function reclaimed()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT j.`id_carrier`, c.`name`, c.`external_module_name`, c.`deleted`
             FROM `' . _DB_PREFIX_ . 'josraenviogratis_carrier` j
             LEFT JOIN `' . _DB_PREFIX_ . 'carrier` c ON (c.`id_carrier` = j.`id_carrier`)
             WHERE c.`id_carrier` IS NULL OR c.`external_module_name` <> \'' . pSQL(self::MODULE) . '\''
        );
        $out = [];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $r) {
            if ($r['name'] !== null && !(int) $r['deleted'] && (string) $r['external_module_name'] !== '') {
                $out[] = [
                    'name' => (string) $r['name'] === '0' ? (string) Configuration::get('PS_SHOP_NAME') : (string) $r['name'],
                    'module' => (string) $r['external_module_name'],
                ];
            }
            Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'josraenviogratis_carrier` WHERE `id_carrier` = ' . (int) $r['id_carrier']
            );
        }

        return $out;
    }

    /**
     * id_carrier => id_reference (incluye borrados, para pedidos antiguos y copias).
     *
     * @return array
     */
    public static function referenceMap()
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            $rows = Db::getInstance()->executeS(
                'SELECT `id_carrier`, `id_reference` FROM `' . _DB_PREFIX_ . 'carrier`'
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $map[(int) $r['id_carrier']] = (int) $r['id_reference'] ?: (int) $r['id_carrier'];
                }
            }
        }

        return $map;
    }

    /**
     * @param int $idCarrier
     *
     * @return int
     */
    public static function referenceOf($idCarrier)
    {
        $map = self::referenceMap();
        $id = (int) $idCarrier;
        if (isset($map[$id])) {
            return $map[$id];
        }
        $ref = (int) Db::getInstance()->getValue(
            'SELECT `id_reference` FROM `' . _DB_PREFIX_ . 'carrier` WHERE `id_carrier` = ' . $id
        );

        return $ref ?: $id;
    }
}
