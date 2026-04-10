<?php

namespace App\Models\Vendor;

use App\Models\Traits\HasUuid;
use App\Models\Financial\Transaction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorWallet extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'vendor_wallets';
    
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'vendor_id',
        'balance',
        'reserved_balance',
        'currency_code',
        'last_transaction_at',
    ];

    protected $casts = [
        'balance' => 'decimal:4',
        'reserved_balance' => 'decimal:4',
        'last_transaction_at' => 'datetime',
    ];

    // ========== Relationships ==========
    
    /**
     * Get the vendor who owns this wallet
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'uuid');
    }
    
    /**
     * Get all transactions for this wallet
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'vendor_id', 'vendor_id');
    }
    
    // ========== Accessors ==========
    
    /**
     * Get available balance (balance - reserved)
     */
    public function getAvailableBalanceAttribute(): float
    {
        return $this->balance - $this->reserved_balance;
    }
    
    /**
     * Get formatted balance
     */
    public function getFormattedBalanceAttribute(): string
    {
        return $this->currency_code . ' ' . number_format($this->balance, 2);
    }
    
    /**
     * Get formatted available balance
     */
    public function getFormattedAvailableBalanceAttribute(): string
    {
        return $this->currency_code . ' ' . number_format($this->available_balance, 2);
    }
    
    /**
     * Get formatted reserved balance
     */
    public function getFormattedReservedBalanceAttribute(): string
    {
        return $this->currency_code . ' ' . number_format($this->reserved_balance, 2);
    }
    
    /**
     * Check if wallet has sufficient balance
     */
    public function getHasSufficientBalanceAttribute(): bool
    {
        return $this->available_balance > 0;
    }
    
    /**
     * Get wallet summary
     */
    public function getSummaryAttribute(): array
    {
        return [
            'balance' => $this->balance,
            'reserved' => $this->reserved_balance,
            'available' => $this->available_balance,
            'currency' => $this->currency_code,
            'last_activity' => $this->last_transaction_at?->toIso8601String(),
        ];
    }
    
    // ========== Methods ==========
    
    /**
     * Credit the wallet
     */
    public function credit(float $amount, string $description = null, array $metadata = []): Transaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive');
        }
        
        $oldBalance = $this->balance;
        $this->balance += $amount;
        $this->last_transaction_at = now();
        $this->save();
        
        // Update vendor totals
        $this->vendor->increment('total_earned', $amount);
        
        // Create transaction record
        $transaction = Transaction::create([
            'vendor_id' => $this->vendor_id,
            'type' => 'sale',
            'amount' => $amount,
            'status' => 'completed',
            'currency_code' => $this->currency_code,
            'balance_after' => $this->balance,
            'description' => $description ?? 'Credit to wallet',
            'metadata' => array_merge($metadata, [
                'old_balance' => $oldBalance,
                'new_balance' => $this->balance,
            ]),
            'processed_at' => now(),
        ]);
        
        return $transaction;
    }
    
    /**
     * Debit the wallet
     */
    public function debit(float $amount, string $description = null, array $metadata = []): Transaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive');
        }
        
        if ($this->available_balance < $amount) {
            throw new \RuntimeException('Insufficient balance');
        }
        
        $oldBalance = $this->balance;
        $this->balance -= $amount;
        $this->last_transaction_at = now();
        $this->save();
        
        // Create transaction record
        $transaction = Transaction::create([
            'vendor_id' => $this->vendor_id,
            'type' => 'payout',
            'amount' => -$amount,
            'status' => 'completed',
            'currency_code' => $this->currency_code,
            'balance_after' => $this->balance,
            'description' => $description ?? 'Debit from wallet',
            'metadata' => array_merge($metadata, [
                'old_balance' => $oldBalance,
                'new_balance' => $this->balance,
            ]),
            'processed_at' => now(),
        ]);
        
        return $transaction;
    }
    
    /**
     * Reserve funds (for pending settlements or disputes)
     */
    public function reserve(float $amount, string $reason = null): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Reserve amount must be positive');
        }
        
        if ($this->available_balance < $amount) {
            throw new \RuntimeException('Insufficient balance to reserve');
        }
        
        $this->reserved_balance += $amount;
        $this->save();
        
        // Create transaction record for reservation
        Transaction::create([
            'vendor_id' => $this->vendor_id,
            'type' => 'adjustment',
            'amount' => 0,
            'status' => 'completed',
            'currency_code' => $this->currency_code,
            'description' => "Reserved: {$reason}",
            'metadata' => [
                'reserved_amount' => $amount,
                'reason' => $reason,
                'type' => 'reservation',
            ],
            'processed_at' => now(),
        ]);
    }
    
    /**
     * Release reserved funds
     */
    public function releaseReserved(float $amount, string $reason = null): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Release amount must be positive');
        }
        
        if ($this->reserved_balance < $amount) {
            throw new \RuntimeException('Insufficient reserved balance');
        }
        
        $this->reserved_balance -= $amount;
        $this->save();
        
        // Create transaction record for release
        Transaction::create([
            'vendor_id' => $this->vendor_id,
            'type' => 'adjustment',
            'amount' => 0,
            'status' => 'completed',
            'currency_code' => $this->currency_code,
            'description' => "Reservation released: {$reason}",
            'metadata' => [
                'released_amount' => $amount,
                'reason' => $reason,
                'type' => 'reservation_release',
            ],
            'processed_at' => now(),
        ]);
    }
    
    /**
     * Get transaction history
     */
    public function getTransactionHistory(int $limit = 50, int $offset = 0)
    {
        return $this->transactions()
            ->orderBy('created_at', 'desc')
            ->skip($offset)
            ->limit($limit)
            ->get();
    }
    
    /**
     * Get balance history for chart
     */
    public function getBalanceHistory(int $days = 30): array
    {
        $history = [];
        $startDate = now()->subDays($days);
        
        // Get daily balance snapshots from transactions
        $transactions = $this->transactions()
            ->where('created_at', '>=', $startDate)
            ->orderBy('created_at', 'asc')
            ->get();
        
        $currentBalance = $this->balance;
        $dailyBalances = [];
        
        foreach ($transactions as $transaction) {
            $date = $transaction->created_at->format('Y-m-d');
            $dailyBalances[$date] = $transaction->balance_after ?? $currentBalance;
        }
        
        // Fill in missing dates
        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $history[] = [
                'date' => $date,
                'balance' => $dailyBalances[$date] ?? ($history ? end($history)['balance'] : $this->balance),
            ];
        }
        
        return $history;
    }
    
    /**
     * Get monthly statistics
     */
    public function getMonthlyStatistics(int $months = 12): array
    {
        $statistics = [];
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();
            
            $transactions = $this->transactions()
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->get();
            
            $credits = $transactions->where('amount', '>', 0)->sum('amount');
            $debits = abs($transactions->where('amount', '<', 0)->sum('amount'));
            
            $statistics[] = [
                'month' => $monthStart->format('M Y'),
                'credits' => $credits,
                'debits' => $debits,
                'net' => $credits - $debits,
                'transaction_count' => $transactions->count(),
            ];
        }
        
        return $statistics;
    }
    
    /**
     * Check if withdrawal is possible
     */
    public function canWithdraw(float $amount): bool
    {
        return $this->available_balance >= $amount;
    }
    
    /**
     * Get minimum withdrawal amount (based on vendor settings or platform)
     */
    public function getMinimumWithdrawalAmount(): float
    {
        // This could come from platform settings
        return 50.00;
    }
    
    /**
     * Get maximum withdrawal amount (based on vendor settings or platform)
     */
    public function getMaximumWithdrawalAmount(): float
    {
        // This could come from vendor plan or platform settings
        return min($this->available_balance, 10000.00);
    }
}
