<?php
// Copy to config.local.php ON THE SERVER (it is gitignored and web-denied) and
// fill in the real values. config.php merges it over the defaults. This keeps
// MySQL credentials and the admin token out of git.
return [
    'engine' => 'mysql',
    'mysql'  => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'webshop_mrszoko',
        'user' => 'REPLACE_ME',
        'pass' => 'REPLACE_ME',
    ],
    // Must match the adminToken the back-office sends (localStorage['adminToken']).
    'admin_token' => 'REPLACE_WITH_A_LONG_RANDOM_TOKEN',

    // KSeF — the national e-invoice registry. Leave the whole block out (or
    // keep the placeholders) and the channel stays CLOSED: nothing is filed,
    // and the KSeF screen says so instead of pretending. The token is issued
    // by the KSeF application against the seller NIP and is as good as a
    // signature on every invoice we write — it belongs here, never in git.
    // 'public_key' is the PATH on this server to the Ministry of Finance
    // public key file; without it the token cannot be encrypted, so no
    // session can open. 'env': test (default) | demo | prod.
    'ksef' => [
        'nip'        => '',                       // empty → falls back to the invoice seller NIP
        'token'      => 'REPLACE_ME_OR_LEAVE_EMPTY',
        'public_key' => '/etc/ssl/ksef/publicKey.pem',
        'env'        => 'test',
    ],
];
