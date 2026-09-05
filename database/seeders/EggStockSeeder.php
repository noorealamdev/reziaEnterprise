<?php

namespace Database\Seeders;

use App\Models\EggSale;
use App\Models\TiffinItem;
use App\Models\TiffinItemPurchase;
use Illuminate\Database\Seeder;

class EggStockSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds a trailing-30-day window of Egg purchases and a handful of
     * external sales, so the Egg Stock & Purchases page (Purchases /
     * Sales tabs + the stock summary strip) has realistic data to browse
     * without waiting for real usage. Purchased quantity is deliberately
     * kept comfortably above JobEntrySeeder's Tiffin consumption plus
     * these sales, so "In Stock Now" lands on a small positive number
     * rather than zero or negative.
     */
    public function run(): void
    {
        $egg = TiffinItem::where('name', 'Egg')->first();

        if (! $egg) {
            return;
        }

        $today = now();

        // One bulk buy roughly every 3 days over the trailing 30 days —
        // matches how JobEntrySeeder windows its Tiffin entries, so both
        // seeders describe the same stretch of business activity.
        $purchases = [
            ['daysAgo' => 29, 'quantity' => 280, 'costRate' => 11.00, 'supplier' => 'Karim Traders'],
            ['daysAgo' => 26, 'quantity' => 260, 'costRate' => 11.20, 'supplier' => 'Bismillah Poultry'],
            ['daysAgo' => 23, 'quantity' => 300, 'costRate' => 10.80, 'supplier' => 'Karim Traders'],
            ['daysAgo' => 20, 'quantity' => 270, 'costRate' => 11.50, 'supplier' => 'Bismillah Poultry'],
            ['daysAgo' => 17, 'quantity' => 290, 'costRate' => 12.00, 'supplier' => 'Karim Traders'],
            ['daysAgo' => 14, 'quantity' => 260, 'costRate' => 11.80, 'supplier' => 'Bismillah Poultry'],
            ['daysAgo' => 11, 'quantity' => 280, 'costRate' => 11.30, 'supplier' => 'Karim Traders'],
            ['daysAgo' => 8, 'quantity' => 300, 'costRate' => 11.60, 'supplier' => 'Bismillah Poultry'],
            ['daysAgo' => 5, 'quantity' => 270, 'costRate' => 12.20, 'supplier' => 'Karim Traders'],
            ['daysAgo' => 2, 'quantity' => 290, 'costRate' => 11.90, 'supplier' => 'Bismillah Poultry'],
        ];

        foreach ($purchases as $purchase) {
            $date = $today->copy()->subDays($purchase['daysAgo'])->toDateString();

            TiffinItemPurchase::updateOrCreate(
                ['tiffin_item_id' => $egg->id, 'purchase_date' => $date],
                [
                    'quantity' => $purchase['quantity'],
                    'cost_rate' => $purchase['costRate'],
                    'cost_amount' => round($purchase['quantity'] * $purchase['costRate'], 2),
                    'supplier_name' => $purchase['supplier'],
                ]
            );
        }

        // A handful of sales to outside buyers, spread across the same
        // window — small enough relative to the purchases above that
        // stock on hand stays comfortably positive.
        $sales = [
            ['daysAgo' => 23, 'quantity' => 100, 'saleRate' => 14.00, 'buyer' => 'Local Market Vendor'],
            ['daysAgo' => 15, 'quantity' => 80, 'saleRate' => 14.50, 'buyer' => 'Rahim Store'],
            ['daysAgo' => 8, 'quantity' => 120, 'saleRate' => 14.00, 'buyer' => 'Local Market Vendor'],
            ['daysAgo' => 3, 'quantity' => 90, 'saleRate' => 15.00, 'buyer' => 'Corner Bakery'],
            ['daysAgo' => 1, 'quantity' => 60, 'saleRate' => 15.00, 'buyer' => 'Rahim Store'],
        ];

        foreach ($sales as $sale) {
            $date = $today->copy()->subDays($sale['daysAgo'])->toDateString();

            EggSale::updateOrCreate(
                ['sale_date' => $date, 'buyer_name' => $sale['buyer']],
                [
                    'quantity' => $sale['quantity'],
                    'sale_rate' => $sale['saleRate'],
                    'sale_amount' => round($sale['quantity'] * $sale['saleRate'], 2),
                ]
            );
        }
    }
}
