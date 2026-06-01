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
