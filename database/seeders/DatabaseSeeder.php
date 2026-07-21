<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // -- Permissions: superset matching FE menu/feature names ----------
        // Laravel-style (kebab:action) — used by CheckPermission middleware.
        $permissionNames = [
            // master data
            'currencies:read', 'currencies:create', 'currencies:update', 'currencies:delete',
            'companies:read', 'companies:create', 'companies:update', 'companies:delete',
            'company-types:read', 'company-types:create', 'company-types:update', 'company-types:delete',
            'items:read', 'items:create', 'items:update', 'items:delete',
            'office-codes:read', 'office-codes:create', 'office-codes:update', 'office-codes:delete',

            // auth & audit
            'users:read', 'users:create', 'users:update', 'users:delete', 'users:reset-password',
            'user-groups:read', 'user-groups:create', 'user-groups:update', 'user-groups:delete',
            'permissions:read',
            'activity-logs:read',

            // (placeholders for transactional modules wired later)
            'incomings:read', 'incomings:create', 'incomings:update', 'incomings:delete',
            'outgoings:read', 'outgoings:create', 'outgoings:update', 'outgoings:delete',
            'productions:read', 'productions:create', 'productions:update', 'productions:delete',
            'bill-of-materials:read', 'bill-of-materials:create', 'bill-of-materials:update', 'bill-of-materials:delete',
            'orders:read', 'orders:create', 'orders:update', 'orders:delete',
            'stocks-opname:read', 'stocks-opname:create', 'stocks-opname:update', 'stocks-opname:delete',
            'reports:read',
            'bclkt:read', 'bclkt:create', 'bclkt:update', 'bclkt:delete',

            // Go-style (snake_case.action) — used by FE sidebar PermissionWrapper.
            // Master Data
            'currency.index', 'currency.create', 'currency.update', 'currency.delete',
            'companies.index', 'companies.create', 'companies.update', 'companies.delete',
            'items.index', 'items.create', 'items.update', 'items.delete',
            'production_line.index',
            'data_support.index',

            // User management
            'manage_user.index', 'manage_user.create', 'manage_user.update', 'manage_user.delete',
            'manage_user_group.index', 'manage_user_group.create', 'manage_user_group.update', 'manage_user_group.delete',
            'permissions.index',
            'user_activity.index',

            // Bill of Material
            'bill_of_material.index', 'bill_of_material.create', 'bill_of_material.update', 'bill_of_material.delete',

            // Orders
            'purchase_order.index', 'purchase_order.create', 'purchase_order.update', 'purchase_order.delete',
            'sales_order.index', 'sales_order.create', 'sales_order.update', 'sales_order.delete',

            // Incoming
            'incoming_materials.index', 'incoming_materials.create', 'incoming_materials.update', 'incoming_materials.delete',
            'incoming_materials_sub_contract.index',
            'incoming_finished_goods.index',
            'incoming_machine_and_equipment.index',

            // Production
            'production_finished_goods.index', 'production_finished_goods.create', 'production_finished_goods.update', 'production_finished_goods.delete',
            'production_scrap.index', 'production_scrap.create', 'production_scrap.update', 'production_scrap.delete',
            'production_wip.index',
            'production_machine_and_equipment.index',

            // Outgoing
            'outgoing_materials.index',
            'outgoing_finished_goods.index', 'outgoing_finished_goods.create', 'outgoing_finished_goods.update', 'outgoing_finished_goods.delete',
            'outgoing_machine_and_equipment.index',
            'outgoing_scrap.index',
            'outgoing_wip.index', 'outgoing_wip.create', 'outgoing_wip.update', 'outgoing_wip.delete',

            // Stock Opname
            'stock_opname_materials.index', 'stock_opname_materials.create', 'stock_opname_materials.update', 'stock_opname_materials.delete',
            'stock_opname_finished_goods.index', 'stock_opname_finished_goods.create', 'stock_opname_finished_goods.update', 'stock_opname_finished_goods.delete',
            'stock_opname_machine_and_equipments.index',
            'stock_opname_scrap.index',
            'stock_opname_wip.index', 'stock_opname_wip.create', 'stock_opname_wip.update', 'stock_opname_wip.delete',

            // Reports
            'report.index',

            // Misc
            'use_raw_material.index',
            'subcontract_goods.index',
        ];

        $now = now();
        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name], [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // -- Admin group with all permissions ------------------------------
        $admin = UserGroup::firstOrCreate(['name' => 'Administrator']);
        $admin->permissions()->sync(Permission::pluck('id')->all());

        // -- Operator group: read everything + transactional CRUD ----------
        $operator = UserGroup::firstOrCreate(['name' => 'Operator']);
        $operator->permissions()->sync(
            Permission::where('name', 'like', '%:read')
                ->orWhereIn('name', [
                    'incomings:create', 'incomings:update',
                    'productions:create', 'productions:update',
                    'outgoings:create', 'outgoings:update',
                ])
                ->pluck('id')
                ->all()
        );

        // -- Default admin user --------------------------------------------
        User::updateOrCreate(
            ['email' => 'admin@furukawa.local'],
            [
                'username' => 'admin',
                'password' => '', // legacy column unused for fresh accounts
                'password_hash' => Hash::make('admin12345'),
                'password_migrated_at' => now(),
                'must_change_password' => false,
                'user_group_id' => $admin->id,
            ]
        );

        $this->command->info('Seeded: Administrator + Operator groups, admin@furukawa.local / admin12345');
    }
}
