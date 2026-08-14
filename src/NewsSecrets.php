<?php
declare(strict_types=1);

final class NewsSecrets
{
    private const PREFIX = 'enc1:';

    public static function encrypt(string $value, string $appSecret): string
    {
        if ($value === '') {
            return '';
        }
        $key = self::key($appSecret);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            throw new RuntimeException('Não foi possível proteger a senha do banco editorial.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $value, string $appSecret): string
    {
        if ($value === '') {
            return '';
        }
        if (!str_starts_with($value, self::PREFIX)) {
            return $value;
        }
        $decoded = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($decoded === false || strlen($decoded) < 28) {
            throw new RuntimeException('A senha protegida do banco editorial está inválida.');
        }
        $key = self::key($appSecret);
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Não foi possível descriptografar a senha do banco editorial. Verifique o APP_SECRET.');
        }
        return $plaintext;
    }

    private static function key(string $appSecret): string
    {
        if (strlen($appSecret) < 32) {
            throw new RuntimeException('APP_SECRET deve ter pelo menos 32 caracteres para proteger a senha do banco editorial.');
        }
        return hash('sha256', $appSecret, true);
    }
}
