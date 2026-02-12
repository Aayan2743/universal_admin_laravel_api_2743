<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'  => 'required|string|max:255',
            'phone' => 'required|digits:10|unique:users,phone',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = User::create([
            'name'     => $request->name,
            'phone'    => $request->phone,
            'email'    => 'user_' . Str::random(6) . '@example.com',
            'password' => Hash::make('123456'),
            'role'     => 'user',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully',
            'data'    => $user,
        ]);
    }

    /* ================= BULK STORE ================= */
    public function bulkStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customers'         => 'required|array|min:1',
            'customers.*.name'  => 'required|string|max:255',
            'customers.*.phone' => 'required|digits:10|distinct',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $inserted = [];

        foreach ($request->customers as $customer) {

            if (User::where('phone', $customer['phone'])->exists()) {
                continue;
            }

            $inserted[] = User::create([
                'name'     => $customer['name'],
                'phone'    => $customer['phone'],
                'email'    => 'user_' . Str::random(6) . '@example.com',
                'password' => Hash::make('123456'),
                'role'     => 'user',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Bulk customers created successfully',
            'count'   => count($inserted),
        ]);
    }

    public function searchUser(Request $request)
    {

        $validator = Validator::make($request->all(), [

            'phone' => 'required|digits:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = User::with('addresses')
            ->where('phone', $request->phone)
            ->where('role', 'user')
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'phone'     => $user->phone,

                'addresses' => $user->addresses->map(function ($address) {
                    return [
                        'id'           => $address->id,
                        'address_line' => $address->address,
                        'city'         => $address->city,
                        'state'        => $address->state,
                        'pincode'      => $address->pincode,
                    ];
                }),
            ],
        ]);
    }
}
