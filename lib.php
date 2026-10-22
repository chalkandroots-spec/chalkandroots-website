<?php
// lib.php
//
// Shared helpers for submit.php and approve.php (the GoDaddy replacement for
// the old Netlify Forms + Netlify Functions setup). Not meant to be opened
// directly in a browser.

if (!defined('CR_APP')) {
    http_response_code(403);
    exit;
}

function cr_config() {
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/config.php';
        $config = is_file($file) ? require $file : array();
        if (!is_array($config)) {
            $config = array();
        }
    }
    return $config;
}

function cr_cfg($key, $default = '') {
    $config = cr_config();
    return (isset($config[$key]) && $config[$key] !== '') ? $config[$key] : $default;
}

function cr_esc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Private folder for the submission log, error log, pending drafts and rate
// limit counters. Blocked from the web by its own .htaccess.
function cr_data_dir($sub = '') {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $guard = $dir . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    if ($sub !== '') {
        $dir .= '/' . $sub;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
    }
    return $dir;
}

function cr_log($message) {
    @file_put_contents(
        cr_data_dir() . '/error.log',
        '[' . gmdate('c') . '] ' . $message . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// Replaces the Netlify Forms dashboard: every accepted submission is kept in
// data/submissions.jsonl (one JSON object per line).
function cr_save_submission($form, $data) {
    $line = json_encode(array(
        'time' => gmdate('c'),
        'form' => $form,
        'data' => $data,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents(cr_data_dir() . '/submissions.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
}

function cr_b64url_encode($raw) {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function cr_sign($value) {
    return cr_b64url_encode(hash_hmac('sha256', $value, (string)cr_cfg('APPROVAL_SECRET'), true));
}

// POSTs JSON and returns array(httpStatus, responseBody). Status 0 = network error.
function cr_post_json($url, $headers, $payload, $timeout = 30) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => array_merge(array('Content-Type: application/json'), $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, // Google Apps Script web apps answer with a redirect
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($body === false) {
        cr_log('curl error for ' . $url . ': ' . curl_error($ch));
        $body = '';
    }
    curl_close($ch);
    return array($status, $body);
}

// Sends one email through Resend. Returns true on success.
function cr_resend($from, $to, $subject, $html, $text = '', $replyTo = '') {
    $key = cr_cfg('RESEND_API_KEY');
    if ($key === '') {
        cr_log('RESEND_API_KEY is not set - email "' . $subject . '" not sent');
        return false;
    }
    $payload = array(
        'from' => $from,
        'to' => array($to),
        'subject' => $subject,
    );
    if ($html !== '') {
        $payload['html'] = $html;
    }
    if ($text !== '') {
        $payload['text'] = $text;
    }
    if ($replyTo !== '') {
        $payload['reply_to'] = $replyTo;
    }
    list($status, $body) = cr_post_json(
        'https://api.resend.com/emails',
        array('Authorization: Bearer ' . $key),
        $payload
    );
    if ($status < 200 || $status >= 300) {
        cr_log('Resend error ' . $status . ' for "' . $subject . '": ' . $body);
        return false;
    }
    return true;
}
