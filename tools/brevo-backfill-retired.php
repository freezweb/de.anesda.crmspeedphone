<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

fwrite(STDERR, "Brevo-Backfill ist stillgelegt; neue Versandereignisse kommen aus der Anesda-Mailplattform.\n");
exit(2);
