<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | Normalize Email
        |--------------------------------------------------------------------------
        */

        $request->merge([
            'email' => strtolower(
                trim(
                    (string) $request->input(
                        'email'
                    )
                )
            ),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Validate
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],

            'device_name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Create Customer
        |--------------------------------------------------------------------------
        */

        $user = User::create([
            'name' =>
                $validated['name'],

            'email' =>
                $validated['email'],

            'password' =>
                Hash::make(
                    $validated['password']
                ),

            'role' =>
                'customer',

            'is_active' =>
                true,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Create Token
        |--------------------------------------------------------------------------
        */

        $token = $user
            ->createToken(
                $validated['device_name']
                    ?? 'mobile-app'
            )
            ->plainTextToken;

        return response()->json([
            'message' =>
                'Account created successfully.',

            'token' =>
                $token,

            'user' =>
                $this->userData($user),
        ], 201);
    }

    public function login(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | Normalize Email
        |--------------------------------------------------------------------------
        */

        $request->merge([
            'email' => strtolower(
                trim(
                    (string) $request->input(
                        'email'
                    )
                )
            ),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Validate
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
            ],

            'password' => [
                'required',
                'string',
            ],

            'device_name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Find User
        |--------------------------------------------------------------------------
        */

        $user = User::query()
            ->where(
                'email',
                $validated['email']
            )
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Verify Credentials
        |--------------------------------------------------------------------------
        */

        if (
            !$user
            ||
            !Hash::check(
                $validated['password'],
                $user->password
            )
        ) {
            throw ValidationException::withMessages([
                'email' => [
                    'The provided credentials are incorrect.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Active Account
        |--------------------------------------------------------------------------
        */

        if (!$user->is_active) {
            return response()->json([
                'message' =>
                    'Your account is inactive.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Create Token
        |--------------------------------------------------------------------------
        */

        $token = $user
            ->createToken(
                $validated['device_name']
                    ?? 'mobile-app'
            )
            ->plainTextToken;

        return response()->json([
            'message' =>
                'Logged in successfully.',

            'token' =>
                $token,

            'user' =>
                $this->userData($user),
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user->is_active) {
            return response()->json([
                'message' =>
                    'Your account is inactive.',
            ], 403);
        }

        return response()->json([
            'user' =>
                $this->userData($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()
            ->currentAccessToken()
            ?->delete();

        return response()->json([
            'message' =>
                'Logged out successfully.',
        ]);
    }

    public function logoutAll(Request $request)
    {
        $request->user()
            ->tokens()
            ->delete();

        return response()->json([
            'message' =>
                'Logged out from all devices.',
        ]);
    }

    private function userData(
        User $user
    ): array {
        return [
            'id' =>
                $user->id,

            'name' =>
                $user->name,

            'email' =>
                $user->email,

            'role' =>
                $user->role,

            'is_active' =>
                (bool) $user->is_active,
        ];
    }
}