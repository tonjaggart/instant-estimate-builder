<?php
/**
 * Authentication foundation only. This file is not an HTTP endpoint or sender.
 * The relay must look up each installation's random secret from protected storage.
 */
function ieb_auth_generate_key() { return bin2hex(random_bytes(32)); }

function ieb_auth_install_schema(PDO $db) {
    $db->exec('CREATE TABLE IF NOT EXISTS ieb_request_nonces (installation TEXT NOT NULL, nonce TEXT NOT NULL, expires_at INTEGER NOT NULL, PRIMARY KEY (installation, nonce))');
    $db->exec('CREATE INDEX IF NOT EXISTS ieb_request_nonce_expiry ON ieb_request_nonces(expires_at)');
}

function ieb_auth_sign($key, $id, $method, $path, $timestamp, $nonce, $body) {
    if (!is_string($key) || !preg_match('/\A[0-9a-f]{64}\z/D', $key) ||
        !is_string($id) || !preg_match('/\A[a-zA-Z0-9_-]{8,64}\z/D', $id) ||
        !is_string($method) || !preg_match('/\A[A-Z]{3,8}\z/D', $method) ||
        !is_string($path) || !preg_match('~\A/[a-z0-9/_-]{1,127}\z~D', $path) ||
        !is_string($timestamp) || !preg_match('/\A[1-9][0-9]{9}\z/D', $timestamp) ||
        !is_string($nonce) || !preg_match('/\A[0-9a-f]{32}\z/D', $nonce) ||
        !is_string($body) || strlen($body) > 65536) {
        throw new InvalidArgumentException('Invalid authentication input');
    }
    $message = "IEB1\n" . $id . "\n" . $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    return hash_hmac('sha256', $message, hex2bin($key));
}

/** Return false for bad auth/replay. DB failures propagate and MUST fail closed. */
function ieb_auth_verify(PDO $db, $key, $id, $method, $path, $timestamp, $nonce, $body, $signature, $now) {
    try {
        $expected = ieb_auth_sign($key, $id, $method, $path, $timestamp, $nonce, $body);
    } catch (InvalidArgumentException $e) {
        return false;
    }
    if (!is_int($now) || abs($now - (int) $timestamp) > 300 || !is_string($signature) ||
        !preg_match('/\A[0-9a-f]{64}\z/D', $signature) || !hash_equals($expected, $signature)) {
        return false;
    }
    $db->beginTransaction();
    try {
        $cleanup = $db->prepare('DELETE FROM ieb_request_nonces WHERE expires_at < :now');
        $cleanup->execute([':now' => $now]);
        $insert = $db->prepare('INSERT OR IGNORE INTO ieb_request_nonces (installation, nonce, expires_at) VALUES (:installation, :nonce, :expires_at)');
        $insert->execute([':installation' => $id, ':nonce' => $nonce, ':expires_at' => (int) $timestamp + 300]);
        $accepted = $insert->rowCount() === 1;
        $db->commit();
        return $accepted;
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }
}
