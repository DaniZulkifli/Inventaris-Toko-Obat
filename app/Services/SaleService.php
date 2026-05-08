<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(private readonly StockService $stockService)
    {
    }

    public function complete(array $data, User $cashier): Sale
    {
        return DB::transaction(function () use ($data, $cashier): Sale {
            $sale = Sale::query()->create([
                'invoice_number' => $this->generateInvoiceNumber(),
                'cashier_id' => $cashier->id,
                'sale_date' => Carbon::now(config('app.timezone')),
                'customer_name' => $data['customer_name'] ?? 'Pelanggan Umum',
                'payment_method' => $data['payment_method'],
                'status' => SaleStatus::Completed,
                'subtotal' => '0.00',
                'discount' => $this->formatMoney($data['discount'] ?? 0),
                'total_amount' => '0.00',
                'amount_paid' => '0.00',
                'change_amount' => '0.00',
                'gross_margin' => '0.00',
                'notes' => $data['notes'] ?? null,
            ]);

            $subtotal = 0.0;
            $grossMargin = 0.0;

            foreach (array_values($data['items']) as $index => $item) {
                $medicine = Medicine::query()->findOrFail($item['medicine_id']);

                if (! $medicine->is_active) {
                    $this->fail("items.{$index}.medicine_id", 'Obat nonaktif tidak dapat dijual.');
                }

                $quantity = $this->quantity($item['quantity'] ?? 0);
                $batchId = filled($item['medicine_batch_id'] ?? null) ? (int) $item['medicine_batch_id'] : null;
                $options = [
                    'reference_id' => $sale->id,
                    'description' => "{$sale->invoice_number} {$medicine->name}",
                ];

                if ($batchId) {
                    $options['batch'] = $batchId;
                }

                $movements = $this->stockService->saleOut($medicine, $quantity, $cashier, $options);

                foreach ($movements as $movement) {
                    $movement->load('batch.medicine');
                    $batch = $movement->batch;
                    $quantityOut = $this->quantity($movement->quantity_out);
                    $unitPrice = $this->money($this->stockService->sellingPriceFor($batch));
                    $cost = $this->money($movement->unit_cost_snapshot);
                    $itemSubtotal = $this->money($quantityOut * $unitPrice);
                    $itemMargin = $this->money(($unitPrice - $cost) * $quantityOut);

                    SaleItem::query()->create([
                        'sale_id' => $sale->id,
                        'medicine_id' => $medicine->id,
                        'medicine_batch_id' => $batch->id,
                        'medicine_code_snapshot' => $medicine->code,
                        'medicine_name_snapshot' => $medicine->name,
                        'batch_number_snapshot' => $batch->batch_number,
                        'expiry_date_snapshot' => $batch->expiry_date?->toDateString(),
                        'quantity' => $this->formatQuantity($quantityOut),
                        'unit_price_snapshot' => $this->formatMoney($unitPrice),
                        'cost_snapshot' => $this->formatMoney($cost),
                        'subtotal' => $this->formatMoney($itemSubtotal),
                        'gross_margin' => $this->formatMoney($itemMargin),
                    ]);

                    $subtotal = $this->money($subtotal + $itemSubtotal);
                    $grossMargin = $this->money($grossMargin + $itemMargin);
                }
            }

            $discount = $this->money($data['discount'] ?? 0);

            if ($discount < 0) {
                $this->fail('discount', 'Diskon tidak boleh negatif.');
            }

            if ($discount > $subtotal) {
                $this->fail('discount', 'Diskon tidak boleh membuat total penjualan negatif.');
            }

            $total = $this->money($subtotal - $discount);
            $paymentMethod = $data['payment_method'];
            $amountPaid = in_array($paymentMethod, [
                PaymentMethod::Transfer->value,
                PaymentMethod::Qris->value,
                PaymentMethod::Other->value,
            ], true)
                ? $total
                : $this->money($data['amount_paid'] ?? 0);

            if ($paymentMethod === PaymentMethod::Cash->value && $amountPaid < $total) {
                $this->fail('amount_paid', 'Uang diterima tidak boleh kurang dari total pembayaran tunai.');
            }

            $sale->update([
                'subtotal' => $this->formatMoney($subtotal),
                'discount' => $this->formatMoney($discount),
                'total_amount' => $this->formatMoney($total),
                'amount_paid' => $this->formatMoney($amountPaid),
                'change_amount' => $this->formatMoney($paymentMethod === PaymentMethod::Cash->value ? $amountPaid - $total : 0),
                'gross_margin' => $this->formatMoney($grossMargin),
            ]);

            return $sale->refresh()->load(['cashier', 'items.batch', 'items.medicine']);
        });
    }

    private function generateInvoiceNumber(): string
    {
        $prefix = 'INV-'.Carbon::now(config('app.timezone'))->format('Ymd').'-';
        $lastInvoice = Sale::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');
        $nextNumber = $lastInvoice ? ((int) substr($lastInvoice, -4)) + 1 : 1;

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    private function quantity(float|int|string $value): float
    {
        return round((float) $value, 3);
    }

    private function money(float|int|string|null $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function formatQuantity(float|int|string $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function formatMoney(float|int|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([
            $key => $message,
        ]);
    }
}
