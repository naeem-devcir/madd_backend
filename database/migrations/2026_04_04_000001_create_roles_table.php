<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('display_name', 150);
            $table->text('description')->nullable();
            $table->string('guard_name', 100)->default('web');
            $table->boolean('is_system')->default(false);
            $table->integer('level')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};

// return new class extends Migration
// {
//     public function up(): void
//     {
//         Schema::create('roles', function (Blueprint $table) {
//             $table->bigIncrements('id');

//             $table->string('name', 100);
//             $table->string('guard_name', 100);

//             $table->timestamps();

//             // optional but recommended index
//             $table->unique(['name', 'guard_name']);
//         });
//     }

//     public function down(): void
//     {
//         Schema::dropIfExists('roles');
//     }
// };