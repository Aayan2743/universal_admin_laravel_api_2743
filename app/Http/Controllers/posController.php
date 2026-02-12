<?php
namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariantCombination;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class posController extends Controller
{
    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'customer_id'        => 'nullable|exists:users,id',
            'payment_method'     => 'required|string',
            'paid_amount'        => 'required|numeric|min:0',

            'customer_name'      => 'nullable|string|max:255',
            'customer_phone'     => 'nullable|string|max:20',

            'items'              => 'required|array|min:1',

            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.variant_id' => 'required|integer|exists:product_variant_combinations,id',
            'items.*.qty'        => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()->first(),
            ], 422);
        }

        DB::beginTransaction();

        try {

            $subtotal      = 0;
            $discountTotal = 0;
            $taxTotal      = 0;

            $itemsData = [];

            foreach ($request->items as $item) {

                $variant = ProductVariantCombination::with('product', 'images')
                    ->lockForUpdate()
                    ->findOrFail($item['variant_id']);

                // 🚫 Prevent overselling
                if ($variant->quantity < $item['qty']) {
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient stock for {$variant->sku}",
                    ], 422);
                }

                $price    = $variant->extra_price;
                $discount = $variant->discount ?? 0;
                $tax      = 0; // Add GST logic if needed

                $lineTotal = ($price - $discount) * $item['qty'];

                $subtotal      += $price * $item['qty'];
                $discountTotal += $discount * $item['qty'];

                $itemsData[]  = [
                    'variant'  => $variant,
                    'price'    => $price,
                    'discount' => $discount,
                    'tax'      => $tax,
                    'qty'      => $item['qty'],
                    'total'    => $lineTotal,
                ];
            }

            $grandTotal   = $subtotal - $discountTotal + $taxTotal;
            $changeAmount = $request->paid_amount - $grandTotal;

            if ($changeAmount < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paid amount is insufficient',
                ], 422);
            }

            // 🧾 Create Sale
            $sale = Sale::create([
                'invoice_number' => 'INV-' . now()->format('YmdHis') . '-' . rand(100, 999),
                'customer_id'    => $request->customer_id,
                'subtotal'       => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total'      => $taxTotal,
                'grand_total'    => $grandTotal,

                'payment_method' => $request->payment_method,
                'paid_amount'    => $request->paid_amount,
                'change_amount'  => $changeAmount,

                'customer_name'  => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'status'         => 'completed',
            ]);

            // 🧾 Save Sale Items + Deduct Stock
            foreach ($itemsData as $data) {

                $variant = $data['variant'];

                SaleItem::create([
                    'sale_id'                => $sale->id,

                    'product_id'             => $variant->product->id,
                    'variant_combination_id' => $variant->id,

                    'product_name'           => $variant->product->name,
                    'variant_name'           => $variant->sku,
                    'sku'                    => $variant->sku,

                    'product_image'          => optional($variant->images->first())->image_path
                        ? asset('storage/' . $variant->images->first()->image_path)
                        : null,

                    'price'                  => $data['price'],
                    'discount'               => $data['discount'],
                    'tax'                    => $data['tax'],

                    'quantity'               => $data['qty'],
                    'total'                  => $data['total'],
                ]);

                // 🔥 Deduct Stock
                $variant->decrement('quantity', $data['qty']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data'    => [
                    'sale_id'        => $sale->id,
                    'invoice_number' => $sale->invoice_number,
                    'grand_total'    => $sale->grand_total,
                ],
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

}
