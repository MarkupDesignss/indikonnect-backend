<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttributeSeeder extends Seeder
{
    public function run(): void
    {
        // Insert attribute masters
        $colorId = DB::table('attribute_masters')->insertGetId([
            'attribute_key' => 'color',
            'is_required' => 1,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sizeId = DB::table('attribute_masters')->insertGetId([
            'attribute_key' => 'size',
            'is_required' => 1,
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $materialId = DB::table('attribute_masters')->insertGetId([
            'attribute_key' => 'material',
            'is_required' => 0,
            'sort_order' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purityId = DB::table('attribute_masters')->insertGetId([
            'attribute_key' => 'purity',
            'is_required' => 0,
            'sort_order' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $weightId = DB::table('attribute_masters')->insertGetId([
            'attribute_key' => 'weight',
            'is_required' => 0,
            'sort_order' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert values for color
        DB::table('attribute_values')->insert([
            ['attribute_master_id' => $colorId, 'value' => 'Red', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $colorId, 'value' => 'Blue', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $colorId, 'value' => 'Green', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $colorId, 'value' => 'Black', 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $colorId, 'value' => 'White', 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $colorId, 'value' => 'Gold', 'sort_order' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $colorId, 'value' => 'Silver', 'sort_order' => 7, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $colorId, 'value' => 'Rose Gold', 'sort_order' => 8, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Insert values for size
        DB::table('attribute_values')->insert([
            ['attribute_master_id' => $sizeId, 'value' => 'XS', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $sizeId, 'value' => 'S', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $sizeId, 'value' => 'M', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $sizeId, 'value' => 'L', 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $sizeId, 'value' => 'XL', 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $sizeId, 'value' => 'XXL', 'sort_order' => 6, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Insert values for material
        DB::table('attribute_values')->insert([
            ['attribute_master_id' => $materialId, 'value' => 'Cotton', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $materialId, 'value' => 'Polyester', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $materialId, 'value' => 'Silk', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $materialId, 'value' => 'Wool', 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $materialId, 'value' => 'Leather', 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Insert values for purity
        DB::table('attribute_values')->insert([
            ['attribute_master_id' => $purityId, 'value' => '22K', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $purityId, 'value' => '18K', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $purityId, 'value' => '24K', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $purityId, 'value' => '14K', 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Insert values for weight
        DB::table('attribute_values')->insert([
            ['attribute_master_id' => $weightId, 'value' => '5g', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $weightId, 'value' => '10g', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $weightId, 'value' => '15g', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $weightId, 'value' => '20g', 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['attribute_master_id' => $weightId, 'value' => '30g', 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}