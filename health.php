<?php
declare(strict_types=1);

http_response_code(200);
header_remove('X-Powered-By');
header('Content-Type: text/plain; charset=utf-8');

echo "ok\n";
