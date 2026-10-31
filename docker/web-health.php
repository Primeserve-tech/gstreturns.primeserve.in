<?php
// Apache terminates internal HTTP behind the HTTPS proxy. Require a real healthy
// response instead of treating an HTTPS redirect as a successful probe.
$curl = curl_init('http://127.0.0.1/health');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['X-Forwarded-Proto: https'],
    CURLOPT_CONNECTTIMEOUT => 1,
    CURLOPT_TIMEOUT => 4,
    CURLOPT_FOLLOWLOCATION => false,
]);
$body = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);
$data = is_string($body) ? json_decode($body, true) : null;
exit($status === 200 && is_array($data) && ($data['status'] ?? null) === 'ok' ? 0 : 1);
