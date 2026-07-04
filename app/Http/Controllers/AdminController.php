<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\AdminCreated;
use App\Mail\AdminPasswordReset;
use App\Models\User;
use App\Services\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function __construct(
        private readonly AdminAuditService $audit,
    ) {}

    public function create_admin(Request $request): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users',
                'admin_role' => 'nullable|string|in:super_admin,support,billing',
            ]);
            if ($validator->fails()) {
                return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            $password = Str::password(16, letters: true, numbers: true, symbols: true);

            $admin = User::where('email', $request->email)->first();
            if ($admin) {
                return response()->json(['message' => 'Admin already exists'], 400);
            }

            $admin = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($password),
                'is_admin' => true,
                'admin_role' => $request->input('admin_role', 'support'),
            ]);

            Mail::to($admin->email)->send(new AdminCreated($admin, $password));

            $this->audit->log($request, 'admin.created', 'user', $admin->id, [
                'email' => $admin->email,
            ]);

            return response()->json([
                'message' => 'Admin created successfully',
                'admin' => $this->formatAdmin($admin),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Admin creation failed', 'error' => $e->getMessage()], 500);
        }
    }

    public function login_admin(Request $request): JsonResponse
    {
        $admin = User::where('email', $request->email)->first();
        if (! $admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        if (! $admin->is_admin) {
            return response()->json(['message' => 'Unauthorized admin access'], 403);
        }

        if (! Hash::check($request->password, $admin->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $verification_code = rand(100000, 999999);
        $admin->verification_code = Hash::make($verification_code);
        $admin->save();

        Mail::raw("Hello {$admin->name}, your verification code is: {$verification_code}", function ($message) use ($admin) {
            $message->to($admin->email)
                ->subject('Bizgrid Admin Verification Code');
        });

        if (config('app.env') === 'local') {
            Log::info('Admin verification code', ['email' => $admin->email, 'code' => $verification_code]);
        }

        return response()->json([
            'message' => 'Verification code sent to admin email',
            'admin' => $this->formatAdmin($admin),
        ]);
    }

    public function verify_admin(Request $request): JsonResponse
    {
        try {
            $admin = User::where('email', $request->email)->first();
            if (! $admin) {
                return response()->json(['message' => 'Admin not found'], 404);
            }

            if (! $admin->is_admin) {
                return response()->json(['message' => 'Unauthorized admin access'], 403);
            }

            if (! Hash::check($request->verification_code, $admin->verification_code)) {
                return response()->json(['message' => 'Invalid verification code'], 401);
            }

            $token = $admin->createToken('admin-token')->plainTextToken;

            $admin->token = $token;
            $admin->email_verified_at = now();
            $admin->save();

            return response()->json([
                'message' => 'Admin verified successfully',
                'admin' => $this->formatAdmin($admin),
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Admin verification failed', 'error' => $e->getMessage()], 500);
        }
    }

    public function delete_admin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $admin = User::where('email', $request->email)->first();
        if (! $admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        if (! $admin->is_admin) {
            return response()->json(['message' => 'User is not an admin'], 400);
        }

        if ($request->user()?->id === $admin->id) {
            return response()->json(['message' => 'You cannot delete your own account'], 400);
        }

        $this->audit->log($request, 'admin.deleted', 'user', $admin->id, [
            'email' => $admin->email,
        ]);

        $admin->delete();

        return response()->json([
            'message' => 'Admin deleted successfully',
        ]);
    }

    public function fetch_admins(Request $request): JsonResponse
    {
        $admins = User::query()
            ->where('is_admin', true)
            ->orderBy('name')
            ->get()
            ->map(fn (User $admin) => $this->formatAdmin($admin));

        return response()->json([
            'message' => 'Admins fetched successfully',
            'admins' => $admins,
        ]);
    }

    public function reset_admin_password(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|exists:users,id',
            ]);
            if ($validator->fails()) {
                return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            $admin = User::find($request->id);
            if (! $admin || ! $admin->is_admin) {
                return response()->json(['message' => 'Admin not found'], 404);
            }

            $password = Str::password(16, letters: true, numbers: true, symbols: true);

            $admin->password = Hash::make($password);
            $admin->save();

            Mail::to($admin->email)->send(new AdminPasswordReset($admin, $password));

            $this->audit->log($request, 'admin.password_reset', 'user', $admin->id);

            return response()->json([
                'message' => 'Admin password reset successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Admin password reset failed', 'error' => $e->getMessage()], 500);
        }
    }

    public function update_profile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,'.$request->user()->id,
        ]);

        $admin = $request->user();
        $admin->fill($data);
        $admin->save();

        return response()->json([
            'message' => 'Profile updated',
            'admin' => $this->formatAdmin($admin),
        ]);
    }

    public function request_password_reset(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);
        $admin = User::where('email', $data['email'])->where('is_admin', true)->first();

        if (! $admin) {
            return response()->json(['message' => 'If that account exists, a reset code was sent.']);
        }

        $code = (string) rand(100000, 999999);
        $admin->verification_code = Hash::make($code);
        $admin->save();

        Mail::raw("Hello {$admin->name}, your password reset code is: {$code}", function ($message) use ($admin) {
            $message->to($admin->email)->subject('Bizgrid Admin Password Reset');
        });

        if (config('app.env') === 'local') {
            Log::info('Admin password reset code', ['email' => $admin->email, 'code' => $code]);
        }

        return response()->json(['message' => 'If that account exists, a reset code was sent.']);
    }

    public function reset_password_with_code(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|max:128',
        ]);

        $admin = User::where('email', $data['email'])->where('is_admin', true)->first();
        if (! $admin || ! Hash::check($data['code'], $admin->verification_code)) {
            return response()->json(['message' => 'Invalid reset code'], 401);
        }

        $admin->password = Hash::make($data['password']);
        $admin->verification_code = null;
        $admin->save();

        return response()->json(['message' => 'Password updated. You can sign in now.']);
    }

    public function revoke_sessions(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $target = User::find($data['user_id']);

        if (! $target?->is_admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        $target->tokens()->delete();
        $this->audit->log($request, 'admin.sessions_revoked', 'user', $target->id);

        return response()->json(['message' => 'All sessions revoked for '.$target->email]);
    }

    private function formatAdmin(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'admin_role' => $user->admin_role ?? 'super_admin',
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}
