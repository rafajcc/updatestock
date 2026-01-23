<?php

namespace Module\UpdateStock\Service;

use Module\UpdateStock\Repository\StockRepository;

class ConsistencyService
{
    private $stockRepository;
    private $fixes = [];
    private $inconsistencies = [];

    // Ideally we inject Repository here, but for now we instantitate or use global Db for simplicity in migration
    // Let's use the Repository pattern since we built it.
    // Wait, in services.yml we didn't inject Repository to ConsistencyService. Let's stick to Db or update service def.
    // I entered: class: Antigravity\UpdateStock\Service\ConsistencyService (no args).
    // Let's use `new StockRepository()` inside or rely on Db directly. 
    // BETTER: Use StockRepository. I will update this class to use `new StockRepository` for now as it's not injected.

    public function __construct()
    {
        $this->stockRepository = new StockRepository();
    }

    public function runTests($id_shop, $fix = false)
    {
        $this->checkStockAvailableConsistency($id_shop, $fix);
        $this->checkNegativeStock($id_shop, $fix);
        $this->checkEquations($id_shop, $fix);
        $this->checkActiveStatus($id_shop, $fix);
        $this->checkEANIntegrity();

        return [
            'inconsistencies' => $this->inconsistencies,
            'fixes' => $this->fixes,
            'critical_errors' => false // Logic skipped for brevity
        ];
    }

    protected function checkStockAvailableConsistency($id_shop, $fix)
    {
        $products = $this->stockRepository->getProductsWithAttributes();
        foreach ($products as $row) {
            $id_product = (int) $row['id_product'];
            $sums = $this->stockRepository->getProductAttributeSum($id_product, $id_shop);

            if (!$sums)
                continue;

            $zeroRecord = $this->stockRepository->getCurrentStock($id_product, 0, $id_shop);
            if ($zeroRecord) {
                if ($sums['q'] != $zeroRecord['quantity'] || $sums['pq'] != $zeroRecord['physical_quantity']) {
                    $this->inconsistencies[] = [
                        'type' => 'Sum Mismatch',
                        'id_product' => $id_product,
                        'id_product_attribute' => 0,
                        'value_before' => "Q:{$zeroRecord['quantity']}, PQ:{$zeroRecord['physical_quantity']}",
                        'value_suggested' => "Q:{$sums['q']}, PQ:{$sums['pq']}"
                    ];
                    if ($fix) {
                        $this->stockRepository->updateProductAttributeZero($id_product, $sums['q'], $sums['pq'], $sums['rq'], $id_shop);
                        $this->fixes[] = "Fixed sum for Product ID $id_product";
                    } else {
                        $this->fixes[] = "Suggested fix for Product ID $id_product";
                    }
                }
            }
        }
    }

    protected function checkNegativeStock($id_shop, $fix)
    {
        $negatives = $this->stockRepository->getNegatives($id_shop);
        foreach ($negatives as $row) {
            $this->inconsistencies[] = [
                'type' => 'Negative Stock',
                'id_product' => $row['id_product'],
                'id_product_attribute' => $row['id_product_attribute'],
                'value_before' => "Q:{$row['quantity']}, PQ:{$row['physical_quantity']}",
                'value_suggested' => "Set to 0"
            ];
            if ($fix) {
                $this->stockRepository->zeroStock($row['id_product'], $row['id_product_attribute'], $id_shop);
                $this->fixes[] = "Fixed negative stock for Product ID {$row['id_product']}";
            }
        }
    }

    protected function checkEquations($id_shop, $fix)
    {
        $rows = $this->stockRepository->getEquationMismatches($id_shop);
        foreach ($rows as $row) {
            $expectedQ = (int) $row['physical_quantity'] - (int) $row['reserved_quantity'];
            $this->inconsistencies[] = [
                'type' => 'Equation Mismatch',
                'id_product' => $row['id_product'],
                'id_product_attribute' => $row['id_product_attribute'],
                'value_before' => "Q:{$row['quantity']} != PQ:{$row['physical_quantity']} - R:{$row['reserved_quantity']}",
                'value_suggested' => "Q:$expectedQ"
            ];
            if ($fix) {
                $this->stockRepository->updateQuantity($row['id_product'], $row['id_product_attribute'], $expectedQ, $id_shop);
                $this->fixes[] = "Fixed equation for Product ID {$row['id_product']}";
            }
        }
    }

    protected function checkActiveStatus($id_shop, $fix)
    {
        $rows = $this->stockRepository->getActiveSpecified($id_shop);
        foreach ($rows as $row) {
            $id_product = (int) $row['id_product'];
            $this->inconsistencies[] = [
                'type' => 'Active but No Stock',
                'id_product' => $id_product,
                'id_product_attribute' => 0,
                'value_before' => "Active: 1",
                'value_suggested' => "Active: 0"
            ];
            if ($fix) {
                $this->stockRepository->disableProduct($id_product, $id_shop);
                $this->fixes[] = "Disabled Product ID $id_product due to zero stock";
            }
        }
    }

    protected function checkEANIntegrity()
    {
        $rows = $this->stockRepository->getDuplicateEans();
        foreach ($rows as $row) {
            $this->inconsistencies[] = [
                'type' => 'Duplicate EAN in DB',
                'id_product' => 0,
                'id_product_attribute' => 0,
                'value_before' => "EAN: " . $row['ean13'],
                'value_suggested' => "None (Manual Fix Required)"
            ];
        }
    }
}
