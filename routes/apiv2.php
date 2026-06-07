<?php

use App\Http\Controllers\AddressBookController;
use App\Http\Controllers\MpcDeviceLinkController;
use App\Http\Controllers\MpcWalletController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\VoiceProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;


/*
This is the new V2 route
*/

Route::prefix('v2')->group(function () {
    //User Routes

    Route::post('/fetch-users', [AddressBookController::class, 'fetch_users']);
    Route::get('/user-distribution-analytics', [AddressBookController::class, 'getUserDistributionAnalytics']);

    Route::post('/register-users', [AddressBookController::class, 'registerUser']);
    Route::post('/verify-code', [AddressBookController::class, 'verifyCode']);
    Route::post('/create-pin', [AddressBookController::class, 'createPin']);
    Route::post('/add-walletaddress', [AddressBookController::class, 'addWalletAddress']);
    Route::post('/get-code', [AddressBookController::class, 'getCode']);

    Route::prefix('voice')->group(function () {
        Route::get('/status', [VoiceProfileController::class, 'status']);
        Route::post('/enroll', [VoiceProfileController::class, 'enroll']);
        Route::post('/verify', [VoiceProfileController::class, 'verify']);
        Route::post('/verify-stream/session', [VoiceProfileController::class, 'streamSession']);
        Route::post('/verify-stream/complete', [VoiceProfileController::class, 'verifyStreamComplete']);
    });

    Route::prefix('passkey')->group(function () {
        Route::post('/register/options', [PasskeyController::class, 'registerOptions']);
        Route::post('/register/verify', [PasskeyController::class, 'registerVerify']);
        Route::post('/auth/options', [PasskeyController::class, 'authOptions']);
        Route::post('/auth/verify', [PasskeyController::class, 'authVerify']);
    });

    Route::prefix('mpc')->group(function () {
        Route::post('/wallets/create', [MpcWalletController::class, 'createWallet']);
        Route::post('/passkey/register', [MpcWalletController::class, 'registerPasskey']);
        Route::post('/session/open', [MpcWalletController::class, 'openSession']);
        Route::post('/sign/share-b', [MpcWalletController::class, 'releaseShareB']);
        Route::post('/share-a/prf/backup', [MpcWalletController::class, 'backupShareAPrf']);
        Route::post('/share-a/prf/fetch', [MpcWalletController::class, 'fetchShareAPrf']);

        Route::prefix('link')->group(function () {
            Route::post('/request', [MpcDeviceLinkController::class, 'requestLink']);
            Route::post('/status', [MpcDeviceLinkController::class, 'linkStatus']);
            Route::post('/approve', [MpcDeviceLinkController::class, 'approveLink']);
            Route::post('/lookup-code', [MpcDeviceLinkController::class, 'lookupByCode']);
        });

        Route::prefix('upgrade')->group(function () {
            Route::post('/challenge', [MpcWalletController::class, 'upgradeChallenge']);
            Route::post('/verify', [MpcWalletController::class, 'upgradeVerify']);
            Route::post('/complete', [MpcWalletController::class, 'upgradeComplete']);
            Route::post('/ack-mnemonic-removed', [MpcWalletController::class, 'ackMnemonicRemoved']);
        });
    });


    //personal contacts

    Route::post('/add-contact', [AddressBookController::class, 'addContact']);
    Route::get('/get-contacts/{id}', [AddressBookController::class, 'getContacts']);
    Route::get('/delete-contact/{id}', [AddressBookController::class, 'deleteContact']);
    Route::post('/update-contact', [AddressBookController::class, 'updateContact']);



    Route::post('/saveId', function (Request $request) {
        $data = [
            'username' => $request->username,
            'chain' => $request->chain,
            'created_at' => now(),
            'updated_at' => now(),
        ];




        $exists = DB::table('selectedchain')->where('username', $request->username)->exists();



        if ($exists) {
            //Update
            $update = DB::table('selectedchain')->where('username', $data['username'])->update($data);
            return response()->json(['status' => 'OK', 'message' => "Item Updated", 'data' => json_encode($data)]);
        } else {
            //save
            $save = DB::table('selectedchain')->insert($data);


            return response()->json(['status' => 'OK', 'message' => "Item Saved"]);
        };
    });

    Route::get('/getchain/{id}', function ($id) {
        $exists = DB::table('selectedchain')->where('username', $id)->exists();
        if ($exists) {
            $id = DB::table('selectedchain')->where('username', $id)->first();
            return response()->json(['chain' => $id->chain]);
        }
        return response()->json(['chain' => null]);
    });

    Route::get('/deletechain/{id}', function ($id) {
        $id = DB::table('selectedchain')->where('username', $id)->delete();
        return response()->json(['status' => 'OK']);
    });


    Route::post('/transcribe', function (Request $request) {
        $request->validate([
            'audio' => 'required|string', // base64-encoded PCM
        ]);

        $base64Audio = $request->input('audio');


        // Remove metadata prefix if present
        if (str_contains($base64Audio, ',')) {
            [, $base64Audio] = explode(',', $base64Audio, 2);
        }

        // Setup directory
        $audioDir = public_path('audios');
        if (!file_exists($audioDir)) {
            mkdir($audioDir, 0777, true);
        }

        // Unique filenames
        $uuid = Str::uuid()->toString();
        $pcmPath = "{$audioDir}/{$uuid}.pcm";
        $wavPath = "{$audioDir}/{$uuid}.wav";

        // Save the PCM file
        // file_put_contents($pcmPath, $base64Audio);

        $wavData = pcmToWav($base64Audio);

        // Save WAV file
        file_put_contents($wavPath, $wavData);

        // Whisper transcription
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('openai.api_key'),
        ])->attach(
            'file',
            file_get_contents($wavPath),
            'audio.wav'
        )->post('https://api.openai.com/v1/audio/transcriptions', [
            'model' => 'whisper-1',
        ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Transcription failed',
                'details' => $response->body(),
            ], 500);
        }

        // unlink($pcmPath);
        // unlink($wavPath);

        return response()->json([
            'transcript' => $response['text'],
        ]);
    });
});

if (!function_exists('pcmToWav')) {
    function pcmToWav(string $pcmData, int $sampleRate = 16000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $dataLength = strlen($pcmData);
        $blockAlign = $channels * ($bitsPerSample / 8);
        $byteRate = $sampleRate * $blockAlign;
        $wavHeader = "RIFF" .
            pack('V', 36 + $dataLength) .
            "WAVEfmt " .
            pack('V', 16) .           // Subchunk1Size (16 for PCM)
            pack('v', 1) .            // AudioFormat (1 for PCM)
            pack('v', $channels) .
            pack('V', $sampleRate) .
            pack('V', $byteRate) .
            pack('v', $blockAlign) .
            pack('v', $bitsPerSample) .
            "data" .
            pack('V', $dataLength);

        return $wavHeader . $pcmData;
    }
}
