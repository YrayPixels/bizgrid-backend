<?php

namespace App\Http\Controllers;

use App\Mail\WaitlistConfirmationMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WaitlistController extends Controller
{
    public function get_waitlist() {
//get all users on waitlist
$waitlist =  DB::table('waitlist')->get();
if($waitlist) {
    return response()->json($waitlist, 200);
}else{
    return response()->json(['message' => 'No user on waitlist'], 400);
}
    }
    public function add_to_waitlist (Request $request) {
        $email_address = $request->email_address;
        $first_name = $request->first_name;
        $last_name = $request->last_name;
        $country = $request->country;
        $wallet_address = $request->wallet_address;

        $data = [
            'email_address' => $email_address,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'country' => $country,
            'wallet_address' => $wallet_address
        ];

        //Send mail to confirm waitlist addition
        try {
            Mail::to($data['email_address'])->send(new WaitlistConfirmationMail($data));
        } catch (\Exception $e) {
            // Handle the exception (e.g., log it or notify the user)
            Log::error('Mail sending failed: ' . $e->getMessage());
        }
        $save = DB::table('waitlist')->insert($data);
        if ($save) {
            return response()->json(['message' => 'User added to waitlist'], 200);
        }else{
            return response()->json(['message' => 'User not added to waitlist'], 400);
        }
    }
}

