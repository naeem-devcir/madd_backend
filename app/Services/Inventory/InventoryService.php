<?php

namespace App\Services\Inventory;

use App\Models\Product\VendorProduct;

class InventoryService
{
    public function deductStock(string $productId, int|float $quantity): bool
    {
        $product = VendorProduct::find($productId);

        if (!$product) {
            return false;
        }

        $current = (int) ($product->quantity ?? 0);
        $product->quantity = max(0, $current - (int) $quantity);
        $product->save();

        return true;
    }

    public function restoreStock(string $productId, int|float $quantity): bool
    {
        $product = VendorProduct::find($productId);

        if (!$product) {
            return false;
        }

        $product->quantity = (int) ($product->quantity ?? 0) + (int) $quantity;
        $product->save();

        return true;
    }

    public function getCurrentStock(string $productId): int
    {
        return (int) (VendorProduct::find($productId)?->quantity ?? 0);
    }
}
