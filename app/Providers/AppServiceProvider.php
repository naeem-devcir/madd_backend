<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // Order Events
        \App\Events\Order\OrderCreated::class => [
            \App\Listeners\Order\SendOrderConfirmation::class,
            \App\Listeners\Order\UpdateInventory::class,
        ],
        
        // Payment Events
        \App\Events\Payment\PaymentReceived::class => [
            \App\Listeners\Payment\ProcessCommission::class,
        ],
        
        // Vendor Events
        \App\Events\Vendor\VendorApproved::class => [
            \App\Listeners\Vendor\SendWelcomeEmail::class,
        ],
        
        // Product Events
        \App\Events\Product\ProductCreated::class => [
            \App\Listeners\Product\SyncProductToMagento::class,
            \App\Listeners\Product\NotifyVendorProductCreated::class,
        ],
        
        \App\Events\Product\ProductApproved::class => [
            \App\Listeners\Product\PublishProductToStores::class,
            \App\Listeners\Product\NotifyVendorProductApproved::class,
        ],
        
        // Settlement Events
        \App\Events\Settlement\SettlementGenerated::class => [
            \App\Listeners\Settlement\GenerateSettlementStatement::class,
            \App\Listeners\Settlement\NotifyVendorSettlementReady::class,
        ],
        
        \App\Events\Settlement\SettlementPaid::class => [
            \App\Listeners\Settlement\UpdateVendorBalance::class,
            \App\Listeners\Settlement\SendPayoutConfirmation::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        parent::boot();
    }
}