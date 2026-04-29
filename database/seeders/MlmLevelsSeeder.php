<?php

namespace Database\Seeders;

use App\Models\Mlm\MlmLevel;
use Illuminate\Database\Seeder;

class MlmLevelsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        MlmLevel::seedDefaultLevels();
        
        $this->command->info('MLM levels seeded successfully!');
    }
}