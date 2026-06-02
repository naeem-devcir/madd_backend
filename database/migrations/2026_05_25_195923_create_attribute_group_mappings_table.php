// database/migrations/xxxx_xx_xx_xxxxxx_create_attribute_group_mappings_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('attribute_group_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('attribute_set_id');
            $table->unsignedBigInteger('attribute_group_id');
            $table->unsignedBigInteger('attribute_id');
            $table->integer('sort_order')->default(0);
            
            // Attribute details for quick access
            $table->string('attribute_code')->nullable();
            $table->string('frontend_label')->nullable();
            $table->boolean('is_system')->default(false); // true = system attribute (cannot unassign)
            $table->boolean('is_required')->default(false);
            
            $table->timestamps();
            
            $table->unique(['attribute_set_id', 'attribute_id'], 'unique_attr_set_attr');
            $table->index('attribute_group_id');
            $table->index('attribute_set_id');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
            $table->foreign('attribute_set_id')->references('id')->on('attribute_sets')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('attribute_group_mappings');
    }
};