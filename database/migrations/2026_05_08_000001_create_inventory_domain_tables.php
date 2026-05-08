<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('symbol', 20)->index();
            $table->timestamps();
        });

        Schema::create('dosage_forms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->index();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('contact_person', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('dosage_form_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 50)->unique();
            $table->string('barcode', 100)->nullable()->unique();
            $table->string('name', 150)->index();
            $table->string('generic_name', 150)->nullable();
            $table->string('manufacturer', 150)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->text('active_ingredient')->nullable();
            $table->string('strength', 100)->nullable();
            $table->enum('classification', [
                'obat_bebas',
                'obat_bebas_terbatas',
                'obat_keras',
                'vitamin_suplemen',
                'alkes',
                'other',
            ])->index();
            $table->boolean('requires_prescription')->default(false);
            $table->decimal('default_purchase_price', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->decimal('minimum_stock', 15, 3)->default(0);
            $table->decimal('reorder_level', 15, 3)->default(0);
            $table->text('storage_instruction')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('medicine_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_number', 100);
            $table->date('expiry_date')->nullable()->index();
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->nullable();
            $table->decimal('initial_stock', 15, 3)->default(0);
            $table->decimal('current_stock', 15, 3)->default(0)->index();
            $table->date('received_date')->nullable();
            $table->enum('status', ['available', 'expired', 'depleted', 'quarantined'])->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['medicine_id', 'batch_number', 'expiry_date']);
            $table->index(['medicine_id', 'status']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('order_date')->index();
            $table->date('received_date')->nullable()->index();
            $table->enum('status', ['draft', 'received'])->index();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->foreignId('medicine_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('batch_number', 100);
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();

            $table->index(['purchase_order_id', 'medicine_id']);
            $table->index('medicine_batch_id');
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 50)->unique();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('sale_date')->index();
            $table->string('customer_name', 150)->nullable();
            $table->enum('payment_method', ['cash', 'transfer', 'qris', 'other'])->index();
            $table->enum('status', ['completed'])->index();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->decimal('gross_margin', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['cashier_id', 'sale_date']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->foreignId('medicine_batch_id')->constrained()->restrictOnDelete();
            $table->string('medicine_code_snapshot', 50);
            $table->string('medicine_name_snapshot', 150);
            $table->string('batch_number_snapshot', 100);
            $table->date('expiry_date_snapshot')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price_snapshot', 15, 2);
            $table->decimal('cost_snapshot', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('gross_margin', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['sale_id', 'medicine_id']);
            $table->index('medicine_batch_id');
        });

        Schema::create('stock_usages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('usage_date')->index();
            $table->enum('usage_type', [
                'damaged',
                'expired',
                'lost',
                'sample',
                'return_supplier',
                'internal_use',
                'other',
            ])->index();
            $table->enum('status', ['draft', 'completed', 'cancelled'])->index();
            $table->decimal('estimated_total_cost', 15, 2)->default(0);
            $table->text('reason');
            $table->timestamps();

            $table->index(['created_by', 'status']);
        });

        Schema::create('stock_usage_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_usage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->foreignId('medicine_batch_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('cost_snapshot', 15, 2);
            $table->decimal('estimated_cost', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['stock_usage_id', 'medicine_id']);
            $table->index('medicine_batch_id');
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('adjustment_date')->index();
            $table->enum('status', ['draft', 'approved', 'cancelled'])->index();
            $table->text('reason');
            $table->timestamps();

            $table->index(['created_by', 'status']);
            $table->index(['approved_by', 'status']);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->foreignId('medicine_batch_id')->constrained()->restrictOnDelete();
            $table->decimal('system_stock', 15, 3);
            $table->decimal('counted_stock', 15, 3);
            $table->decimal('difference', 15, 3);
            $table->decimal('cost_snapshot', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['stock_adjustment_id', 'medicine_id']);
            $table->index('medicine_batch_id');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->foreignId('medicine_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('movement_type', [
                'opening_stock',
                'purchase_in',
                'sale_out',
                'usage_out',
                'adjustment_in',
                'adjustment_out',
                'cancel_usage',
                'cancel_adjustment',
            ])->index();
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('quantity_in', 15, 3)->default(0);
            $table->decimal('quantity_out', 15, 3)->default(0);
            $table->decimal('stock_before', 15, 3);
            $table->decimal('stock_after', 15, 3);
            $table->decimal('unit_cost_snapshot', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['medicine_id', 'medicine_batch_id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('module', 100)->index();
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->enum('type', ['string', 'number', 'boolean', 'json']);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_usage_items');
        Schema::dropIfExists('stock_usages');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('medicine_batches');
        Schema::dropIfExists('medicines');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('dosage_forms');
        Schema::dropIfExists('units');
        Schema::dropIfExists('medicine_categories');
    }
};
