<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('madd_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Legal entity name');
            $table->char('country_code', 2)->index();
            $table->string('vat_number', 50);
            $table->string('registration_number', 100);
            $table->string('legal_representative')->comment('Legal rep name');
            $table->string('contact_email', 191)->comment('Official email');
            $table->string('contact_phone', 30)->nullable()->comment('Official phone');
            $table->string('tax_office')->nullable()->comment('Tax authority details');
            $table->json('address')->comment('Full legal address');
            $table->json('bank_details')->nullable()->comment('Company bank info');
            $table->string('logo_url', 500)->nullable()->comment('For invoices');
            $table->string('invoice_prefix', 20)->comment('Invoice number prefix');
            $table->date('fiscal_year_start')->default('2024-01-01')->comment('Accounting year start');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('madd_companies');
    }
};
