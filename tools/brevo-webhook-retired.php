<?php

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'error' => 'Der Brevo-Webhook wurde durch die eigene Anesda-Mailplattform ersetzt.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
