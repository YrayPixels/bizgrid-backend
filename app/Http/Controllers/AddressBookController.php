<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AddressBookController extends Controller
{

    public function getContacts($id)
    {
        $contacts = DB::table('personal_contacts')->where('user_id', $id)->get();
        return response()->json(['status' => "success", 'data' => $contacts], 200);
    }


    public function deleteContact($id)
    {
        $contact = DB::table('personal_contacts')->where('id', $id)->first();
        if (!$contact) {
            return response()->json(['status' => "error", 'message' => 'Contact not found'], 400);
        }
        $delete = DB::table('personal_contacts')->where('id', $id)->delete();
        if ($delete) {
            return response()->json(['status' => "success", 'message' => 'Contact deleted successfully'], 200);
        }
    }

    public function updateContact(Request $request)
    {
        $contact_id = $request->contact_id;

        $data = [
            'username' => $request->username,
            'mobile_number' => $request->phone_number,
            'wallet_address' => $request->wallet_address,
            'updated_at' => now(),
        ];

        $user = DB::table('addressbook')->where('username', $request->username)->first();
        if ($user) {
            return response()->json(['status' => "error", 'message' => 'Username already Taken!'], 400);
        }

        $update = DB::table('personal_contacts')->where('id', $contact_id)->update($data);

        if ($update) {
            return response()->json(['status' => "success", 'message' => 'Contact updated successfully'], 200);
        } else {
            return response()->json(['status' => "error", 'message' => 'Contact not updated'], 400);
        }
    }


    public function addContact(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255',
            'phone_number' => 'required|string|max:255',
            'wallet_address' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 400);
        }

        $data = [
            'username' => $request->username,
            'mobile_number' => $request->phone_number,
            'wallet_address' => $request->wallet_address,
            'user_id' => $request->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        //check if username exists in addressbook
        $user = DB::table('addressbook')
            ->where('wallet_address', $request->wallet_address)
            ->orWhere('username', $request->username)
            ->orWhere('phone_number', $request->phone_number)
            ->first();

        if (!empty($user)) {
            return response()->json([
                'status' => "existed",
                'message' => 'User already registered, Kindly Import',
                'user' => $user
            ], 200);
        }

        //check if username exists in personal_contacts
        $user = DB::table('personal_contacts')
            ->where('user_id', $request->user_id)
            ->where(function ($query) use ($request) {
                $query->where('username', $request->username)
                ->orWhere('mobile_number', $request->phone_number);
            })
            ->first();

        if ($user) {
            return response()->json(['status' => "error", 'message' => 'User already registered in your contacts',], 400);
        }


        //save to db
        $save = DB::table('personal_contacts')->insert($data);
        if ($save) {
            return response()->json(['status' => "success", 'message' => 'User added successfully'], 200);
        } else {
            return response()->json(['status' => "error", 'message' => 'User not added'], 400);
        }
    }

    public function addWalletAddress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:255',
            'username' => 'required|string|max:255',
        ]);

        // Return validation errors as JSON
        if ($validator->fails()) {
            $errors = implode(', ', $validator->errors()->all());
            return response()->json([
                'message' => $errors
            ], 500);
        }

        $username = $request->username;
        $wallet_address = $request->wallet_address;

        $user = DB::table('addressbook')->where('username', $username)->first();
        if (!$user) {
            return response()->json(['message' => 'User not registered'], 400);
        }

        $update_user = DB::table('addressbook')->where('username', '=', $username)->update(['wallet_address' => $wallet_address, "updated_at" => now()]);

        if ($update_user) {
            return response()->json(['message' => 'Wallet address created successfully'], 200);
        } else {
            return response()->json(['message' => 'Wallet address not created'], 400);
        }
    }

    public function createPin(Request $request)
    {
        $pin = $request->pin;
        $update_user = DB::table('addressbook')->where('phone_number', $request->phone_number)->update(['pin' => Hash::make($pin)]);
        if ($update_user) {
            return response()->json(['message' => 'Pin created successfully'], 200);
        } else {
            return response()->json(['message' => 'Pin not created'], 400);
        }
    }

    public function verifyCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'verification_code' => 'required|string|max:6',
            'username' => 'required|string|max:255',
        ]);

        // Return validation errors as JSON
        if ($validator->fails()) {
            $errors = implode(', ', $validator->errors()->all());
            return response()->json([
                'message' => $errors
            ], 500);
        }

        $username = $request->username;
        $verification_code = $request->verification_code;

        $user = DB::table('addressbook')->where('username', $username)->first();
        if (!$user) {
            return response()->json(['message' => 'User not registered'], 400);
        }

        // Test/dev bypass: fixed OTP for specific mobile number
        $normalizedPhone = $this->normalizePhoneForTest($user->phone_number ?? '');
        if ($normalizedPhone === '2348132532430' && $verification_code === '123456') {
            DB::table('addressbook')->where('username', $username)->update(['updated_at' => now(), 'verification_status' => 'verified']);
            return response()->json(['message' => 'Account verified successfully'], 200);
        }

        if (Hash::check($verification_code, $user->verification_code)) {
            $update_user = DB::table('addressbook')->where('username', $username)->update(['updated_at' => now(), 'verification_status' => 'verified']);
            // if ($update_user) {
            return response()->json(['message' => 'Account verified successfully'], 200);
            // }
            // return response()->json(['message' => 'User not verified'], 400);
        } else {
            return response()->json(['message' => 'Invalid verification code'], 400);
        }
    }

    public function registerUser(Request $request)
    {
        // Manually validate input
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255|unique:addressbook,username',
            'phone_number' => 'required|string|max:255|unique:addressbook,phone_number',
            'country' => 'nullable|string|max:255',
        ]);

        // Return validation errors as JSON
        if ($validator->fails()) {
            $errors = implode(', ', $validator->errors()->all());
            return response()->json([
                'message' => $errors
            ], 500);
        }
        // Test/dev: fixed OTP for specific mobile number
        $normalizedPhone = $this->normalizePhoneForTest($request->phone_number);
        if ($normalizedPhone === '2348132532430') {
            $verification_code = '123456';
            $hashed_verification_code = Hash::make($verification_code);
        } else {
            $verification_code = mt_rand(100000, 999999);
            $hashed_verification_code = Hash::make($verification_code);
        }

        $data = [
            "username" => $request->username,
            "phone_number" => $request->phone_number,
            "wallet_address" => $request->wallet_address,
            'verification_code' => $hashed_verification_code,
            "created_at" => now(),
            "updated_at" => now(),
        ];
        if ($request->filled('country')) {
            $data['country'] = $request->country;
        }

        if ($normalizedPhone !== '2348132532430') {
            $response = $this->sendVerificationCode($data['phone_number'], $verification_code);
            if (!$response['status']) {
                return response()->json(['message' => $response['message']], 400);
            }
        }

        $save = DB::table('addressbook')->insert($data);
        if ($save) {
            $user = DB::table('addressbook')->where('phone_number', $data['phone_number'])->first();
            return response()->json($user, 200);
        } else {
            return response()->json($save, 400);
        }
    }



    public function getCode(Request $request)
    {
        // Manually validate input
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:255',
            'country' => 'nullable|string|max:255',
        ]);

        // Return validation errors as JSON
        if ($validator->fails()) {
            $errors = implode(', ', $validator->errors()->all());
            return response()->json([
                'message' => $errors
            ], 500);
        }

        /// Check if user exists
        $user = DB::table('addressbook')->where('phone_number', $request->phone_number)->first();
        if (!$user) {
            return response()->json(['message' => 'Mobile number not registered'], 400);
        }

        // Test/dev: fixed OTP for specific mobile number
        $normalizedPhone = $this->normalizePhoneForTest($request->phone_number);
        if ($normalizedPhone === '2348132532430') {
            $verification_code = '123456';
            $hashed_verification_code = Hash::make($verification_code);
        } else {
            $verification_code = mt_rand(100000, 999999);
            $hashed_verification_code = Hash::make($verification_code);
        }

        $data = [
            'verification_code' => $hashed_verification_code,
            "updated_at" => now(),
        ];
        if ($request->filled('country')) {
            $data['country'] = $request->country;
        }

        if ($normalizedPhone !== '2348132532430') {
            $response = $this->sendVerificationCode($request->phone_number, $verification_code);
            if (!$response['status']) {
                return response()->json(['message' => $response['message']], 400);
            }
        }

        $save = DB::table('addressbook')->where('id', $user->id)->update($data);
        if ($save) {
            $user = DB::table('addressbook')->where('phone_number', $request->phone_number)->first();
            return response()->json($user, 200);
        } else {
            return response()->json($save, 400);
        }
    }


    /**
     * Normalize phone for test/dev bypass: strip leading + so +2348132532430 and 2348132532430 match.
     */
    private function normalizePhoneForTest($phone)
    {
        return $phone ? ltrim((string) $phone, '+') : '';
    }

    private function sendVerificationCode($number, $verification_code)
    {
        $curl = curl_init();

        $template = json_encode([
            "messaging_product" => "whatsapp",
            "to" => $number,
            "type" => "template",
            "template" => [
                "name" => "verify_code",
                "language" => ["code" => "en_US"],
                "components" => [
                    [
                        "type" => "body",
                        "parameters" => [
                            ["type" => "text", "text" => $verification_code]
                        ]
                    ],
                    [
                        "type" => "button",
                        "sub_type" => "url",
                        "index" => "0",
                        "parameters" => [
                            ["type" => "text", "text" => $verification_code]
                        ]
                    ]
                ]
            ]
        ]);

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://graph.facebook.com/v22.0/646903795166215/messages',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $template,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer EAAQluT2GHkQBOyu1bwS22RV2sArG1z9kls8Yl3Y1QNlbQdEAMJ3bF5z5nwoqHMRmYAzQQZArStM5Q6jEIRrlYKyTt5uF1bQNIxmoY7KP3PA4c2HTkMYU1g9L2ZA2vy2icepZBV7QtSpl1Uchp4ZCtTte5Tx66LZBhbW4x13LN1FZAFit94FzR7aVhiJQ8E8V4r1gZDZD'
            ),
        ));

        $response = curl_exec($curl);
        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($responseCode >= 400) {
            $error = json_decode($response);
            return [
                "status" => false,
                "message" => $error->error->message
            ];
        }
        curl_close($curl);
        return [
            'status' => true,
            "message" => "Verification code sent successfully"
        ];
    }

    public function getUserDistributionAnalytics()
    {
        // Country distribution based on phone number country codes
        // $countryDistribution = DB::table('addressbook')
        //     ->select(DB::raw('
        //         CASE 
        //             WHEN phone_number LIKE "+234%" THEN "Nigeria"
        //             WHEN phone_number LIKE "+1%" THEN "USA/Canada"
        //             WHEN phone_number LIKE "+44%" THEN "United Kingdom"
        //             WHEN phone_number LIKE "+91%" THEN "India"
        //             WHEN phone_number LIKE "+27%" THEN "South Africa"
        //             WHEN phone_number LIKE "+233%" THEN "Ghana"
        //             WHEN phone_number LIKE "+254%" THEN "Kenya"
        //             WHEN phone_number LIKE "+49%" THEN "Germany"
        //             WHEN phone_number LIKE "+33%" THEN "France"
        //             WHEN phone_number LIKE "+86%" THEN "China"
        //             ELSE "Other"
        //         END as country
        //     '), DB::raw('count(*) as user_count'))
        //     ->groupBy(DB::raw('
        //         CASE 
        //             WHEN phone_number LIKE "+234%" THEN "Nigeria"
        //             WHEN phone_number LIKE "+1%" THEN "USA/Canada"
        //             WHEN phone_number LIKE "+44%" THEN "United Kingdom"
        //             WHEN phone_number LIKE "+91%" THEN "India"
        //             WHEN phone_number LIKE "+27%" THEN "South Africa"
        //             WHEN phone_number LIKE "+233%" THEN "Ghana"
        //             WHEN phone_number LIKE "+254%" THEN "Kenya"
        //             WHEN phone_number LIKE "+49%" THEN "Germany"
        //             WHEN phone_number LIKE "+33%" THEN "France"
        //             WHEN phone_number LIKE "+86%" THEN "China"
        //             ELSE "Other"
        //         END
        //     '))
        //     ->orderBy('user_count', 'desc')
        //     ->get();

        $countryDistribution = DB::table('addressbook')
            ->select(DB::raw('
                CASE 
                    WHEN phone_number LIKE "+234%" THEN "Nigeria"
                    WHEN phone_number LIKE "+1%" THEN "USA/Canada"
                    WHEN phone_number LIKE "+44%" THEN "United Kingdom"
                    WHEN phone_number LIKE "+91%" THEN "India"
                    WHEN phone_number LIKE "+81%" THEN "Japan"
                    WHEN phone_number LIKE "+49%" THEN "Germany"
                    WHEN phone_number LIKE "+33%" THEN "France"
                    WHEN phone_number LIKE "+86%" THEN "China"
                    WHEN phone_number LIKE "+39%" THEN "Italy"
                    WHEN phone_number LIKE "+7%" THEN "Russia"
                    WHEN phone_number LIKE "+55%" THEN "Brazil"
                    WHEN phone_number LIKE "+61%" THEN "Australia"
                    WHEN phone_number LIKE "+27%" THEN "South Africa"
                    WHEN phone_number LIKE "+34%" THEN "Spain"
                    WHEN phone_number LIKE "+46%" THEN "Sweden"
                    WHEN phone_number LIKE "+47%" THEN "Norway"
                    WHEN phone_number LIKE "+48%" THEN "Poland"
                    WHEN phone_number LIKE "+31%" THEN "Netherlands"
                    WHEN phone_number LIKE "+41%" THEN "Switzerland"
                    WHEN phone_number LIKE "+351%" THEN "Portugal"
                    WHEN phone_number LIKE "+32%" THEN "Belgium"
                    WHEN phone_number LIKE "+380%" THEN "Ukraine"
                    WHEN phone_number LIKE "+90%" THEN "Turkey"
                    WHEN phone_number LIKE "+82%" THEN "South Korea"
                    WHEN phone_number LIKE "+966%" THEN "Saudi Arabia"
                    WHEN phone_number LIKE "+971%" THEN "United Arab Emirates"
                    WHEN phone_number LIKE "+92%" THEN "Pakistan"
                    WHEN phone_number LIKE "+880%" THEN "Bangladesh"
                    WHEN phone_number LIKE "+62%" THEN "Indonesia"
                    WHEN phone_number LIKE "+63%" THEN "Philippines"
                    WHEN phone_number LIKE "+66%" THEN "Thailand"
                    WHEN phone_number LIKE "+20%" THEN "Egypt"
                    WHEN phone_number LIKE "+254%" THEN "Kenya"
                    WHEN phone_number LIKE "+233%" THEN "Ghana"
                    WHEN phone_number LIKE "+256%" THEN "Uganda"
                    WHEN phone_number LIKE "+212%" THEN "Morocco"
                    WHEN phone_number LIKE "+231%" THEN "Liberia"
                    WHEN phone_number LIKE "+250%" THEN "Rwanda"
                    WHEN phone_number LIKE "+213%" THEN "Algeria"
                    WHEN phone_number LIKE "+84%" THEN "Vietnam"
                    WHEN phone_number LIKE "+964%" THEN "Iraq"
                    WHEN phone_number LIKE "+98%" THEN "Iran"
                    WHEN phone_number LIKE "+60%" THEN "Malaysia"
                    WHEN phone_number LIKE "+998%" THEN "Uzbekistan"
                    ELSE "Other"
                END as country
            '), DB::raw('count(*) as user_count'))
            ->groupBy(DB::raw('
                CASE 
                    WHEN phone_number LIKE "+234%" THEN "Nigeria"
                    WHEN phone_number LIKE "+1%" THEN "USA/Canada"
                    WHEN phone_number LIKE "+44%" THEN "United Kingdom"
                    WHEN phone_number LIKE "+91%" THEN "India"
                    WHEN phone_number LIKE "+81%" THEN "Japan"
                    WHEN phone_number LIKE "+49%" THEN "Germany"
                    WHEN phone_number LIKE "+33%" THEN "France"
                    WHEN phone_number LIKE "+86%" THEN "China"
                    WHEN phone_number LIKE "+39%" THEN "Italy"
                    WHEN phone_number LIKE "+7%" THEN "Russia"
                    WHEN phone_number LIKE "+55%" THEN "Brazil"
                    WHEN phone_number LIKE "+61%" THEN "Australia"
                    WHEN phone_number LIKE "+27%" THEN "South Africa"
                    WHEN phone_number LIKE "+34%" THEN "Spain"
                    WHEN phone_number LIKE "+46%" THEN "Sweden"
                    WHEN phone_number LIKE "+47%" THEN "Norway"
                    WHEN phone_number LIKE "+48%" THEN "Poland"
                    WHEN phone_number LIKE "+31%" THEN "Netherlands"
                    WHEN phone_number LIKE "+41%" THEN "Switzerland"
                    WHEN phone_number LIKE "+351%" THEN "Portugal"
                    WHEN phone_number LIKE "+32%" THEN "Belgium"
                    WHEN phone_number LIKE "+380%" THEN "Ukraine"
                    WHEN phone_number LIKE "+90%" THEN "Turkey"
                    WHEN phone_number LIKE "+82%" THEN "South Korea"
                    WHEN phone_number LIKE "+966%" THEN "Saudi Arabia"
                    WHEN phone_number LIKE "+971%" THEN "United Arab Emirates"
                    WHEN phone_number LIKE "+92%" THEN "Pakistan"
                    WHEN phone_number LIKE "+880%" THEN "Bangladesh"
                    WHEN phone_number LIKE "+62%" THEN "Indonesia"
                    WHEN phone_number LIKE "+63%" THEN "Philippines"
                    WHEN phone_number LIKE "+66%" THEN "Thailand"
                    WHEN phone_number LIKE "+20%" THEN "Egypt"
                    WHEN phone_number LIKE "+254%" THEN "Kenya"
                    WHEN phone_number LIKE "+233%" THEN "Ghana"
                    WHEN phone_number LIKE "+256%" THEN "Uganda"
                    WHEN phone_number LIKE "+212%" THEN "Morocco"
                    WHEN phone_number LIKE "+231%" THEN "Liberia"
                    WHEN phone_number LIKE "+250%" THEN "Rwanda"
                    WHEN phone_number LIKE "+213%" THEN "Algeria"
                    WHEN phone_number LIKE "+84%" THEN "Vietnam"
                    WHEN phone_number LIKE "+964%" THEN "Iraq"
                    WHEN phone_number LIKE "+98%" THEN "Iran"
                    WHEN phone_number LIKE "+60%" THEN "Malaysia"
                    WHEN phone_number LIKE "+998%" THEN "Uzbekistan"
                    ELSE "Other"
                END
            '))
            ->orderBy('user_count', 'desc')
            ->get();


        // User registration trends by date
        $registrationTrends = DB::table('addressbook')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as registrations'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'asc')
            ->get();

        // User registration trends by month
        $monthlyRegistrations = DB::table('addressbook')
            ->select(DB::raw('YEAR(created_at) as year'), DB::raw('MONTH(created_at) as month'), DB::raw('count(*) as registrations'))
            ->groupBy(DB::raw('YEAR(created_at)'), DB::raw('MONTH(created_at)'))
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->get();

        // Verification status distribution  
        $verificationStatusRaw = DB::table('addressbook')
            ->select('verification_status', DB::raw('count(*) as user_count'))
            ->groupBy('verification_status')
            ->get();

        // Transform the results
        $verificationStatus = $verificationStatusRaw->map(function ($item) {
            $status = 'Pending'; // Default for null/empty
            if ($item->verification_status === 'verified') {
                $status = 'Verified';
            } elseif ($item->verification_status && $item->verification_status !== '') {
                $status = 'Unverified';
            }
            return (object) [
                'status' => $status,
                'user_count' => $item->user_count
            ];
        })->groupBy('status')->map(function ($grouped) {
            return (object) [
                'status' => $grouped->first()->status,
                'user_count' => $grouped->sum('user_count')
            ];
        })->values();

        // Wallet address completion rate
        $walletStatusRaw = DB::table('addressbook')
            ->select('wallet_address', DB::raw('count(*) as user_count'))
            ->groupBy('wallet_address')
            ->get();

        // Transform the results
        $walletStatus = collect([
            (object) ['wallet_status' => 'Has Wallet', 'user_count' => 0],
            (object) ['wallet_status' => 'No Wallet', 'user_count' => 0]
        ]);

        foreach ($walletStatusRaw as $item) {
            if ($item->wallet_address && $item->wallet_address !== '') {
                $walletStatus[0]->user_count += $item->user_count;
            } else {
                $walletStatus[1]->user_count += $item->user_count;
            }
        }

        // PIN creation status
        $pinStatusRaw = DB::table('addressbook')
            ->select('pin', DB::raw('count(*) as user_count'))
            ->groupBy('pin')
            ->get();

        // Transform the results
        $pinStatus = collect([
            (object) ['pin_status' => 'PIN Created', 'user_count' => 0],
            (object) ['pin_status' => 'No PIN', 'user_count' => 0]
        ]);

        foreach ($pinStatusRaw as $item) {
            if ($item->pin && $item->pin !== '') {
                $pinStatus[0]->user_count += $item->user_count;
            } else {
                $pinStatus[1]->user_count += $item->user_count;
            }
        }

        // Recent registrations (last 30 days)
        $recentRegistrations = DB::table('addressbook')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        // Total users
        $totalUsers = DB::table('addressbook')->count();

        // Growth rate (comparing last 30 days to previous 30 days)
        $previousPeriodRegistrations = DB::table('addressbook')
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
            ->count();

        $growthRate = $previousPeriodRegistrations > 0
            ? (($recentRegistrations - $previousPeriodRegistrations) / $previousPeriodRegistrations) * 100
            : 0;

        $data = [
            'country_distribution' => $countryDistribution,
            'registration_trends' => $registrationTrends,
            'monthly_registrations' => $monthlyRegistrations,
            'verification_status' => $verificationStatus,
            'wallet_status' => $walletStatus,
            'pin_status' => $pinStatus,
            'total_users' => $totalUsers,
            'recent_registrations' => $recentRegistrations,
            'growth_rate' => round($growthRate, 2),
        ];

        return response()->json($data);
    }

    public function fetch_users()
    {
        $users = DB::table('addressbook')->get();
        return response()->json($users);
    }

    public function fetchUsersWithFilters(Request $request)
    {
        $query = DB::table('addressbook');

        // Apply search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhere('wallet_address', 'like', "%{$search}%");
            });
        }

        // Apply status filter
        if ($request->has('status') && $request->status && $request->status !== 'all') {
            if ($request->status === 'verified') {
                $query->where('verification_status', 'verified');
            } elseif ($request->status === 'pending') {
                $query->where(function ($q) {
                    $q->whereNull('verification_status')
                        ->orWhere('verification_status', '')
                        ->orWhere('verification_status', '!=', 'verified');
                });
            }
        }

        // Apply wallet status filter
        if ($request->has('wallet_status') && $request->wallet_status && $request->wallet_status !== 'all') {
            if ($request->wallet_status === 'has_wallet') {
                $query->whereNotNull('wallet_address')
                    ->where('wallet_address', '!=', '');
            } elseif ($request->wallet_status === 'no_wallet') {
                $query->where(function ($q) {
                    $q->whereNull('wallet_address')
                        ->orWhere('wallet_address', '');
                });
            }
        }

        // Apply sorting
        $sortField = $request->get('sort_field', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        // Validate sort field to prevent SQL injection
        $allowedSortFields = ['username', 'phone_number', 'wallet_address', 'created_at', 'updated_at'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'created_at';
        }

        $query->orderBy($sortField, $sortDirection);

        // Get total count before pagination
        $total = $query->count();

        // Apply pagination
        $perPage = $request->get('per_page', 10);
        $page = $request->get('page', 1);

        // Validate pagination parameters
        $perPage = max(1, min(100, (int) $perPage)); // Limit per_page between 1 and 100
        $page = max(1, (int) $page);

        $users = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // Transform the data to include verification status
        $users = $users->map(function ($user) {
            $user->verification_status = $user->verification_status ?: 'pending';
            return $user;
        });

        return response()->json([
            'users' => $users,
            'total' => $total,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
            'per_page' => $perPage
        ]);
    }
}
