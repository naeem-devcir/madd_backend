<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettlementResource extends JsonResource
{
    /**
     * Transform the settlement resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'settlement_number' => $this->settlement_number,

            // Period
            'period' => [
                'start' => $this->period_start->toDateString(),
                'end' => $this->period_end->toDateString(),
                'range' => $this->period_range,
                'days' => $this->period_days,
            ],

            // Vendor Info
            'vendor' => $this->whenLoaded('vendor', function () {
                return [
                    'id' => $this->vendor->uuid,
                    'name' => $this->vendor->company_name,
                    'slug' => $this->vendor->company_slug,
                ];
            }),

            // Financial Summary
            'financials' => [
                'gross_sales' => $this->gross_sales,
                'gross_sales_formatted' => $this->formatted_gross_sales,
                'total_refunds' => $this->total_refunds,
                'total_commissions' => $this->total_commissions,
                'total_shipping_fees' => $this->total_shipping_fees,
                'total_tax_collected' => $this->total_tax_collected,
                'gateway_fees' => $this->gateway_fees,
                'adjustment_amount' => $this->adjustment_amount,
                'net_payout' => $this->net_payout,
                'net_payout_formatted' => $this->formatted_net_payout,
            ],

            // Currency
            'currency' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,

            // Status
            'status' => $this->status,
            'status_label' => $this->getStatusLabelAttribute(),
            'status_color' => $this->getStatusColorAttribute(),
            'is_pending' => $this->is_pending,
            'is_approved' => $this->is_approved,
            'is_paid' => $this->is_paid,
            'is_disputed' => $this->is_disputed,

            // Payment Info
            'payment' => [
                'method' => $this->payment_method,
                'reference' => $this->payment_reference,
                'paid_at' => $this->paid_at?->toIso8601String(),
            ],

            // Approval
            'approved_by' => $this->whenLoaded('approvedBy', function () {
                return [
                    'id' => $this->approvedBy->uuid,
                    'name' => $this->approvedBy->full_name,
                ];
            }),
            'approved_at' => $this->approved_at?->toIso8601String(),

            // Madd Company
            'madd_company' => $this->whenLoaded('maddCompany', function () {
                return [
                    'id' => $this->maddCompany->id,
                    'name' => $this->maddCompany->name,
                    'country' => $this->maddCompany->country_code,
                ];
            }),

            // Transactions
            'transactions' => $this->whenLoaded('transactions', function () {
                return $this->transactions->map(function ($transaction) {
                    return [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'type_label' => $transaction->type_label,
                        'amount' => $transaction->amount,
                        'description' => $transaction->description,
                        'created_at' => $transaction->created_at->toIso8601String(),
                    ];
                });
            }),

            // Orders
            'orders_count' => $this->whenLoaded('orders', function () {
                return $this->orders->count();
            }),
            'orders' => OrderResource::collection($this->whenLoaded('orders')),

            // Payout
            'payout' => $this->whenLoaded('payout', function () {
                return [
                    'id' => $this->payout->id,
                    'amount' => $this->payout->amount,
                    'method' => $this->payout->payout_method,
                    'status' => $this->payout->status,
                    'reference' => $this->payout->gateway_payout_id,
                    'completed_at' => $this->payout->completed_at?->toIso8601String(),
                ];
            }),

            // Document
            'statement' => [
                'pdf_url' => $this->statement_pdf_path ? $this->getStatementUrl() : null,
                'is_available' => ! is_null($this->statement_pdf_path),
            ],

            // Notes
            'notes' => $this->notes,

            // Transaction Summary
            'summary' => $this->when($request->include_summary ?? false, function () {
                return $this->getTransactionSummary();
            }),

            // Dates
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Get status label
     */
    private function getStatusLabelAttribute(): string
    {
        $labels = [
            'pending' => 'Pending Approval',
            'approved' => 'Approved',
            'paid' => 'Paid',
            'disputed' => 'Disputed',
            'cancelled' => 'Cancelled',
        ];

        return $labels[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get status color
     */
    private function getStatusColorAttribute(): string
    {
        $colors = [
            'pending' => 'warning',
            'approved' => 'info',
            'paid' => 'success',
            'disputed' => 'danger',
            'cancelled' => 'secondary',
        ];

        return $colors[$this->status] ?? 'secondary';
    }

    /**
     * Get formatted gross sales
     */
    private function getFormattedGrossSalesAttribute(): string
    {
        return $this->currency_code.' '.number_format($this->gross_sales, 2);
    }

    /**
     * Get formatted net payout
     */
    private function getFormattedNetPayoutAttribute(): string
    {
        return $this->currency_code.' '.number_format($this->net_payout, 2);
    }

    /**
     * Get statement URL
     */
    private function getStatementUrl(): ?string
    {
        if ($this->statement_pdf_path) {
            return \Storage::disk('s3')->temporaryUrl(
                $this->statement_pdf_path,
                now()->addMinutes(15)
            );
        }

        return null;
    }

    /**
     * Get transaction summary
     */
    private function getTransactionSummary(): array
    {
        return [
            'sales' => $this->transactions()->where('type', 'sale')->sum('amount'),
            'refunds' => $this->transactions()->where('type', 'refund')->sum('amount'),
            'commissions' => $this->transactions()->where('type', 'commission')->sum('amount'),
            'adjustments' => $this->transactions()->where('type', 'adjustment')->sum('amount'),
        ];
    }
}
