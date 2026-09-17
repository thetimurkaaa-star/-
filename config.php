<?php
declare(strict_types=1);

define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '');
define('MAIN_ADMIN_ID', (int)(getenv('MAIN_ADMIN_ID') ?: 0));
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: '');
define('CRON_SECRET', getenv('CRON_SECRET') ?: '');
define('DATA_DIR', __DIR__ . '/data');

define('CURRENCY', '💰');
define('MAX_STRENGTH', 100);
define('START_BALANCE', 10000);
define('START_STRENGTH', 1);
define('START_LEVEL', 1);
define('START_XP', 0);
define('DAILY_BONUS', 5000);
define('WORK_COOLDOWN', 3600);
define('CASINO_MAX_BET', 1000000);
