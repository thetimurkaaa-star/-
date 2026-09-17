# Работа Бандита 1.3 — JSON

Чистая сборка PHP + JSON для Railway. Нет Python, SQLite, PDO, `schema.sql` и `DB_PATH`.

## Railway Variables

```text
BOT_TOKEN=токен_бота
MAIN_ADMIN_ID=Telegram_ID_главного_админа
WEBHOOK_SECRET=необязательно
CRON_SECRET=необязательно
```

`DB_PATH` НЕ нужен.

## Хранилище

Все данные создаются автоматически в `/app/data/*.json`:

- users.json
- transactions.json
- jobs.json
- robberies.json
- inventory.json
- shops.json
- activity.json
- events.json
- event_participants.json
- vip_claims.json
- chats.json
- admins.json
- broadcast_log.json
- casino_games.json

## Важно

JSON-файлы находятся внутри контейнера. Без Railway Volume они могут быть потеряны при пересоздании контейнера/redeploy. Это неизбежное ограничение варианта без внешней БД/постоянного диска.

Для счёта активности в группах бот должен получать сообщения группы (при необходимости отключи Group Privacy через BotFather).

## Админ

`/safonof`

Рассылки:

`/broadcast_users текст`
`/broadcast_chats текст`
`/broadcast_all текст`

VIP:

`/vip ID ДНИ`
`/unvip ID`
`/respect ID +/-SUM`

События:

`/event_create Название | Описание | YYYY-MM-DD HH:MM | деньги | респекты | победители`
`/event_list`
`/event_end ID`

Администраторы:

`/admin_add ID`
`/admin_del ID`
