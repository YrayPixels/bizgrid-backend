<?php

namespace App\Http\Controllers;

use App\Mail\AgentXWelcomeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class TwitterBotController extends Controller
{

    //tweet //
    //create tweet //
    //respond to tweet //
    //fetch tweet //
   public function add_tweet (Request $request) {
        $tweet_id = $request->tweet_id;
        $tweet = $request->tweet;
        $created_by = $request->user_id;
        $data = [
            'tweetid' => $tweet_id,
            'text' => $tweet,
            'createdby' => $created_by,
            'status' => 'pending',
            "created_at" => now(),
            "updated_at" => now(),
        ];
        //if the tweet is saved already skip
        $check_tweet = DB::table('twitterbot')->where('tweetid', $tweet_id)->exists();
        if($check_tweet) {
            return response()->json(['message' => 'Tweet already added'], 200);
        }else{

                $save  = DB::table('twitterbot')->insert($data);
                if($save) {
                    return response()->json(['message' => 'Tweet added successfully'],200);
                }else {
                    return response()->json(['message' => 'Tweet not added'],400);
                }
        }
    }




   public function mark_response ($id) {

        $data = [
            'status' => 'responded',
                    "updated_at" => now(),
        ];
            $update_tweet = DB::table('twitterbot')->where('tweetid', $id)->update($data);
    if($update_tweet) {
        return response()->json(['message' => 'Tweet marked as responded'], 200);
       }else {
        return response()->json(['message' => 'Tweet not marked as responded'], 400);
       }

   }
   public function fetch_tweets () {
    $tweets = DB::table('twitterbot')->where('status', 'pending')->get();
    if($tweets) {
        return response()->json($tweets, 200);
    }else {
        return response()->json(['message' => 'No tweets found'], 400);
    }
   }

   //user
   //register user //send mail
   //verify user
   public function register_bot_user (Request $request) {
    $username = $request->username;
    $user_id = $request->user_id;
    $phone_number = $request->phone_number;
    $email_address = $request->email_address;
    $wallet_address = $request->wallet_address;

    $data =[
        'username' => $username,
        'user_id' => $user_id,
        'phone_number' => $phone_number,
        'email_address' => $email_address,
        'verification_code' => "verified",
        'verification_status' => 'verified',
        'wallet_address' => $wallet_address
    ];

        Mail::to($data['email_address'])->send(new AgentXWelcomeMail($data));
    
    $save= DB::table('twitterbotusers')->insert($data);
    if($save) {
        return response()->json(['message' => 'User registered successfully'], 200);
       }else {
        return response()->json(['message' => 'User not registered'], 400);
       }

   }

   public function fetch_user($id){
    $user = DB::table('twitterbotusers')->where('user_id', $id)->orWhere('username', $id)->first();
    if($user) {
        return response()->json($user, 200);
    }else {
        return response()->json(['message' => 'User not found'], 400);
    }
   }

   public function verify_bot_user(Request $request) {
        $user_id = $request->user_id;
        $verification_code = $request->verification_code;
        $user = DB::table('twitterbotusers')->where('user_id', $user_id)->first();

    if($user->verifcation_code == $verification_code) {
        $update_user = DB::table('twitterbotusers')->where('user_id', $user_id)->update(['verification_status' => 'verified']);
        if($update_user) {
            return response()->json(['message' => 'User verified successfully'], 200);
        }else {
            return response()->json(['message' => 'User not verified'], 400);
        }
        }else {
            return response()->json(['message' => 'Invalid verification code'], 400);
        }

   }
}
