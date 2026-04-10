<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('invoice_number', 50)->unique();
            $table->enum('type', ['vendor_invoice', 'credit_note', 'platform_invoice']);
            $table->string('payable_type')->index();
            $table->unsignedBigInteger('payable_id')->index();
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('vendor_id')->nullable()->references('uuid')->on('vendors')->nullOnDelete();
            $table->foreignUuid('settlement_id')->nullable()->references('uuid')->on('settlements')->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->references('uuid')->on('orders')->nullOnDelete();
            $table->unsignedBigInteger('credit_note_id')->nullable();
            $table->foreignId('madd_company_id')->nullable()->constrained('madd_companies')->nullOnDelete();
            
            $table->json('billing_address');
            $table->string('vat_number', 50)->nullable();
            $table->boolean('reverse_charge')->default(false);
            $table->decimal('subtotal', 12, 4);
            $table->decimal('tax_amount', 12, 4)->default(0);
            $table->decimal('total', 12, 4);
            $table->char('currency_code', 3);
            $table->string('language_code', 10)->default('en');
            $table->string('payment_terms', 100)->nullable();
            $table->text('footer_notes')->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->enum('status', ['draft', 'issued', 'paid', 'cancelled']);
            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('invoice_number');
            $table->index('status');
            $table->index('credit_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};