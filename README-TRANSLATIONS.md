# Переводы плагина

## Компиляция переводов

WordPress требует `.mo` файлы для переводов. Для компиляции `.po` в `.mo`:

### Вариант 1: Использовать плагин Loco Translate (рекомендуется)

1. Установите плагин [Loco Translate](https://wordpress.org/plugins/loco-translate/)
2. Перейдите в Loco Translate → Plugins → Amazon Sync Pro for WooCommerce
3. Плагин автоматически скомпилирует переводы

### Вариант 2: Использовать msgfmt (если установлен gettext)

```bash
cd wp-content/plugins/wc-amazon-sync/languages
msgfmt wc-amazon-sync-ru_RU.po -o wc-amazon-sync-ru_RU.mo
```

### Вариант 3: Онлайн-сервисы

Используйте онлайн-сервисы для компиляции:
- https://po2mo.net/
- https://localise.biz/free/po2mo

После компиляции файл `wc-amazon-sync-ru_RU.mo` должен появиться в папке `languages/`.

## Seller ID

**Seller ID (Merchant Token)** — это идентификатор вашего аккаунта продавца в Amazon.

### Где найти Seller ID:

1. Войдите в **Amazon Seller Central**
2. Перейдите в **Settings** → **Account Info**
3. Найдите **Merchant Token** — это и есть Seller ID

**Важно:** Для некоторых операций SP-API Seller ID может быть необязательным. Плагин может попытаться получить его автоматически из ваших учетных данных при тестировании соединения.

Если у вас нет Seller ID, вы можете:
- Оставить поле пустым
- Нажать "Test Connection" — плагин попытается получить Seller ID автоматически
- Если это не сработает, найдите его в Seller Central вручную

### Ваши данные API:

У вас уже есть:
- ✅ **Client ID (LWA)**: `amzn1.application-oa2-client.2dffa6dd475e4ed2aecc6e6250c2a87f`
- ✅ **Client Secret (LWA)**: `amzn1.oa2-cs.v1.c23939e30baf437c412c751a8f44b1256...`
- ✅ **Refresh Token**: `Atzr|IwEBIHznW3hMbp9GO1h7kISQtpmRtbpFNZhWsFOM3v3KT6BBRK_L8kWQgm...`
- ✅ **Marketplace ID**: `A1PA6795UKMFR9` (Германия)

**Seller ID** нужно найти в Seller Central или попробовать получить автоматически через "Test Connection".

