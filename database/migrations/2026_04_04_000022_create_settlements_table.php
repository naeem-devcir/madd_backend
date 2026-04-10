<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // Polymorphic — supports Vendor, Courier, etc.
            $table->string('payable_type')->index();
            $table->unsignedBigInteger('payable_id')->index();
            
            // FIX: Add proper foreign keys
            $table->foreignId('madd_company_id')->nullable()->constrained('madd_companies')->nullOnDelete();
            $table->foreignUuid('vendor_id')->nullable()->references('uuid')->on('vendors')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->references('uuid')->on('users')->nullOnDelete();
            
            // Settlement Period
            $table->date('period_start');
            $table->date('period_end');
            $table->integer('period_days')->default(30);
            
            // Financials
            $table->decimal('gross_sales', 14, 4);
            $table->decimal('total_refunds', 14, 4)->default(0);
            $table->decimal('total_commissions', 14, 4);
            $table->decimal('total_shipping_fees', 14, 4)->default(0);
            $table->decimal('total_tax_collected', 14, 4)->default(0);
            $table->decimal('adjustment_amount', 14, 4)->default(0);
            $table->decimal('gateway_fees', 14, 4)->default(0);
            $table->decimal('net_payout', 14, 4);
            
            // Currency
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate', 10, 4)->default(1.00);
            
            // Status
            $table->enum('status', ['pending', 'approved', 'paid', 'disputed']);
            
            // Payment Info
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('statement_pdf_path', 500)->nullable();
            
            // Timestamps
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            
            // Notes
            $table->text('notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['payable_type', 'payable_id']);
            $table->index(['vendor_id', 'period_start', 'period_end']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};