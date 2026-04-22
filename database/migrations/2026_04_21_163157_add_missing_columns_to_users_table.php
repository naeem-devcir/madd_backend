<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add missing columns for GDPR compliance and user preferences
            $table->json('metadata')->nullable()->after('kyc_status');
            $table->json('preferences')->nullable()->after('metadata');
            $table->boolean('is_super_admin')->default(false)->after('preferences');
            $table->boolean('is_email_verified')->default(false)->after('is_super_admin');
            $table->boolean('is_phone_verified')->default(false)->after('is_email_verified');
            $table->boolean('is_kyc_verified')->default(false)->after('is_phone_verified');
            
            // Add indexes for better performance
            $table->index('is_super_admin');
            $table->index('status');
            $table->index('user_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'metadata',
                'preferences',
                'is_super_admin',
                'is_email_verified',
                'is_phone_verified',
                'is_kyc_verified'
            ]);
        });
    }
};