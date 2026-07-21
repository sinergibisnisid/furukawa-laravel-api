<?php

namespace Database\Seeders;

use App\Models\BillOfMaterial;
use App\Models\BillOfMaterialDetail;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Currency;
use App\Models\Item;
use App\Models\OfficeCode;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Currencies
        Currency::firstOrCreate(['name' => 'IDR'], ['description' => 'Indonesian Rupiah', 'exchange_rate' => 1]);
        Currency::firstOrCreate(['name' => 'USD'], ['description' => 'US Dollar', 'exchange_rate' => 15000]);
        Currency::firstOrCreate(['name' => 'JPY'], ['description' => 'Japanese Yen', 'exchange_rate' => 100]);

        // 2. Company Types
        $typeSupplier = CompanyType::firstOrCreate(['name' => 'Supplier']);
        $typeBuyer = CompanyType::firstOrCreate(['name' => 'Buyer']);

        // 3. Office Codes
        OfficeCode::firstOrCreate(['name' => '01']);
        OfficeCode::firstOrCreate(['name' => '02']);

        // 4. Companies
        $supplier = Company::firstOrCreate(
            ['code' => 'SUP-001'],
            [
                'name' => 'PT Mitra Supplier Baku',
                'tax_number_id' => '12.345.678.9-000.000',
                'address' => 'Jl. Industri No. 1, Cikarang',
                'country' => 'Indonesia',
                'telephone' => '021-1234567',
                'fax_number' => '021-1234567',
                'currency' => 'IDR',
                'is_internal' => false,
            ]
        );
        \Illuminate\Support\Facades\DB::table('company_type_links')->insertOrIgnore([
            'company_id' => $supplier->id,
            'company_type_id' => $typeSupplier->id,
        ]);

        $buyer = Company::firstOrCreate(
            ['code' => 'BUY-001'],
            [
                'name' => 'PT Global Buyer Utama',
                'tax_number_id' => '98.765.432.1-000.000',
                'address' => 'Jl. Sudirman No. 10, Jakarta',
                'country' => 'Indonesia',
                'telephone' => '021-7654321',
                'fax_number' => '021-7654321',
                'currency' => 'IDR',
                'is_internal' => false,
            ]
        );
        \Illuminate\Support\Facades\DB::table('company_type_links')->insertOrIgnore([
            'company_id' => $buyer->id,
            'company_type_id' => $typeBuyer->id,
        ]);

        // 5. Items
        $rm1 = Item::firstOrCreate(
            ['code' => 'RM-001'],
            [
                'part_no' => 'P-RM-001',
                'name' => 'Plastik PP (Raw Material)',
                'type' => 'Raw Material',
                'uom' => 'KG',
                'price' => 15000,
                'currency' => 'IDR',
                'created_by' => 1,
            ]
        );

        $rm2 = Item::firstOrCreate(
            ['code' => 'RM-002'],
            [
                'part_no' => 'P-RM-002',
                'name' => 'Zat Pewarna Merah (Raw Material)',
                'type' => 'Raw Material',
                'uom' => 'LITER',
                'price' => 50000,
                'currency' => 'IDR',
                'created_by' => 1,
            ]
        );

        $fg1 = Item::firstOrCreate(
            ['code' => 'FG-001'],
            [
                'part_no' => 'P-FG-001',
                'name' => 'Ember Plastik Merah (Finished Goods)',
                'type' => 'Finished Goods',
                'uom' => 'PCS',
                'price' => 35000,
                'currency' => 'IDR',
                'created_by' => 1,
            ]
        );

        // 6. Bill Of Material (BOM)
        $bom = BillOfMaterial::firstOrCreate(
            ['no' => 'BOM-001'],
            [
                'date' => now(),
                'company_id' => $buyer->id,
                'finished_good_name' => 'Ember Plastik Merah',
                'finished_good_id' => $fg1->id,
                'feature' => 'Finished Goods',
                'quantity' => 1,
            ]
        );

        // BOM Details: 1 FG requires 0.5 KG PP and 0.01 Liter Pewarna
        BillOfMaterialDetail::firstOrCreate(
            [
                'bill_of_material_id' => $bom->id,
                'item_id' => $rm1->id,
            ],
            [
                'quantity' => 0.5,
            ]
        );

        BillOfMaterialDetail::firstOrCreate(
            [
                'bill_of_material_id' => $bom->id,
                'item_id' => $rm2->id,
            ],
            [
                'quantity' => 0.01,
            ]
        );

        $this->command->info('Master Data Seeded: Currencies, Companies, Items (RM & FG), and 1 BOM.');
    }
}
