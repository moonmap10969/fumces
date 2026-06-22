<?php
namespace App\Helpers;

class AESHelper
{
    public static function encrypt($data)
    {
        $key = base64_decode(env('AES_KEY')); // 32 bytes for AES-256
        $iv = random_bytes(16); // 16 bytes IV for CBC
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        // Store IV + encrypted content, then base64 encode
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt($data)
    {
        $key = base64_decode(env('AES_KEY'));
        $raw = base64_decode($data);
        $iv = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }
}