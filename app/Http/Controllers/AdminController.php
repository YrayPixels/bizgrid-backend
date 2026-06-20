<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\AdminCreated;
use App\Mail\AdminPasswordReset;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function create_admin(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users',
            ]);
            if ($validator->fails()) {
                return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            // create a random password words and numbers mixed together
            $alphabets = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z'];
            $numbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

            $password = '';
            for ($i = 0; $i < 8; $i++) {
                if ($i % 2 == 0) {
                    $password .= $alphabets[array_rand($alphabets)];
                } else {
                    $password .= $numbers[array_rand($numbers)];
                }
            }

            // check if admin already exists
            $admin = User::where('email', $request->email)->first();
            if ($admin) {
                return response()->json(['message' => 'Admin already exists'], 400);
            }


            $admin = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($password),
            ]);

            $token = $admin->createToken('admin-token')->plainTextToken;

            $admin->token = $token;
            $admin->save();

            //send details to admin mail
            Mail::to($admin->email)->send(new AdminCreated($admin, $password));

            return response()->json([
                'message' => 'Admin created successfully',
                'admin' => $admin,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Admin creation failed', 'error' => $e->getMessage()], 500);
        }
    }


    public function login_admin(Request $request)
    {
        $admin = User::where('email', $request->email)->first();
        if (!$admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        if (!Hash::check($request->password, $admin->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // send verification code to admin email
        $verification_code = rand(100000, 999999);
        $admin->verification_code = Hash::make($verification_code);
        $admin->save();

        // send verification code to admin email

        Mail::raw("Hello {$admin->name}, your verification code is: {$verification_code}", function ($message) use ($admin) {
            $message->to($admin->email)
                ->subject('StoreHause Admin Verification Code');
        });

        return response()->json([
            'message' => 'Verification code sent to admin email',
            'admin' => $admin,
        ]);
    }


    public function verify_admin(Request $request)
    {
        try {

            $admin = User::where('email', $request->email)->first();
            if (!$admin) {
                return response()->json(['message' => 'Admin not found'], 404);
            }


            if (!Hash::check($request->verification_code, $admin->verification_code)) {
                return response()->json(['message' => 'Invalid verification code'], 401);
            }

            $token = $admin->createToken('admin-token')->plainTextToken;

            $admin->token = $token;
            $admin->email_verified_at = now();
            $admin->save();

            return response()->json([
                'message' => 'Admin verified successfully',
                'admin' => $admin,
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Admin verification failed', 'error' => $e->getMessage()], 500);
        }
    }

    public function delete_admin(Request $request)
    {
        $admin = User::where('email', $request->email)->first();
        if (!$admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        $admin->delete();

        return response()->json([
            'message' => 'Admin deleted successfully',
        ]);
    }

    public function fetch_admins(Request $request)
    {
        $admins = User::all();
        return response()->json([
            'message' => 'Admins fetched successfully',
            'admins' => $admins,
        ]);
    }

    public function reset_admin_password(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|exists:users,id',
            ]);
            if ($validator->fails()) {
                return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            $admin = User::find($request->id);
            if (!$admin) {
                return response()->json(['message' => 'Admin not found'], 404);
            }

            // Generate a new temporary password and email it to the admin
            $password = Str::lower(Str::random(10)) . rand(10, 99);

            $admin->password = Hash::make($password);
            $admin->save();

            Mail::to($admin->email)->send(new AdminPasswordReset($admin, $password));

            return response()->json([
                'message' => 'Admin password reset successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Admin password reset failed', 'error' => $e->getMessage()], 500);
        }
    }
}
