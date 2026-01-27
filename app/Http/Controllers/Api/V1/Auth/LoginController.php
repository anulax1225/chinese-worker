<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    /**
     * Login User
     *
     * Authenticate a user with email and password and receive an authentication token.
     *
     * @group Authentication
     *
     * @unauthenticated
     *
     * @bodyParam email string required The user's email address. Example: john@example.com
     * @bodyParam password string required The user's password. Example: password123
     *
     * @response 200 {
     *   "user": {
     *     "id": 1,
     *     "name": "John Doe",
     *     "email": "john@example.com",
     *     "created_at": "2026-01-26T14:00:00.000000Z",
     *     "updated_at": "2026-01-26T14:00:00.000000Z"
     *   },
     *   "token": "1|abc123def456..."
     * }
     * @response 422 {
     *   "message": "The provided credentials are incorrect.",
     *   "errors": {
     *     "email": [
     *       "The provided credentials are incorrect."
     *     ]
     *   }
     * }
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Get User
     *
     * Get current authentificated user.
     *
     * @group Authentication
     *
     * @authenticated
     *
     * @response 200 {
     *  "id": 1,
     *  "name": "John Doe",
     *  "email": "john@example.com",
     *  "created_at": "2026-01-26T14:00:00.000000Z",
     *  "updated_at": "2026-01-26T14:00:00.000000Z"
     * }
     */
    public function user(Request $request) {
        return $request->user();
    }
}
