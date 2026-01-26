<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    /**
     * Logout User
     *
     * Revoke the current authentication token and end the user's session.
     *
     * @group Authentication
     *
     * @authenticated
     *
     * @response 200 {
     *   "message": "Successfully logged out"
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }
}
