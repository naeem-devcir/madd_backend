<?php

namespace App\Models\Financial;

use App\Jobs\Invoice\GenerateInvoicePdf;
use App\Models\Config\MaddCompany;
use App\Models\Order\Order;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'invoices';

    protected $fillable = [
        'invoice_number',
        'type',
        'payable_type',
        'payable_id',
        'vendor_id',
        'settlement_id',
        'order_id',
        'credit_note_id',
        'madd_company_id',
        'billing_address',
        'vat_number',
        'reverse_charge',
        'subtotal',
        'tax_amount',
        'total',
        'currency_code',
        'language_code',
        'payment_terms',
        'footer_notes',
        'pdf_path',
        'status',
        'issued_at',
        'due_at',
        'paid_at',
    ];

    protected $casts = [
        'billing_address' => 'array',
        'reverse_charge' => 'boolean',
        'subtotal' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total' => 'decimal:4',
        'issued_at' => 'date',
        'due_at' => 'date',
        'paid_at' => 'datetime',
    ];

    // ========== Relationships ==========

    public function payable()
    {
        return $this->morphTo();
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
    }

    public function settlement()
    {
        return $this->belongsTo(Settlement::class, 'settlement_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function creditNote()
    {
        return $this->belongsTo(Invoice::class, 'credit_note_id', 'id');
    }

    public function originalInvoice()
    {
        return $this->hasMany(Invoice::class, 'credit_note_id', 'id');
    }

    public function maddCompany()
    {
        return $this->belongsTo(MaddCompany::class, 'madd_company_id', 'id');
    }

    // ========== Scopes ==========

    public function scopeIssued($query)
    {
        return $query->where('status', 'issued');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'issued')
            ->where('due_at', '<', now());
    }

    // ========== Accessors ==========

    public function getFormattedSubtotalAttribute(): string
    {
        return $this->currency_code.' '.number_format($this->subtotal, 2);
    }

    public function getFormattedTotalAttribute(): string
    {
        return $this->currency_code.' '.number_format($this->total, 2);
    }

    public function getFormattedTaxAmountAttribute(): string
    {
        return $this->currency_code.' '.number_format($this->tax_amount, 2);
    }

    public function getIsDraftAttribute(): bool
    {
        return $this->status === 'draft';
    }

    public function getIsIssuedAttribute(): bool
    {
        return $this->status === 'issued';
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->status === 'paid';
    }

    public function getIsCancelledAttribute(): bool
    {
        return $this->status === 'cancelled';
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'issued' && $this->due_at && $this->due_at->isPast();
    }

    public function getIsCreditNoteAttribute(): bool
    {
        return $this->type === 'credit_note';
    }

    // ========== Methods ==========

    public function issue(): void
    {
        $this->status = 'issued';
        $this->issued_at = now();
        $this->save();

        // Generate PDF
        GenerateInvoicePdf::dispatch($this);
    }

    public function markAsPaid(): void
    {
        $this->status = 'paid';
        $this->paid_at = now();
        $this->save();

        // If this is a vendor invoice, update settlement status
        if ($this->settlement_id && $this->type === 'vendor_invoice') {
            $this->settlement->markAsPaid($this->invoice_number, 'invoice');
        }
    }

    public function cancel(): void
    {
        $this->status = 'cancelled';
        $this->save();
    }

    public function createCreditNote(float $amount, string $reason): self
    {
        $creditNote = $this->replicate();
        $creditNote->type = 'credit_note';
        $creditNote->invoice_number = $this->generateCreditNoteNumber();
        $creditNote->credit_note_id = $this->id;
        $creditNote->total = -$amount;
        $creditNote->status = 'issued';
        $creditNote->issued_at = now();
        $creditNote->footer_notes = $reason;
        $creditNote->save();

        return $creditNote;
    }

    protected function generateCreditNoteNumber(): string
    {
        $prefix = 'CN-'.date('Ymd');
        $last = Invoice::where('invoice_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->first();

        $number = $last ? intval(substr($last->invoice_number, -4)) + 1 : 1;

        return $prefix.'-'.str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function getPdfUrl(): ?string
    {
        if ($this->pdf_path) {
            return \Storage::disk('s3')->temporaryUrl(
                $this->pdf_path,
                now()->addMinutes(15)
            );
        }

        return null;
    }
}
