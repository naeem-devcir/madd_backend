<?php

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

DB::statement('SET FOREIGN_KEY_CHECKS=0;');
DB::table('mlm_commissions')->truncate();
DB::table('mlm_agents')->truncate();
DB::statement('SET FOREIGN_KEY_CHECKS=1;');

$now = now();

// Create dummy users for agents if they don't exist
$userIds = [];
for ($i = 1; $i <= 20; $i++) {
    $user = User::where('email', "agent{$i}@madd.com")->first();
    if (!$user) {
        $user = new User();
        $user->uuid = Str::uuid();
        $user->email = "agent{$i}@madd.com";
        $user->full_name = "Agent User {$i}";
        $user->password = Hash::make('password');
        $user->role = 'vendor';
        $user->country_code = 'US';
        $user->phone = "123456789{$i}";
        $user->save();
    }
    $userIds[] = $user->id;
}

// Create 1 Root Agent
$rootId = DB::table('mlm_agents')->insertGetId([
    'uuid' => Str::uuid(),
    'user_id' => $userIds[0],
    'parent_id' => null,
    'level' => 1,
    'territory_type' => 'country',
    'territory_code' => 'US',
    'commission_rate' => 10.0,
    'phone' => '1234567890',
    'status' => 'active',
    'kyc_status' => 'verified',
    'total_commissions_earned' => 5000.0,
    'total_vendors_recruited' => 19,
    'created_at' => $now,
    'updated_at' => $now,
]);

// Create Level 2 Agents (Children of root)
$level2Ids = [];
for ($i = 1; $i <= 4; $i++) {
    $level2Ids[] = DB::table('mlm_agents')->insertGetId([
        'uuid' => Str::uuid(),
        'user_id' => $userIds[$i],
        'parent_id' => $rootId,
        'level' => 2,
        'territory_type' => 'region',
        'territory_code' => 'NY',
        'commission_rate' => 8.0,
        'phone' => '1234567890',
        'status' => 'active',
        'kyc_status' => 'verified',
        'total_commissions_earned' => rand(1000, 3000),
        'total_vendors_recruited' => rand(2, 5),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// Create Level 3 Agents (Children of Level 2)
for ($i = 5; $i <= 15; $i++) {
    DB::table('mlm_agents')->insert([
        'uuid' => Str::uuid(),
        'user_id' => $userIds[$i],
        'parent_id' => $level2Ids[array_rand($level2Ids)],
        'level' => 3,
        'territory_type' => 'city',
        'territory_code' => 'NYC',
        'commission_rate' => 5.0,
        'phone' => '1234567890',
        'status' => ['active', 'inactive', 'suspended'][rand(0, 2)],
        'kyc_status' => ['pending', 'verified', 'rejected'][rand(0, 2)],
        'total_commissions_earned' => rand(100, 1000),
        'total_vendors_recruited' => rand(0, 2),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// Create Commissions
$agents = DB::table('mlm_agents')->get();
$commissions = [];
foreach ($agents as $agent) {
    $numCommissions = rand(1, 5);
    for ($j = 0; $j < $numCommissions; $j++) {
        $commissions[] = [
            'uuid' => Str::uuid(),
            'agent_id' => $agent->id,
            'amount' => rand(50, 500),
            'currency_code' => 'USD',
            'status' => ['pending', 'approved', 'paid', 'rejected'][rand(0, 3)],
            'source_type' => 'vendor_sale',
            'source_id' => rand(1000, 9999),
            'description' => 'Commission for order #' . rand(1000, 9999),
            'created_at' => now()->subDays(rand(1, 30)),
            'updated_at' => $now,
        ];
    }
}
DB::table('mlm_commissions')->insert($commissions);

echo "Seeded " . count($agents) . " agents and " . count($commissions) . " commissions.\n";
