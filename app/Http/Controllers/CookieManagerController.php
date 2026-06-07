<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CookieManagerController extends Controller
{
    public function add_cookie(Request $request)
    {
        $cookie = $request->cookie;
        $expires_at = $request->expires_at;
        $data = [
            'cookie' => $cookie,
            'expires_at' => now()->addDays(7),
            'last_used_at' => now(),
        ];
        $save = DB::table('cookie_manager')->insert($data);
        if ($save) {
            return response()->json($data, 200);
        } else {
            return response()->json($data, 400);
        }
    }

    public function fetch_cookie()
    {
        $cookies = DB::table('cookie_manager')
            ->where('expires_at', '>', now()) // Only fetch cookies that have not expired
            ->first();

        if (!$cookies) {
            return response()->json(['error' => 'No valid cookies found'], 400);
        }

        return response()->json($cookies, 200);
    }
}
