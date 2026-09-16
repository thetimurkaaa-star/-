<?php
// config.php
// Заполни токен бота и Telegram ID главного администратора.
const BOT_TOKEN = '8719618064:AAGA5jj57a_5CIN2vupsyYTtOhwMFe2n__M';
const MAIN_ADMIN_ID = 1282393103;

// SQLite-файл. На Railway укажи постоянный Volume и измени путь,
// например: /data/bot.sqlite
const DB_PATH = __DIR__ . '/bot.sqlite';

const CURRENCY = '💰';
const MAX_STRENGTH = 100;
const START_BALANCE = 10000;
const START_STRENGTH = 1;
const START_LEVEL = 1;
const START_XP = 0;

// Секрет для webhook можно оставить пустым, если используешь обычный polling.
const WEBHOOK_SECRET = '';
