<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema awal hasil port dari go-furukawa-api.
 *
 * Squash 90+ file migration project lama jadi satu schema otoritatif.
 * Nama kolom, tipe, dan constraint dijaga identik supaya backend Laravel
 * bisa langsung jalan di atas database produksi yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // Master data
        // ============================================================
        Schema::create('currencies', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('description');
            $table->float('exchange_rate');
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->string('updated_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by')->nullable();
        });

        Schema::create('company_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code')->unique();
            $table->string('name');
            $table->string('tax_number_id');
            $table->string('address');
            $table->string('country');
            $table->string('fax_number');
            $table->string('telephone');
            $table->string('currency');
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->string('updated_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by')->nullable();
            $table->boolean('is_internal')->default(false);
        });

        Schema::create('company_type_links', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id');
            $table->unsignedInteger('company_type_id');
            $table->primary(['company_id', 'company_type_id']);
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('company_type_id')->references('id')->on('company_types');
        });

        Schema::create('items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('currency');
            $table->string('code')->unique();
            $table->string('part_no')->nullable();
            $table->string('name');
            $table->string('type');
            $table->string('uom');
            $table->float('price');
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->string('updated_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by')->nullable();
        });

        Schema::create('office_codes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->default('');
            $table->string('name')->default('');
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->string('updated_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by')->nullable();
        });

        Schema::create('packagings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code');
            $table->string('description');
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->string('updated_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by')->nullable();
        });

        Schema::create('outgoing_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        // ============================================================
        // Auth (users / groups / permissions)
        // ============================================================
        Schema::create('user_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('user_group_permissions', function (Blueprint $table) {
            $table->unsignedInteger('user_group_id');
            $table->unsignedInteger('permission_id');
            $table->index('user_group_id');
            $table->index('permission_id');
            $table->foreign('user_group_id')->references('id')->on('user_groups');
            $table->foreign('permission_id')->references('id')->on('permissions');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('username');
            $table->string('email')->unique();
            // legacy plaintext password column (kept for lazy migration);
            // bcrypt hash is stored in `password_hash` going forward.
            $table->string('password');
            $table->string('password_hash')->nullable();
            $table->timestamp('password_migrated_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->unsignedInteger('user_group_id');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->rememberToken();
            $table->index('user_group_id');
            $table->foreign('user_group_id')->references('id')->on('user_groups');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('user_email');
            $table->string('activity_type', 50);
            $table->string('activity_name', 100);
            $table->text('activity_description');
            $table->timestamp('activity_timestamp')->useCurrent();
            $table->string('ip_address', 45);
            $table->string('user_agent');
            $table->index('user_email');
        });

        // ============================================================
        // BCLKT (Customs documents)
        // ============================================================
        Schema::create('bclkt_finished_goods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('bj_serial_number');
            $table->string('bj_application_number');
            $table->string('bj_application_registration_number');
            $table->string('bj_registration_number');
            $table->date('bj_registration_date');
            $table->string('bj_office_code');
            $table->string('bj_item_series');
            $table->float('bj_quantity');
        });

        Schema::create('bclkt_raw_materials', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('bb_no');
            $table->string('bb_serial_number');
            $table->string('bb_application_number');
            $table->string('bb_registration_number');
            $table->date('bb_registration_date');
            $table->string('bb_office_code');
            $table->string('bb_item_series');
            $table->float('bb_quantity');
            $table->float('bb_waste_percentage');
            $table->string('bb_waste_physical_form');
        });

        // ============================================================
        // Transactions
        // ============================================================
        Schema::create('outgoings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('currency')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('outgoing_no')->nullable();
            $table->date('outgoing_date')->nullable();
            $table->string('feature');
            $table->string('outgoing_type')->default('');
            $table->string('peb_no')->default('');
            $table->date('peb_date')->nullable();
            $table->string('application_number')->nullable();
            $table->string('application_registration_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->date('registration_date')->nullable();
            $table->unsignedInteger('office_code_id')->nullable();
            $table->float('total_quantity')->nullable();
            $table->string('item_series')->default('');
            $table->string('travel_letter_number')->default('');
            $table->date('travel_letter_date')->nullable();
            $table->index('company_id');
            $table->index('office_code_id', 'fk_outgoings_office_code');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('office_code_id', 'fk_outgoings_office_code')->references('id')->on('office_codes');
        });

        Schema::create('incomings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('no');
            $table->string('currency');
            $table->string('invoice_number');
            $table->date('incoming_date');
            $table->string('customs_document_number')->nullable();
            $table->date('customs_document_date')->nullable();
            $table->string('feature');
            $table->float('amount_item')->default(0);
            $table->date('invoice_date')->nullable();
            $table->boolean('is_subcontract')->default(false);
            $table->string('application_number')->default('');
            $table->unsignedInteger('office_code_id')->nullable();
            $table->index('company_id');
            $table->index('office_code_id', 'fk_incomings_office_code');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('office_code_id', 'fk_incomings_office_code')->references('id')->on('office_codes');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('currency');
            $table->unsignedBigInteger('company_id');
            $table->string('no');
            $table->date('date');
            $table->string('feature');
            $table->string('terms')->nullable();
            $table->unsignedBigInteger('incoming_id')->nullable();
            $table->unsignedBigInteger('outgoing_id')->nullable();
            $table->index('company_id');
            $table->index('incoming_id', 'fk_orders_incoming_id');
            $table->index('outgoing_id', 'fk_outgoings_order_id');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('incoming_id', 'fk_orders_incoming_id')->references('id')->on('incomings');
            $table->foreign('outgoing_id', 'fk_outgoings_order_id')->references('id')->on('outgoings');
        });

        Schema::create('orders_detail', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('item_id');
            $table->float('quantity');
            $table->float('price');
            $table->index('order_id');
            $table->index('item_id');
            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('item_id')->references('id')->on('items');
        });

        Schema::create('incomings_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('incoming_id');
            $table->unsignedBigInteger('item_id');
            $table->float('po_quantity')->nullable();
            $table->float('quantity');
            $table->string('hs_code')->default('');
            $table->string('country')->default('');
            $table->float('amount')->default(0);
            $table->float('remainder_quantity')->default(0);
            $table->string('item_series')->default('');
            $table->index('incoming_id');
            $table->index('item_id');
            $table->foreign('incoming_id')->references('id')->on('incomings');
            $table->foreign('item_id')->references('id')->on('items');
        });

        // ============================================================
        // Bill of Materials
        // ============================================================
        Schema::create('bill_of_materials', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('no');
            $table->date('date');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('finished_good_name')->nullable();
            $table->string('feature');
            $table->float('quantity')->default(0);
            $table->unsignedBigInteger('finished_good_id')->nullable();
            $table->index('company_id');
            $table->index('finished_good_id', 'fk_bill_of_materials_finished_good_id');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('finished_good_id', 'fk_bill_of_materials_finished_good_id')
                ->references('id')->on('items');
        });

        // ============================================================
        // Productions
        // ============================================================
        Schema::create('productions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('no');
            $table->text('description')->nullable();
            $table->date('date');
            $table->unsignedBigInteger('bill_of_material_id')->nullable();
            $table->string('feature');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->float('remainder_quantity')->nullable();
            $table->float('total_quantity')->default(0);
            $table->index('bill_of_material_id', 'fk_productions_bill_of_material');
            $table->foreign('bill_of_material_id', 'fk_productions_bill_of_material')
                ->references('id')->on('bill_of_materials');
        });

        Schema::create('productions_detail', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('production_id');
            $table->unsignedBigInteger('item_id');
            $table->float('po_quantity')->nullable();
            $table->float('quantity');
            $table->string('stock_opname_feature')->nullable();
            $table->string('identifier')->default('');
            $table->float('remainder_quantity')->nullable();
            $table->index('production_id');
            $table->index('item_id');
            $table->foreign('production_id')->references('id')->on('productions');
            $table->foreign('item_id')->references('id')->on('items');
        });

        Schema::create('bill_of_materials_detail', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('bill_of_material_id');
            $table->float('quantity');
            $table->unsignedBigInteger('production_detail_id')->nullable();
            $table->index('item_id');
            $table->index('bill_of_material_id');
            $table->index('production_detail_id', 'fk_bill_of_materials_detail_production_detail');
            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('bill_of_material_id')->references('id')->on('bill_of_materials');
            $table->foreign('production_detail_id', 'fk_bill_of_materials_detail_production_detail')
                ->references('id')->on('productions_detail');
        });

        // ============================================================
        // Outgoings detail and links
        // ============================================================
        Schema::create('outgoings_detail', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('outgoing_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->float('quantity')->nullable();
            $table->float('amount')->default(0);
            $table->float('remainder_quantity')->default(0);
            $table->unsignedBigInteger('production_id')->nullable();
            $table->string('item_series')->nullable();
            $table->index('outgoing_id');
            $table->index('item_id');
            $table->index('production_id', 'fk_outgoings_details_productions');
            $table->foreign('outgoing_id')->references('id')->on('outgoings');
            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('production_id', 'fk_outgoings_details_productions')
                ->references('id')->on('productions');
        });

        Schema::create('outgoings_detail_incoming', function (Blueprint $table) {
            $table->unsignedBigInteger('incoming_detail_id');
            $table->unsignedBigInteger('outgoing_detail_id');
            $table->float('quantity')->default(0);
            $table->unique(['incoming_detail_id', 'outgoing_detail_id'], 'odi_in_out_unique');
            $table->index('outgoing_detail_id');
            $table->foreign('incoming_detail_id')->references('id')->on('incomings_details');
            $table->foreign('outgoing_detail_id')->references('id')->on('outgoings_detail');
        });

        Schema::create('productions_detail_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('incoming_detail_id')->nullable();
            $table->unsignedBigInteger('outgoing_detail_id')->nullable();
            $table->unsignedBigInteger('production_finished_good_detail_id')->nullable();
            $table->unsignedBigInteger('production_detail_id');
            $table->float('quantity')->default(0);
            $table->index('incoming_detail_id');
            $table->index('production_detail_id');
            $table->index('production_finished_good_detail_id', 'pdl_pfgd_idx');
            $table->index('outgoing_detail_id');
            $table->foreign('incoming_detail_id')->references('id')->on('incomings_details');
            $table->foreign('production_detail_id')->references('id')->on('productions_detail');
            $table->foreign('production_finished_good_detail_id', 'pdl_pfgd_fk')
                ->references('id')->on('productions_detail');
            $table->foreign('outgoing_detail_id')->references('id')->on('outgoings_detail');
        });

        Schema::create('productions_detail_outgoing', function (Blueprint $table) {
            $table->unsignedBigInteger('production_detail_id');
            $table->unsignedBigInteger('outgoing_detail_id');
            $table->float('quantity')->default(0);
            $table->unique(['production_detail_id', 'outgoing_detail_id'], 'pdo_pd_od_unique');
            $table->index('outgoing_detail_id');
            $table->foreign('production_detail_id')->references('id')->on('productions_detail');
            $table->foreign('outgoing_detail_id')->references('id')->on('outgoings_detail');
        });

        // ============================================================
        // Outgoings WIP
        // ============================================================
        Schema::create('outgoings_wip', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('no')->unique();
            $table->date('date');
            $table->string('type');
        });

        Schema::create('outgoings_wip_detail', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('outgoing_wip_id');
            $table->unsignedBigInteger('incoming_detail_id');
            $table->unsignedBigInteger('item_id');
            $table->float('quantity')->nullable();
            $table->float('amount')->default(0);
            $table->float('remainder_quantity')->default(0);
            $table->index('outgoing_wip_id');
            $table->index('incoming_detail_id');
            $table->index('item_id');
            $table->foreign('outgoing_wip_id')->references('id')->on('outgoings_wip');
            $table->foreign('incoming_detail_id')->references('id')->on('incomings_details');
            $table->foreign('item_id')->references('id')->on('items');
        });

        // ============================================================
        // Material Movements (central inventory ledger)
        // ============================================================
        Schema::create('material_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('movement_type');
            $table->date('movement_date');
            $table->unsignedBigInteger('document_id');
            $table->string('document_no');
            $table->unsignedBigInteger('item_id');
            $table->float('quantity');
            $table->string('movement_direction', 25);
            $table->string('location_from');
            $table->string('location_to');
            $table->unsignedBigInteger('parent_movement_id')->nullable();
            $table->unsignedBigInteger('root_incoming_material_movement_id')->nullable();
            $table->string('adjustment_type')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('item_id');
            $table->index('document_id');
            $table->index('movement_type');
            $table->index('parent_movement_id');
            $table->index('root_incoming_material_movement_id');
            $table->foreign('parent_movement_id')->references('id')->on('material_movements');
            $table->foreign('item_id')->references('id')->on('items');
        });

        // ============================================================
        // Stock Opname
        // ============================================================
        Schema::create('stocks_opname', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('no');
            $table->date('date');
            $table->string('feature');
        });

        Schema::create('stocks_opname_detail', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('stock_opname_id');
            $table->float('beginning_balance')->default(0);
            $table->float('income')->default(0);
            $table->float('expense')->default(0);
            $table->float('ending_balance')->default(0);
            $table->float('difference')->default(0);
            $table->float('adjust_in')->default(0);
            $table->float('adjust_out')->default(0);
            $table->float('stock_opname')->default(0);
            $table->string('information');
            $table->index('item_id');
            $table->index('stock_opname_id');
            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('stock_opname_id')->references('id')->on('stocks_opname');
        });

        Schema::create('tracing_stocks_opname', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('item_id');
            $table->date('date');
            $table->string('feature');
            $table->float('adjust_in')->default(0);
            $table->float('adjust_out')->default(0);
            $table->float('stock_opname')->default(0);
            $table->index('item_id');
            $table->foreign('item_id')->references('id')->on('items');
        });

        // ============================================================
        // Accounting (legacy, keep schema)
        // ============================================================
        Schema::create('accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedInteger('currency_id')->nullable();
            $table->unsignedBigInteger('incoming_id')->nullable();
            $table->string('no');
            $table->date('date');
            $table->float('rate')->nullable();
            $table->string('description')->nullable();
            $table->string('delivery_order_number');
            $table->string('debit')->nullable();
            $table->string('credit')->nullable();
            $table->float('total_amount');
            $table->string('tax_type');
            $table->float('tax_amount')->nullable();
            $table->float('after_tax')->nullable();
            $table->string('feature');
            $table->index('company_id');
            $table->index('currency_id');
            $table->index('incoming_id');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->foreign('incoming_id')->references('id')->on('incomings');
        });

        Schema::create('bank_cash_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('journal_type');
            $table->string('no');
            $table->date('date');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedInteger('currency_id');
            $table->float('rate');
            $table->string('invoice_number')->nullable();
            $table->string('description')->nullable();
            $table->string('debit');
            $table->string('credit');
            $table->float('total_amount');
            $table->float('tax')->nullable();
            $table->float('tax_amount');
            $table->float('after_tax');
            $table->string('feature');
            $table->index('company_id');
            $table->index('account_id');
            $table->index('currency_id');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->foreign('currency_id')->references('id')->on('currencies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_cash_accounts');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('tracing_stocks_opname');
        Schema::dropIfExists('stocks_opname_detail');
        Schema::dropIfExists('stocks_opname');
        Schema::dropIfExists('material_movements');
        Schema::dropIfExists('outgoings_wip_detail');
        Schema::dropIfExists('outgoings_wip');
        Schema::dropIfExists('productions_detail_outgoing');
        Schema::dropIfExists('productions_detail_links');
        Schema::dropIfExists('outgoings_detail_incoming');
        Schema::dropIfExists('outgoings_detail');
        Schema::dropIfExists('bill_of_materials_detail');
        Schema::dropIfExists('productions_detail');
        Schema::dropIfExists('productions');
        Schema::dropIfExists('bill_of_materials');
        Schema::dropIfExists('incomings_details');
        Schema::dropIfExists('orders_detail');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('incomings');
        Schema::dropIfExists('outgoings');
        Schema::dropIfExists('bclkt_raw_materials');
        Schema::dropIfExists('bclkt_finished_goods');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('users');
        Schema::dropIfExists('user_group_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('user_groups');
        Schema::dropIfExists('outgoing_types');
        Schema::dropIfExists('packagings');
        Schema::dropIfExists('office_codes');
        Schema::dropIfExists('items');
        Schema::dropIfExists('company_type_links');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('company_types');
        Schema::dropIfExists('currencies');
    }
};
