<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$cols = DB::select("SHOW COLUMNS FROM mlm_commissions");
foreach($cols as $col) {
    if($col->Field == 'source_type') {
        echo "Type of source_type is: " . $col->Type . "\n";
    }
}
