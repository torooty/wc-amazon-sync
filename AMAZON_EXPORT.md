# Экспорт товара на Amazon (Listings Items API)

## Какой API использовать

Для **создания и полного обновления** листинга используется **Listings Items API** и операция **putListingsItem** (HTTP PUT).

- **Эндпоинт**: `PUT /listings/2021-08-01/items/{sellerId}/{sku}`
- **Query**: `marketplaceIds`, `issueLocale` (опционально), `requirements` (например `LISTING` или `LISTING_OFFER_ONLY`)
- **Тело**: JSON с полями `productType`, `requirements`, `attributes`

Текущий плагин уже использует тот же API для **частичного обновления** (PATCH) — например, обновление остатка через `patch_listings_item_quantity`. Для публикации нового товара или полной перезаписи листинга нужен именно **PUT putListingsItem**.

## Обязательные и опциональные поля

Зависит от **product type** и **marketplace**. Схема атрибутов берётся из **Product Type Definitions API** (`GET definitions/2020-09-01/productTypes/PRODUCT` с `requirements=LISTING` или `LISTING_OFFER_ONLY`).

### Универсальный продукт (productType: PRODUCT)

Для типа `PRODUCT` типично нужны:

| Атрибут | Обязательность | Описание | Откуда в WC |
|--------|----------------|-----------|-------------|
| `condition_type` | Обязательно | new_new, used_like_new и т.д. | Обычно `new_new` |
| `merchant_shipping_group` | Часто обязательно | Код шаблона доставки (из Product Type Definitions) | Настройки плагина / категория |
| `fulfillment_availability` | Для FBA/MFN | Каналы и количество | WC stock quantity |
| `list_price` | Для отображения цены | Цена с currency | WC price |
| `purchasable_offer` | Для покупки | offer_id, price, availability | Цена + наличие |
| `item_name` | Для полного листинга | Название товара | WC title |
| `brand` | Часто обязательно | Бренд | WC attribute / meta |
| `product_description` | Опционально | Описание | WC short/long description |
| `bullet_point` | Опционально | Пункты списка | Атрибуты / мета |
| `main_product_image_locator` | Опционально | Ссылка на изображение | WC gallery |
| `external_product_identifier` / `ean` | Для каталога | EAN/GTIN | WC SKU/EAN поле |

Для **только оффера** (цена + остаток без смены названия/категории) используют `requirements: LISTING_OFFER_ONLY` и передают в основном `list_price`, `fulfillment_availability`, `condition_type`, `merchant_shipping_group`.

## Связь WooCommerce ↔ листинг Amazon

- **Идентификатор листинга у продавца**: **Seller SKU** — уникальный в рамках sellerId + marketplace. В плагине логично брать **WooCommerce SKU** продукта; если SKU пустой — можно генерировать (например, `wc_{product_id}`) или требовать заполнения.
- **Идентификатор в каталоге Amazon**: **ASIN** (присваивает Amazon) или привязка по **EAN/GTIN** (если товар уже есть в каталоге). При создании нового листинга через putListingsItem с новым SKU Amazon создаёт новый листинг; при указании EAN может связать с существующим каталогом.
- **Рекомендация**: хранить в плагине связь `wc_product_id` ↔ `seller_sku` (и при появлении — `asin`) в таблице `amazon_imported_products` после успешного экспорта и при необходимости повторного импорта — обновлять эту же запись.

## Ограничения и рекомендации

1. **Категория (browse node)**  
   Обязательна для полного листинга. В плагине уже есть маппинг WooCommerce категория → Amazon category (browse node ID). При экспорте нужно подставлять `default_amazon_category_id` из маппинга для категории товара.

2. **Product Type**  
   Для EU часто используется тип `PRODUCT`; для части категорий — более специфичные типы (например, для книг — другой тип). В первой версии достаточно типа `PRODUCT`; при необходимости можно расширить выбор по маппингу категорий.

3. **Валидация**  
   Amazon возвращает ошибки валидации в теле ответа putListingsItem (в т.ч. по атрибутам). Перед отправкой полезно делать локальную проверку: SKU не пустой, цена > 0, наличие не отрицательное, категория и при необходимости EAN заполнены.

4. **Формат атрибутов**  
   Атрибуты в теле передаются в формате массива с привязкой к `marketplace_id`, например:
   ```json
   "attributes": {
     "condition_type": [{ "value": "new_new", "marketplace_id": "A1PA6795UKMFR" }],
     "list_price": [{ "value": { "value": 19.99, "currency": "EUR" }, "marketplace_id": "A1PA6795UKMFR" }],
     "fulfillment_availability": [{ "fulfillment_channel_code": "DEFAULT", "quantity": 10, "marketplace_id": "A1PA6795UKMFR" }]
   }
   ```
   Точный формат нужно сверять со схемой Product Type Definitions для выбранного productType и marketplace.

5. **Частота и лимиты**  
   У SP-API есть rate limits; при массовом экспорте лучше ставить задержки или очередь.

## Обязательные поля по категориям / типам продукта

Обязательные атрибуты **зависят от product type и marketplace**. Их нельзя зафиксировать раз и навсегда для «каждой категории» в виде статического списка: Amazon отдаёт актуальные требования через **Product Type Definitions API**.

В плагине реализовано:

1. **Получение схемы**  
   Вызов `GET definitions/2020-09-01/productTypes/{productType}` с параметрами `sellerId`, `marketplaceIds`, `requirements=LISTING`, `productTypeVersion=LATEST`, `locale`. По умолчанию используется тип **PRODUCT** (универсальный). Для части категорий в других маркетплейсах могут использоваться типы вроде LUGGAGE, FOOD и т.д.; при необходимости тип можно задавать по маппингу категории.

2. **Парсинг обязательных и опциональных атрибутов**  
   В возвращённой JSON-схеме ищется узел `properties.attributes`: массив **required** — список обязательных имён атрибутов, **properties** — описание каждого атрибута (в т.ч. **title** для подписи). По этой схеме формируются списки «обязательные» и «опциональные» с человекочитаемыми подписями.

3. **Отображение в предпросмотре**  
   В окне «Export to Amazon — Preview» выводятся:
   - данные, которые будут отправлены (SKU, название, цена, остаток, EAN, категория, шаблон доставки);
   - **Required by Amazon (product type …)** — таблица обязательных атрибутов с подписью, значением (из WC или «—») и статусом (✓ / —);
   - **Optional attributes** — таблица опциональных атрибутов в том же формате.

4. **Валидация**  
   Если для обязательного атрибута из схемы в WooCommerce нет значения (например, brand, item_name), в список ошибок добавляется сообщение вида «Required for Amazon (product type PRODUCT): "…" is missing.» Отправка блокируется до устранения ошибок.

5. **Маппинг атрибутов Amazon → WC**  
   В коде задаётся соответствие: item_name → название товара, brand → атрибут/мета brand, list_price → цена, fulfillment_availability → остаток, condition_type → new_new, merchant_shipping_group → настройка, external_product_identifier → EAN. Остальные атрибуты в превью показываются как «—», если для них нет маппинга; при необходимости маппинг можно расширить (в т.ч. по product type).

Итог: обязательные поля для публикации определяются **динамически по схеме Amazon** для выбранного типа продукта (по умолчанию PRODUCT), а не жёстко «по категории». Окно предпросмотра показывает и эти требования, и заполненность полей.

## Архитектура в плагине

1. **Окно предпросмотра (preview)**  
   - Пользователь на странице «Produkte» нажимает «Nach Amazon» у товара со статусом READY (ещё не на Amazon).
   - Открывается модальное окно с данными, которые будут отправлены: название, категория, цены, остатки, атрибуты, предупреждения/ошибки валидации.
   - Кнопки: «Отправить» (вызов API публикации), «Отмена».

2. **Сбор данных для preview и отправки**  
   - Сервис/класс (например, `AmazonExportPayloadBuilder`) по `wc_product_id` собирает: данные WC (title, SKU, EAN, price, stock, категория), маппинг категории в Amazon browse node, настройки (merchant_shipping_group, issue_locale). Формирует структуру атрибутов для putListingsItem и список предупреждений (нет EAN, нет категории и т.д.).

3. **Вызов API**  
   - В `ReportsClient` (или отдельном клиенте Listings) — метод `put_listings_item($seller_id, $sku, $marketplace_id, $body, $issue_locale, $requirements)`.
   - После успешного ответа: при необходимости обновить локальную запись (например, создать/обновить строку в `amazon_imported_products` с link_status и wc_product_id) или предложить пользователю запустить импорт для подтягивания ASIN/статуса.

4. **Шаги реализации**  
   - Добавить поддержку PUT в метод `request()` клиента.  
   - Реализовать `put_listings_item()` в ReportsClient.  
   - Реализовать `AmazonExportPayloadBuilder` (payload + валидация).  
   - AJAX: `amazon_export_preview` (возвращает payload + warnings), `amazon_export_submit` (вызывает putListingsItem).  
   - На странице Produkte: кнопка «Nach Amazon» для READY открывает модальное окно; в нём загрузка preview по AJAX, отображение данных и кнопок Отправить/Отмена.
