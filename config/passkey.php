<?php

return [
    'rp_name' => env('PASSKEY_RP_NAME', 'Hey Solana'),
    'rp_id' => env('PASSKEY_RP_ID', 'hey.yraytestings.com.ng'),
    'apple_team_id' => env('PASSKEY_APPLE_TEAM_ID', 'HRN328485T'),
    'ios_bundle_id' => env('PASSKEY_IOS_BUNDLE_ID', 'com.maskyray.heysolana'),
    'android_package' => env('PASSKEY_ANDROID_PACKAGE', 'com.maskyray.heysolana'),
    // Comma-separated SHA256 cert fingerprints (debug + release). Add Play/App signing cert via .env.
    'android_sha256_cert' => env(
        'PASSKEY_ANDROID_SHA256_CERT',
        'FA:C6:17:45:DC:09:03:78:6F:B9:ED:E6:2A:96:2B:39:9F:73:48:F0:BB:6F:89:9B:83:32:66:75:91:03:3B:9C,C3:53:46:EA:F3:63:F9:61:0F:78:EA:D6:8B:0F:B2:E4:DF:94:16:D2:84:84:D5:EE:CF:48:FB:B3:B2:04:9B:5D,4F:FA:99:4D:2C:39:A5:6C:CF:97:3E:1F:17:7A:88:A9:0E:86:88:B2:F2:94:5B:7B:75:E9:21:29:F3:AA:6D:DC'
    ),
    'mpc_session_ttl_seconds' => (int) env('MPC_SESSION_TTL', 300),
];
