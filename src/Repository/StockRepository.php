<?php

namespace Antigravity\UpdateStock\Repository;

use Db;
use Product;

class StockRepository
{
    protected $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    public function getProductByEan($ean)
    {
        $sql = 'SELECT pa.id_product, pa.id_product_attribute 
                FROM ' . _DB_PREFIX_ . 'product_attribute pa 
                WHERE pa.ean13 = "' . pSQL($ean) . '"';
        $result = $this->db->getRow($sql);

        if (!$result) {
            $sql = 'SELECT p.id_product, 0 as id_product_attribute
                    FROM ' . _DB_PREFIX_ . 'product p 
                    WHERE p.ean13 = "' . pSQL($ean) . '"';
            $result = $this->db->getRow($sql);
        }

        return $result;
    }

    public function getCurrentStock($id_product, $id_product_attribute, $id_shop = null)
    {
        $sql = 'SELECT quantity, physical_quantity, reserved_quantity FROM ' . _DB_PREFIX_ . 'stock_available 
                WHERE id_product = ' . (int) $id_product . ' 
                AND id_product_attribute = ' . (int) $id_product_attribute;

        if ($id_shop) {
            $sql .= ' AND id_shop = ' . (int) $id_shop;
        }

        return $this->db->getRow($sql);
    }

    public function updateStock($id_product, $id_product_attribute, $id_shop, $finalQty, $physicalQty, $reserved)
    {
        $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'stock_available (id_product, id_product_attribute, id_shop, id_shop_group, quantity, physical_quantity, reserved_quantity, depends_on_stock, out_of_stock)
                VALUES (' . (int) $id_product . ', ' . (int) $id_product_attribute . ', ' . (int) $id_shop . ', 0, ' . (int) $finalQty . ', ' . (int) $physicalQty . ', ' . (int) $reserved . ', 0, 2)
                ON DUPLICATE KEY UPDATE quantity = ' . (int) $finalQty . ', physical_quantity = ' . (int) $physicalQty;

        return $this->db->execute($sql);
    }

    public function updateStockGlobal($id_product, $id_product_attribute, $finalQty, $physicalQty)
    {
        return $this->db->execute('UPDATE ' . _DB_PREFIX_ . 'stock_available 
                                   SET quantity = ' . (int) $finalQty . ', physical_quantity = ' . (int) $physicalQty . '
                                   WHERE id_product = ' . (int) $id_product . ' AND id_product_attribute = ' . (int) $id_product_attribute);
    }

    public function getProductAttributeSum($id_product, $id_shop = null)
    {
        $sql = 'SELECT SUM(quantity) as q, SUM(physical_quantity) as pq, SUM(reserved_quantity) as rq 
                FROM ' . _DB_PREFIX_ . 'stock_available 
                WHERE id_product = ' . (int) $id_product . ' AND id_product_attribute != 0';

        if ($id_shop) {
            $sql .= ' AND id_shop = ' . (int) $id_shop;
        }

        return $this->db->getRow($sql);
    }

    public function updateProductAttributeZero($id_product, $q, $pq, $rq, $id_shop = null)
    {
        $sql = 'UPDATE ' . _DB_PREFIX_ . 'stock_available 
                SET quantity = ' . (int) $q . ', physical_quantity = ' . (int) $pq . ', reserved_quantity = ' . (int) $rq . '
                WHERE id_product = ' . (int) $id_product . ' AND id_product_attribute = 0';

        if ($id_shop) {
            $sql .= ' AND id_shop = ' . (int) $id_shop;
        }

        return $this->db->execute($sql);
    }

    public function getProductsNotIn($ids, $id_shop = null)
    {
        if (empty($ids))
            $ids = '0';

        $sql = 'SELECT id_product, id_product_attribute, quantity, reserved_quantity 
                FROM ' . _DB_PREFIX_ . 'stock_available 
                WHERE id_product NOT IN (' . $ids . ')';

        if ($id_shop) {
            $sql .= ' AND id_shop = ' . (int) $id_shop;
        }

        return $this->db->executeS($sql);
    }

    public function disableProduct($id_product, $id_shop = null)
    {
        $this->db->execute('UPDATE ' . _DB_PREFIX_ . 'product SET active = 0 WHERE id_product = ' . (int) $id_product);

        $sql = 'UPDATE ' . _DB_PREFIX_ . 'product_shop SET active = 0 WHERE id_product = ' . (int) $id_product;
        if ($id_shop) {
            $sql .= ' AND id_shop = ' . (int) $id_shop;
        }
        $this->db->execute($sql);
    }

    // Consistency Queries
    public function getProductsWithAttributes()
    {
        return $this->db->executeS('SELECT id_product FROM ' . _DB_PREFIX_ . 'product_attribute GROUP BY id_product');
    }

    public function getNegatives($id_shop)
    {
        return $this->db->executeS('SELECT id_product, id_product_attribute, quantity, physical_quantity 
                                    FROM ' . _DB_PREFIX_ . 'stock_available 
                                    WHERE (quantity < 0 OR physical_quantity < 0) 
                                    AND id_shop = ' . (int) $id_shop);
    }

    public function zeroStock($id_product, $id_pa, $id_shop)
    {
        return $this->db->execute('UPDATE ' . _DB_PREFIX_ . 'stock_available 
                                   SET quantity = 0, physical_quantity = 0 
                                   WHERE id_product = ' . (int) $id_product . ' 
                                   AND id_product_attribute = ' . (int) $id_pa . ' 
                                   AND id_shop = ' . (int) $id_shop);
    }

    public function getEquationMismatches($id_shop)
    {
        return $this->db->executeS('SELECT id_product, id_product_attribute, quantity, physical_quantity, reserved_quantity 
                                    FROM ' . _DB_PREFIX_ . 'stock_available 
                                    WHERE quantity != (physical_quantity - reserved_quantity)
                                    AND id_shop = ' . (int) $id_shop);
    }

    public function updateQuantity($id_product, $id_pa, $qty, $id_shop)
    {
        return $this->db->execute('UPDATE ' . _DB_PREFIX_ . 'stock_available 
                                   SET quantity = ' . (int) $qty . '
                                   WHERE id_product = ' . (int) $id_product . ' 
                                   AND id_product_attribute = ' . (int) $id_pa . ' 
                                   AND id_shop = ' . (int) $id_shop);
    }

    public function getActiveSpecified($id_shop)
    {
        return $this->db->executeS('SELECT sa.id_product 
                     FROM ' . _DB_PREFIX_ . 'stock_available sa
                     JOIN ' . _DB_PREFIX_ . 'product_shop ps ON sa.id_product = ps.id_product AND sa.id_shop = ps.id_shop
                     WHERE sa.id_product_attribute = 0 
                     AND sa.quantity <= 0 
                     AND ps.active = 1
                     AND sa.id_shop = ' . (int) $id_shop);
    }

    public function getDuplicateEans()
    {
        return $this->db->executeS('SELECT ean13, count(*) as c 
                FROM ' . _DB_PREFIX_ . 'product_attribute 
                WHERE ean13 != "" 
                GROUP BY ean13 
                HAVING c > 1');
    }
}
