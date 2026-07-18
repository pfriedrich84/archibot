<?php

/**
 * One-request raw HTTP server for deterministic Guzzle transfer-decoding tests.
 *
 * Usage: php raw_http_server.php <gzip|chunked|chunked-under-limit> <ready-file>
 */
$scenario = $argv[1] ?? '';
$readyFile = $argv[2] ?? '';
$server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

if ($server === false || $readyFile === '') {
    fwrite(STDERR, "Could not start test server: {$errorNumber} {$errorMessage}\n");
    exit(1);
}

$address = stream_socket_get_name($server, false);
if (! is_string($address) || file_put_contents($readyFile, $address, LOCK_EX) === false) {
    fwrite(STDERR, "Could not publish test server address.\n");
    exit(1);
}

$connection = stream_socket_accept($server, 10);
if ($connection === false) {
    fwrite(STDERR, "Timed out waiting for test request.\n");
    exit(1);
}

stream_set_timeout($connection, 5);
$request = '';
while (! str_contains($request, "\r\n\r\n") && ! feof($connection)) {
    $part = fread($connection, 8192);
    if ($part === false) {
        break;
    }
    $request .= $part;
}

if ($scenario === 'gzip') {
    $decoded = str_repeat('decoded-content-', 100);
    $body = gzencode($decoded);
    $response = "HTTP/1.1 200 OK\r\n"
        ."Content-Type: application/json\r\n"
        ."Content-Encoding: gzip\r\n"
        .'Content-Length: '.strlen($body)."\r\n"
        ."Connection: close\r\n\r\n"
        .$body;
} elseif ($scenario === 'chunked' || $scenario === 'chunked-under-limit') {
    if ($scenario === 'chunked-under-limit') {
        $entity = json_encode(['content' => str_repeat('c', 1000)], JSON_THROW_ON_ERROR);
        $chunks = [substr($entity, 0, 500), substr($entity, 500)];
    } else {
        $chunks = [str_repeat('a', 700), str_repeat('b', 325)];
    }

    $body = '';
    foreach ($chunks as $chunk) {
        $body .= dechex(strlen($chunk))."\r\n{$chunk}\r\n";
    }
    $response = "HTTP/1.1 200 OK\r\n"
        ."Content-Type: application/json\r\n"
        ."Transfer-Encoding: chunked\r\n"
        ."Connection: close\r\n\r\n"
        .$body."0\r\n\r\n";
} else {
    $response = "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
}

$remaining = $response;
while ($remaining !== '') {
    $written = fwrite($connection, $remaining);
    if ($written === false || $written === 0) {
        break;
    }
    $remaining = substr($remaining, $written);
}

fclose($connection);
fclose($server);
exit(in_array($scenario, ['gzip', 'chunked', 'chunked-under-limit'], true) ? 0 : 2);
