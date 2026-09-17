# Работа Бандита 1.3 — JSON / Railway

Версия полностью без базы данных.

## Что убрано
- SQLite
- PDO
- schema.sql
- DB_PATH
- start.sh
- запуск через системный curl

## Хранение
Данные сохраняются в JSON-файлы в каталоге `data/`:
- users.json
- chats.json
- events.json
- transactions.json
- broadcasts.json
- admins.json
- settings.json

Каталог создаётся автоматически.

## Railway
В корне проекта обязательно должен находиться файл `Dockerfile`.
Railway автоматически использует Dockerfile, если он есть в корне исходников.

Переменные:

```text
BOT_TOKEN=токен_бота
MAIN_ADMIN_ID=числовой_Telegram_ID_админа
```

Не нужны:

```text
DB_PATH
DATABASE_URL
```

`WEBHOOK_SECRET` и `CRON_SECRET` можно не задавать.

## Важно
Если в Railway в Settings → Deploy → Start Command вручную прописан старый `/app/start.sh`, очисти это поле. Эта версия вообще не использует `start.sh`.

После загрузки в Build Logs должна появиться строка:

`Using detected Dockerfile!`

А в Deployment Logs PHP должен запускаться из образа `php:8.3-cli`.
