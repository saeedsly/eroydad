<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
echo "APP_KEY=".bin2hex(random_bytes(32)).PHP_EOL;
echo "EMPLOYEE_HMAC_KEY=".bin2hex(random_bytes(32)).PHP_EOL;
echo "TICKET_SIGNING_KEY=".bin2hex(random_bytes(32)).PHP_EOL;
