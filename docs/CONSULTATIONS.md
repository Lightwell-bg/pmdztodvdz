# Консультации: архитектурный контракт (v0.2.0)

Документ для исполнителей и ревью. Если код расходится с ним, ошибка в коде. Он дополняет [ARCHITECTURE.md](ARCHITECTURE.md) и наследует все её правила:
- живой EDD;
- CSRF и guard;
- no-store;
- платное не попадает в браузер до оплаты;
- `?>` не пишется в `//`-комментариях.

Ядро уже написано, и его нужно переиспользовать, а не переписывать:
- `src/Schedule/{Grid,ScheduleConfig,SlotGenerator}.php` — сетка 5 минут, расписание, слоты, летнее время, срок письменного ответа;
- `src/Storage/SlotRepository.php` — атомарные единицы и места, повтор при deadlock;
- `src/Storage/CreditRepository.php` — атомарные состояния зачёта;
- `src/Booking/BookingService.php` — машина состояний записи, условия `terms`, перенос, отмена, события оплаты.

## 1. Таблицы (`Schema::DB_VERSION = '3'`, только добавление)

Существующие таблицы и данные не трогаются. `maybeUpgrade()` вызывает `dbDelta`. Имена через `Schema::table()`: `units`, `seats`, `bookings`, `credits`, `notifications`, `messages`.

```
purchases            + kind varchar(16) NOT NULL DEFAULT 'instruction'   ('instruction'|'consultation')
                     + booking_id bigint unsigned NOT NULL DEFAULT 0, KEY booking_id
booking_units        unit_utc bigint NOT NULL PRIMARY KEY, booking_id bigint unsigned NOT NULL, expires_at bigint NULL,
                     KEY booking_id, KEY expires_at
written_seats        local_date date NOT NULL, seat_no smallint unsigned NOT NULL, booking_id bigint unsigned NOT NULL,
                     expires_at bigint NULL, PRIMARY KEY (local_date, seat_no), KEY booking_id
bookings             id PK AI, public_id char(32) UNIQUE, snapshot_id, session_id, user_id (bigint unsigned, default 0),
                     format varchar(16), mode varchar(16), kind varchar(8) ('sync'|'written'),
                     status varchar(20), status_reason varchar(32) '',
                     start_utc bigint NULL, end_utc bigint NULL, block_end_utc bigint NULL,
                     written_date date NULL, deadline_utc bigint NULL, hold_until bigint NOT NULL,
                     contact varchar(190), email varchar(190), question text, visitor_tz varchar(64),
                     terms longtext (JSON, неизменяемые условия), meet_url varchar(255) '',
                     order_id bigint unsigned 0, order_item_id bigint unsigned 0, purchase_id bigint unsigned 0,
                     credit_purchase_id bigint unsigned 0, credit_amount decimal(18,9) 0, credit_conflict tinyint 0,
                     ics_uid varchar(64), ics_sequence int unsigned 0, reschedule_count int unsigned 0,
                     followups_used int unsigned 0, reminder_sent_at datetime NULL, paid_at datetime NULL,
                     admin_notes longtext NULL (только админ), created_at, updated_at,
                     KEY status, KEY start_utc, KEY snapshot_id, KEY session_id, KEY order_id
credits              id PK AI, instruction_purchase_id bigint unsigned UNIQUE, booking_id bigint unsigned,
                     amount decimal(18,9), currency varchar(8), tax_mode varchar(8) ('incl'|'excl'),
                     state varchar(10), hold_until bigint, updated_at datetime, KEY booking_id, KEY state
notifications        id PK AI, booking_id, type varchar(40), seq int unsigned 0, recipient varchar(190),
                     status varchar(10) ('queued'|'sent'|'failed'|'skipped'), attempts smallint, last_error varchar(255),
                     created_at, sent_at NULL, UNIQUE KEY once (booking_id, type, seq)
booking_messages     id PK AI, booking_id, direction varchar(8) ('out'|'note'), subject varchar(255), body longtext,
                     author_id, created_at, mail_status varchar(10)   — история письменных ответов
```

## 2. `BookingRepository` (CRUD; интерфейс, который ожидает `BookingService`)

- `create(array $data): ?array`:
  - генерирует `public_id` (`Tokens::publicId`) и `ics_uid` (random hex + `@` + хост сайта);
  - `terms` сериализует в JSON, `created_at`/`updated_at` — UTC;
  - возвращает строку через `get()`.
- `get(int)`, `byPublicId(string)`: `terms` декодируются в массив, числа приводятся к int.
- `transition(int $id, array $from, string $to, array $fields = []): bool` — один `UPDATE … SET status=%s, … WHERE id=%d AND status IN (…)`; затем перечитать и вернуть `true`, только если статус стал `$to` **этим** вызовом (сравнить старый статус из `$from`). В `$fields` разрешены колонки из белого списка; `terms` кодируется в JSON.
- `update(int $id, array $fields): void` — белый список колонок.
- `activeHold(int $sessionId, int $snapshotId, int $now): ?array` — статус `held` и `hold_until >= now` (самая свежая).
- `listForAdmin(filters, page)`, `forMonth(Y, m, tz)`, `bySnapshot`, `byOrder(int $orderId)`, `staleHeld(int $now, int $limit)`, `dueReminders(int $from, int $to)`.

## 3. Настройки консультаций

Ключ в существующем `bgdvzh_settings` — `consult` (массив), `Settings::consultation()` возвращает его с дефолтами:

```
enabled false, download_id 0, price_id null (базовый), format_price_ids {telegram:null,…},
consultant: {name:'', experience:'', photo_id:0},
tz 'Europe/Sofia', weekly {1..5:['10:00-13:00','14:00-18:00']}, exceptions [], vacations [],
duration_min 45, break_min 15, step_min 15, min_notice_hours 24, horizon_days 30,
written_per_day 2, written_business_days 2, written_scope 'Письменный ответ по анкете и дополнительным вопросам',
written_followups 1, written_followup_days 7,
formats: { telegram:{enabled:true, initiator_text:'Консультант напишет вам в Telegram в назначенное время'},
           whatsapp:{enabled:true, initiator_text:'Консультант напишет вам в WhatsApp в назначенное время'},
           phone:{enabled:true, initiator_text:'Консультант позвонит вам в назначенное время'},
           meet:{enabled:true, initiator_text:'Ссылку на Google Meet консультант пришлёт отдельным письмом до встречи'},
           email:{enabled:true, initiator_text:'Ответ придёт на ваш email не позднее указанного срока'} },
hold_minutes 15, reschedule_hours 24, reschedule_max 1, reminder_hours 24,
admin_email '' (пусто → get_option('admin_email')), analytics_events false
```

`ConsultGate::reason(): ?string` — консультация выключена, если выполнено любое условие:
- `enabled` выключен;
- товар не выбран или не опубликован: проверка `post_status === 'publish'` и `EDD_Download::can_purchase()` для анонимного контекста; черновик не годится;
- `price_id` невалиден для вариативного товара;
- `ScheduleConfig` невалиден;
- нет ни одного включённого формата.

Инструкция при этом продаётся. Та же проверка «опубликован и `can_purchase`» добавляется в `EddGate` для товара инструкции.

## 4. Цены и зачёт

- **Цена позиции** — только из EDD. Цена формата — `format_price_ids[format]`, если задан и валиден, иначе базовый `price_id`. Ввод из браузера — только `format`.
- **Зачёт:** `CreditCalculator::amount(instructionItem, refundItems, taxMode)` (чистая функция).
  - `incl` (`edd_prices_include_tax()`): `item.total − Σ|refund.total|`;
  - `excl`: `(item.subtotal − item.discount) − Σ(|refund.subtotal| − |refund.discount|)`;
  - не меньше 0; округление до 2 знаков.
- **Условия зачёта:**
  - есть покупка инструкции этого же снимка, живо `paid`, владелец подтверждён тем же контекстом, что и для `/result` (сессия или пользователь > 0, **не** grant);
  - валюта заказа инструкции === `edd_get_currency()`;
  - зачёт > 0 и < цены консультации.
- **Применение — только фильтр `edd_cart_item_price($price, $download_id, $options)`.** Для нашего товара консультации с `options.bgdvzh_ref` и `options.bgdvzh_booking` фильтр:
  1. находит бронь `held` этого снимка;
  2. проверяет строку `credits` в состоянии `reserved` для этой брони;
  3. возвращает `round(price − credit.amount, 2)`, но не меньше 0.01.

  Сумма берётся из БД, не из опций корзины. В остальных случаях цена не меняется. Кэш на запрос.
- **Название позиции** дополняется « (с зачётом инструкции X)» через фильтр названия позиции в корзине и квитанции. Конкретный фильтр выбрать по исходникам EDD 3.7: `edd_get_cart_item_name` или аналог.
- **Отрицательные комиссии EDD не используются.**
- **До оплаты** карточка показывает: базовая цена, зачёт, цена позиции. Если `!edd_prices_include_tax() && edd_use_taxes()` — пометка «плюс налог, если применяется; итог рассчитает оформление заказа». После `edd_add_to_cart` сервер сверяет `edd_get_cart_item_price()` с показанной ценой; при расхождении — ошибка `price_changed` и обновлённые данные.

## 5. Корзина, заказ, события

- **`CartService::replaceWithConsultation(snapshot, booking)`:**
  - удаляет из корзины все позиции нашей консультации **и инструкции этого снимка**, потому что консультация включает инструкцию;
  - добавляет консультацию с `price_id` формата и `options` = `bgdvzh_ref` (как у инструкции), `bgdvzh_booking` = public_id брони, qty 1;
  - обычные товары корзины не трогает.
- **`CheckoutGuard`**, для консультации:
  - валидный ref и снимок, бронь `held` не истекла **или** по ней уже есть живой заказ;
  - бронь принадлежит снимку;
  - `price_id` = ожидаемому для формата, qty 1, одна позиция;
  - зачёт: если `credit_purchase_id` > 0 — строка `reserved` этой брони, иначе отказ `credit_changed`.

  Ещё:
  - консультация и инструкция одного снимка вместе — отказ;
  - консультация без контекста плагина — отказ.
- **`OrderBinder` (`edd_built_order`):**
  - для позиции консультации — upsert `purchases` с `kind='consultation'`, `booking_id`;
  - в брони обновить `order_id`, `order_item_id`, `purchase_id`;
  - идемпотентно, переиспользовать существующую логику повторного срабатывания.
- **`OrderEvents`** (те же хуки: transition, item transition, refund, complete, dispute):
  - пересчитать по `AccessPolicy::paymentState` каждую покупку заказа;
  - для консультации:
    - paid → `BookingService::onPaid`;
    - refunded (полный по позиции) → `onFullRefund(booking, instructionStillValid)`, где `instructionStillValid` — живое состояние покупки `credit_purchase_id`;
    - disputed → `onDispute`;
    - частичный → только событие;
  - для инструкции: полный возврат → `CreditRepository::voidIfFree`.

  Повторы безопасны: всё условными переходами плюс уникальный журнал писем.
- **При каждом `GET /consult/booking`** — тот же пересчёт по живому EDD. Подтверждение записи не зависит от cron и after_order_actions.
- **Истечение резерва:** при запросах `/consult/*` и в cron вызывается `BookingService::expireIfStale(booking, orderAlive)`; `orderAlive` — у брони есть `order_id` и живой статус заказа ∈ {pending, processing, complete, partially_refunded}. Перед `credits->reserve` для другой брони — `releaseCreditIfStale` предыдущего держателя.
- **Доступ к инструкции.** `AccessPolicy` / `ResultAccess`: любая покупка снимка (`instruction` или `consultation`) в состоянии paid даёт инструкцию и PDF. Используется существующий `DocumentService` с `purchase_id` этой покупки. Отдельная покупка инструкции не зависит от судьбы консультации.

## 6. REST `bgdvzh/v1/consult/*`

Тот же guard, CSRF сессии со scope, no-store и лимиты частоты.

| Метод | Кто | Что |
|---|---|---|
| `GET /consult/options?id=<snapshot>` | владелец снимка | `{enabled, reason_public?, formats:[{key, label, sync, modes, initiator_text, duration_min | written:{scope, followups, followup_days, business_days}}], consultant:{name, experience, photo_url}, price:{base, credit, item, currency, tax_note, display}, recommended:bool, already_paid_instruction:bool}` |
| `GET /consult/availability?format=&from=&days=` | любой | только свободные начала `[{start_utc, date_local}]` в поясе консультанта или даты `[{date, deadline_utc}]` с учётом мест; без чужих данных; лимит частоты по IP; если пусто — `next_dates` |
| `POST /consult/hold` | владелец снимка + CSRF(scope=snapshot) | `{id, format, mode, start_utc | date, contact, email, question, visitor_tz}`. Сервер валидирует формат, контакт по формату (telegram: `@username` 5–32 `[A-Za-z0-9_]` или телефон; whatsapp/phone: E.164 `+` и 8–15 цифр; meet/email: `is_email`), длину вопроса ≤ 2000, `visitor_tz` ∈ `timezone_identifiers_list()`. Лимиты: 5 резервов в час на сессию, 20 в час на IP, одна активная бронь на сессию и снимок. Затем `BookingService::hold` → `{booking}` (без служебных полей) |
| `POST /consult/checkout` | владелец брони + CSRF(scope=booking) | бронь `held` и не истекла → `CartService::replaceWithConsultation` → сверка цены → `{redirect}`. Резерв **не** продлевается |
| `GET /consult/booking?b=<public_id>` | владелец (сессия, пользователь > 0, grant `kind='booking'` этой брони) | синхронизация с EDD, затем `{status, format, mode, times:{start_utc, end_utc, deadline_utc}, consultant_tz, visitor_tz, duration_min, written, contact, email, question, initiator_text, meet_url (только если задан), reschedule:{allowed, until_utc, left}, instruction:{available, result_id}, ics_url?}`. Без `admin_notes`, без истории служебных писем |
| `POST /consult/reschedule` | владелец + CSRF(scope=booking) или grant + одноразовый nonce формы | `{b, start_utc | date}` → `BookingService::reschedule(…, 'guest')` |
| `GET /consult/ics?b=` | владелец | `text/calendar`, `attachment`, no-store; только для синхронных форматов в статусах confirmed/cancelled |

`/state` и `/result` получают поле `consultations` — брони снимка без контактов чужих записей.

Grant `kind='booking'` выпускается в письме-подтверждении: ссылка `<page>#dvzh-b=<token>`. Обмен идёт как у `#dvzh-k`, через `POST /grant` с полем `kind`. Grant открывает только `/consult/booking`, `/consult/reschedule`, `/consult/ics` этой брони и инструкцию её снимка.

## 7. Письма, `.ics`, напоминания (`Notifier`)

- **`queue(bookingId, type, seq)`** — `INSERT` в `notifications`; дубль по `UNIQUE` — тихо ничего. Отправка сразу после `queue` в том же запросе (`wp_mail`) плюс повтор из cron (до 5 попыток) и кнопкой в админке. Ошибка → `failed` + уведомление админу (тип `admin_mail_failed`, тоже уникально).
- **Типы:**
  - для посетителя: `customer_confirmation`, `customer_updated`, `customer_cancelled`, `customer_meet_link`, `customer_reminder`, `customer_written_answer`;
  - для админа: `admin_new_paid`, `admin_time_conflict`, `admin_credit_conflict`, `admin_refunded`, `admin_disputed`, `admin_rescheduled`, `admin_mail_failed`.
- **Содержание писем посетителю** берётся только из `terms` и записи:
  - формат и режим, кто инициирует связь;
  - дата и время в поясе посетителя и в Болгарии с аббревиатурой (EEST/EET) — либо крайний срок письменного ответа с часовым поясом;
  - длительность или состав, контакт, вопрос;
  - ссылка на инструкцию: `#dvzh-k` — grant покупки консультации;
  - ссылка управления: `#dvzh-b`;
  - условия переноса и отмены;
  - для Meet до ввода ссылки — «ссылка будет отправлена отдельно».

  Текст plain, поля без HTML, заголовки без переводов строк.
- **Напоминание:** cron `bgdvzh_cron` раз в 5 минут (новое расписание `bgdvzh_5min`) находит confirmed sync с `start_utc ∈ (now, now + reminder_hours]` и `reminder_sent_at IS NULL`, ставит в очередь `customer_reminder` (seq = `ics_sequence`). Если `start_utc <= now`, напоминание не отправляется.
- **`.ics`:** `METHOD:PUBLISH` (отмена — `METHOD:CANCEL` + `STATUS:CANCELLED`), `UID` = `ics_uid`, `SEQUENCE` = `ics_sequence`, `DTSTART`/`DTEND` в UTC (`Z`), `DTSTAMP`, `SUMMARY:Консультация ПМЖ → ДВЖ (<формат>)`. Без контакта, вопроса и email. Экранирование RFC 5545 (`\\`, `;`, `,`, `\n`), складывание строк по 75 октетов, CRLF.
- **Meet:** админ вводит ссылку, валидация `^https://meet\.google\.com/[a-z]{3}-[a-z]{4}-[a-z]{3}$`, затем `customer_meet_link` (seq++).

## 8. Админка

**«Консультации».** Права `manage_options`, nonce на каждом действии.

- **Список:** фильтры — статус, формат, период, «конфликты». Колонки: дата и время / срок, формат, статус, заказ (ссылка в EDD), сумма, зачёт, флаги (конфликт времени или зачёта, ошибка письма).
- **Календарь** — месяц, записи по дням.
- **Карточка записи:** анкета (ссылка на просмотр), покупка, `terms`, контакт, вопрос, история писем и сообщений, заметки (только админ).
- **Действия:**
  - перенести — выбор из свободных слотов, `BookingService::reschedule(…, 'admin')`;
  - отменить — чекбокс подтверждения, без возврата денег;
  - добавить Meet-ссылку;
  - отметить выполненной;
  - отправить обновлённое подтверждение;
  - повторить неудавшееся письмо;
  - написать письменный ответ → `booking_messages` + `customer_written_answer`, seq = число ответов; `followups_used` учитывается: первый ответ, затем не больше `written.followups` уточнений до `deadline + followup_days`;
  - «конфликт зачёта разрешён вручную».

**«Настройки консультаций».**
- Все ключи §3: расписание — по дням недели и строкам интервалов, исключения и отпуска — построчно.
- Фото — `wp.media`.
- Ошибки `ScheduleConfig` и `ConsultGate` выводятся вверху.

**Шаблоны.** Тексты карточек — в версионируемых шаблонах, новый ключ `offers` в `DefaultTemplates`:
- `instruction_title`, `instruction_text`, `instruction_button`;
- `consult_title`, `consult_text`, `consult_button`;
- `upsell_after_instruction`;
- `consult_obstacle_note`: «Обсудим причины и план подготовки. Консультация не гарантирует готовность к подаче или положительное решение»;
- `recommended_label`.

## 9. Фронтенд

- **После результата — две карточки.** Консультация помечается «Рекомендуем при вашей ситуации», если `recommended`; при obstacle показывается `consult_obstacle_note`.
- **Карточка консультации:**
  - выбор формата (радио), для Telegram и WhatsApp — режим «переписка / звонок»;
  - выбор даты и времени: слоты в поясе посетителя, переключатель пояса (select из `Intl.supportedValuesOf('timeZone')`), рядом время в Болгарии с аббревиатурой; для email — выбор даты и крайний срок ответа;
  - контакт по формату, email заказа, «Главный вопрос / что хотите уточнить»;
  - цена: база, зачёт, итог, налоговая пометка;
  - «Выбрать способ и время» → «Оплатить» → `/consult/hold` → `/consult/checkout`.
  - Нет мест — следующие даты; оплату без резерва не запускать. Таймер резерва только информирует и ничего не продлевает.
- **После оплаты** — экран записи по `/consult/booking`:
  - статусы «Ожидаем подтверждения оплаты» / «Запись подтверждена» / «Оплачено — требуется выбрать новое время» / «Отменена» / «Возврат»;
  - перенос (если разрешён), скачать `.ics`;
  - кнопка инструкции и PDF.
- **После покупки инструкции** — upsell-карточка с зачётом.
- **Аналитика:** только если `analytics_events` и `window.dataLayer` существует — события `bgdvzh_result_shown`, `bgdvzh_option_selected {option: instruction|consultation}`, `bgdvzh_checkout_opened {option}`, `bgdvzh_purchase_confirmed {option}`. Без контактов, ответов и id.

## Реализация консультаций (бэкенд)

Версия 0.2.0, схема БД `3`. Остальные части (письма, `.ics`, админка, фронтенд) пишутся отдельно; здесь — что сделано и чем отличается от §1–§6.

### Что где лежит
- `Storage\Schema` — шесть новых таблиц и колонки `purchases.kind`, `purchases.booking_id`. `Schema::ready()` — схема актуальна; `maybeUpgrade()` вызывается при первом запросе после замены ZIP (`Plugin::wire`, с блокировкой `bgdvzh_upgrading`), в админке и в каждом проходе cron. Пока схема старая, `ConsultGate` выключает консультации, маршруты `/consult/*` отвечают 503, `OrderEvents` ничего не делает с записями.
- `Storage\BookingRepository` — CRUD и выборки (§2), плюс `byPurchase`, `awaitingPayment`.
- `Support\ConsultSettings` — дефолты, очистка, разбор текстовых полей. `Support\Settings::consultation()` и `consultationErrors()`. Логические значения меняются, только если ключ передан: форма админки шлёт скрытое `0` рядом с чекбоксом. Минуты и границы интервалов не округляются молча: ошибка кратности 5 минутам берётся из `ScheduleConfig::errors()`.
- `Commerce\ConsultGate` — `reason()`, цены формата из EDD (`priceIdFor`, `priceFor`), `taxMode`, `display`.
- `Commerce\EddGate::purchasable()` — товар опубликован и `EDD_Download::can_purchase()`. Статус проверяется явно: сам `can_purchase()` пропускает черновик для пользователя с правом редактирования. Проверка добавлена и в `EddGate::reason()` для инструкции.
- `Commerce\CreditCalculator` (чистый), `Booking\CreditService`, `Booking\BookingSync` (общая синхронизация записи с живым EDD), `Booking\BookingCron`, `Booking\Notifier`, `Booking\ContactValidator`.
- `Commerce\ConsultPriceFilter` — фильтры цены и названия позиции.
- `Http\ConsultController` — маршруты §6.

### Точные хуки EDD (3.2–3.7.1.1, проверено по исходникам)
- `edd_cart_item_price` (`$price, $download_id, $options`, `EDD_Cart::get_item_price`). Результат попадает в `cart_details[i]['item_price']`, а `edd_build_order()` кладёт его в `amount` позиции заказа. В заказе хранится уже уменьшенная сумма; отрицательные комиссии нигде не используются. Цена пересчитывается только для позиции консультации с подписанным `bgdvzh_ref` и `bgdvzh_booking` у записи `held`, строка `credits` должна быть `reserved` за этой записью. Если цена не определена (`false`) или не больше нуля, фильтр её не трогает.
- `edd_get_cart_item_name` (`$title, $download_id, $item`, `EDD_Cart::get_item_name`) — дописывает « (с зачётом инструкции X)» в корзине оформления и платёжных шлюзах. Название строки заказа EDD берёт из `get_the_title()` + названия варианта и не меняется.
- `edd_checkout_error_checks`, `edd_built_order`, `edd_transition_order_status`, `edd_transition_order_item_status`, `edd_refund_order`, `edd_complete_purchase`, `edd_stripe_dispute_created` — как раньше; для консультации используются те же обработчики.
- Не используются: фильтры налогов, `edd_get_cart_content_details`, комиссии (`edd_add_fee`).
- Ограничение: при `edd_get_cart_item_price($id, $options, true)` (цена без налога в режиме «цены включают налог») фильтр получает уже очищенную от налога цену, и зачёт с налогом вычитается неточно. EDD так вызывает цену только для пересчёта налога позиции, итог в заказе строится по `get_item_price()` без этого флага.

### Отклонения и уточнения к §1–§6
1. `Settings` получил ключ `consult` в `defaults()`. `Settings::sanitize()` принимает `consult` как массив и пропускает его через `ConsultSettings::sanitize`.
2. **Cron.** Событие `bgdvzh_cron` переведено на расписание `bgdvzh_5min` (фильтр `cron_schedules`, `CronHandler::ensureScheduled()`). Консультации обрабатываются в каждом проходе, а прежняя часть (документы, сверка покупок, хранение) — не чаще раза в час (опция `bgdvzh_cron_hourly_at`). Действие `bgdvzh_consult_tick($now)` вызывается в конце прохода для агента писем и напоминаний.
3. **`orderAlive`.** Статусы заказа `pending`, `processing`, `complete`, `partially_refunded`, `on_hold` дают «оплата идёт» без срока отсечения: брошенный pending помечает сам EDD, и только после этого зачёт и время освобождаются. Если EDD недоступен — «жив» (ничего не освобождаем).
4. **Зачёт и собственный резерв.** `CreditService::eligibility()` считает свободным зачёт, зарезервированный за собственным активным резервом сессии (повторный клик), а `BookingService::hold()` затем сам заменяет старый резерв. Иначе повторный выбор времени показывал бы цену без зачёта.
5. **Цена формата** передаётся в `/consult/options` для каждого формата (`formats[i].price`); верхний `price` — для первого предлагаемого формата. Формат без цены в EDD не предлагается.
6. **Grant `booking`.** `GrantManager::issueBookingLink($consultationPurchase)` (срок не короче 60 дней, многоразовая) и `exchangeFull($token, 'booking')`. Обмен выдаёт обычную grant-cookie покупки консультации, поэтому она открывает: `/consult/booking`, `/reschedule`, `/ics` этой записи (по `purchase_id` записи) и инструкцию снимка (`/result`, скачивание). Ссылка `#dvzh-k` из квитанции EDD для покупки консультации даёт тот же доступ: это тот же покупатель по тому же email. Другие записи снимка по grant не открываются.
7. **Перенос по grant.** Для доступа по grant `POST /consult/reschedule` принимает `nonce` формы: `GET /consult/booking` возвращает `reschedule_nonce`, привязанный к `ics_sequence`, числу переносов и статусу записи, поэтому после любого переноса он перестаёт действовать. Владелец по сессии использует CSRF-заголовок.
8. **Связь записи и второго заказа.** Если у записи уже есть живой заказ, другой заказ с тем же `bgdvzh_booking` создаёт только покупку (событие `consult_second_order`) и не перепривязывает запись; оплаченный второй заказ забирает запись, если первый не оплачен; две оплаты — событие `consult_duplicate_payment` (разбор вручную).
9. `SuccessRedirect` уводит на страницу анкеты и заказ, состоящий только из консультации.
10. `Plugin::clientConfig()` получил `consult_enabled` и `analytics_events`.
11. `OrderReader` расширен методами `orderCurrency()`, `itemAmounts()`, `refundAmounts()`; `FakeOrderReader` в тестах обновлён.
12. Сверка цены перед оплатой выполняется дважды: в `POST /consult/checkout` (цена корзины против `terms.price.item`; при расхождении корзина очищается, ответ 409 `price_changed`) и в `CheckoutGuard` (то же при отправке формы оформления).

13. **Блокировка записи.** Колонки `bookings.lock_token`, `lock_until` (TTL 30 с). Каждый изменяющий метод `BookingService` (`onPaid`, `onFullRefund`, `onDispute`, `expireIfStale`, `releaseCreditIfStale`, `reschedule`, `cancel`, `complete`, замена резерва в `hold`) берёт `BookingRepository::acquireLock()` (до ~3 с ожидания), перечитывает запись заново и работает со свежей строкой, затем `releaseLock()`. Не удалось взять — «занято» (старый статус / `busy` / `false`): sync при следующем запросе и cron повторят. Уведомления ставятся только после успешного `transition()`.
14. **Зачёт и отменённая запись.** Живой заказ (в том числе у отменённой записи) держит зачёт. Оплата заказа отменённой записи: время не возвращается, зачёт помечается использованным (или `credit_conflict`), админу уходит `admin_paid_after_cancel`.
15. **Спор.** При входе в `disputed` статус до спора сохраняется в `pre_dispute_status`; если спор выигран (платёж снова `complete`), возвращается он: `confirmed` → `confirmed`, `needs_reschedule` → `needs_reschedule`, `held`/`expired` → обычное подтверждение (время/место и зачёт). Зачёт разблокируется всегда.
16. **Зачёт при оформлении.** `CheckoutGuard` пересчитывает зачёт по живому EDD (`CreditService::liveAmount`); при расхождении с зарезервированным или неоплаченной инструкции — ошибка `credit_changed`, резерв зачёта снимается.
17. **Письма и места.** `Mailer` перед отправкой сверяет статус записи с типом письма, иначе `skip:`. `SlotRepository::confirmSeat` при сбое UPDATE возвращает `false`; `claimSeat` считает «уже есть место» только непросроченную строку.

### REST: фактические формы ответов
Общее: ошибки `{code, message}`; CSRF в заголовке `X-Bgdvzh-Csrf`; действия `consult_hold` (scope = public_id анкеты), `consult_checkout` и `consult_reschedule` (scope = public_id записи).
- `GET /consult/options?id=` → `{enabled, formats:[{key,label,sync,modes:['text','call']|['call']|['written'],initiator_text,price,duration_min | written:{scope,followups,followup_days,business_days}}], consultant:{name,experience,photo_url}, price, recommended, already_paid_instruction, consultant_tz, hold_minutes, csrf:{hold}}`. `price` = `{base, credit, item, currency, tax_note, display, display_base, display_credit}` (строки, деньги с двумя знаками). Если выключено: `{enabled:false, reason_public, formats:[]}`.
- `GET /consult/availability?format=&from=Y-m-d&days=1..31` → `{format, kind, consultant_tz, from, days, slots:[{start_utc,date_local}] | dates:[{date,deadline_utc}], next_dates}`; `next_dates` заполнен только если окно пустое (для синхронных — список дат `Y-m-d`, для письменного — объекты как в `dates`).
- `POST /consult/hold` `{id, format, mode, start_utc | date, contact, email, question, visitor_tz}` → `{booking, csrf:{checkout,reschedule}}`. Ошибки: 422 `validation` с `errors:{email,contact,question,visitor_tz,mode,date,start_utc}`; 409 `time_unavailable|time_taken|date_unavailable|date_full|credit_busy|in_progress`; 429 `rate_limited`.
- `POST /consult/checkout` `{b}` → `{redirect}`; 409 `hold_expired|in_progress|price_changed` (при `price_changed` ещё `price` корзины и `booking`).
- `GET /consult/booking?b=` → `{booking, csrf?}`; `booking` = `{id, snapshot, status, format, mode, kind, times:{start_utc,end_utc,deadline_utc}, consultant_tz, visitor_tz, duration_min, written, contact, email, question, initiator_text, hold_until, price:{base,credit,item,currency,display}, meet_url?, reschedule:{allowed,until_utc,left}, instruction:{available,result_id}, ics_url?, reschedule_nonce}`. `csrf` только владельцу по сессии/пользователю.
- `POST /consult/reschedule` `{b, start_utc | date, nonce?}` → `{booking}`; 409 `reschedule_limit|reschedule_too_late|time_taken|date_full|not_reschedulable`.
- `GET /consult/ics?b=` — отдаёт `text/calendar`, если есть класс `Booking\IcsBuilder` с `static build(array $booking): string`; пока его нет — 501.
- `POST /grant` `{token, kind?}` (`kind` = `link` по умолчанию | `booking`) → `{id}` либо `{id, booking}`.
- `/state` и `/result`: поле `consultations` — список `{id,status,format,mode,start_utc,deadline_utc,date,hold_until}` записей снимка (без контактов, вопроса и email). Для снимка в `/state` добавлен `csrf.consult_hold`.

### Что нужно агентам писем, админки и фронтенда
- Письма: `Notifier::setSender(callable(array $notification, array $booking): true|string)` либо фильтр `bgdvzh_notification_sender`. До подключения отправителя письма лежат в `queued`; `Notifier::retryPending()` вызывается в каждом проходе cron. Ссылки: `GrantManager::issueBookingLink($purchase)` (покупка записи: `PurchaseRepository::byBooking($id)`) и `issueLink($purchase)` для `#dvzh-k`. Напоминания — на действии `bgdvzh_consult_tick`, выборка `BookingRepository::dueReminders()`.
- Админка: `BookingRepository::listForAdmin/forMonth/get/update/transition`, `BookingService::reschedule(…,'admin')/cancel/complete`, `Notifier::forBooking/retry`, `Settings::consultation()/consultationErrors()`, `ConsultGate::reason()`, `ConsultSettings::*FromText/*ToText` для текстовых полей расписания.
- Фронтенд: см. формы ответов выше. Время резерва только информирует (`hold_until`, `hold_minutes`).

## Проверка конкурентности на InnoDB

Атомарность занятия времени, мест письменных разборов и зачёта инструкции проверяется скриптом `bin/concurrency-check.php` на реальном InnoDB (два и более соединения, 8 параллельных процессов, инварианты проверяются с третьего соединения). Подробности и команды запуска, в том числе для staging: `docs/TESTING.md`, раздел «Проверка конкурентности на InnoDB». Запускать после любых правок `SlotRepository`, `CreditRepository` и SQL бронирования.

## Реализация (письма и админка)

Упрощённая версия по решению владельца: «это не CRM». Что есть и чего нет.

### Письма
- `Booking\Mailer` — отправитель для `Notifier::setSender` (подключается в `Plugin::wire`). Plain text, `wp_mail`. Текст строится только из строки записи и замороженных `terms`; настройки и `admin_notes` в письма не попадают. Тема и заголовки очищаются от CR/LF и управляющих символов, получатель — один корректный адрес (иначе ошибка `invalid recipient`, `wp_mail` не вызывается).
- Время — в поясе посетителя и в поясе консультанта (Болгария) с аббревиатурой `EEST/EET` (`DateTimeImmutable::format('T')`); письменный формат — выбранная дата и крайний срок с поясом, «одно уточнение в течение N дней».
- Ссылки: инструкция `<страница>#dvzh-k=<токен>` (`GrantManager::issueLink`), просмотр записи `#dvzh-b=<токен>` (`issueBookingLink`). Для Meet без ссылки — «Ссылка на Google Meet будет отправлена отдельно»; письмо `customer_meet_link` содержит URL.
- Посетитель сам не переносит и не отменяет: в каждом письме строка «Чтобы перенести или отменить запись, ответьте на это письмо или напишите на <email админа>». `POST /consult/reschedule` отвечает 403 `reschedule_disabled`, `reschedule.allowed` всегда `false`. Перенос делает админ.
- `.ics` прикладывается к `customer_confirmation`, `customer_confirmation_resend`, `customer_updated`, `customer_cancelled` (синхронные форматы, кроме `needs_reschedule`); временный файл создаётся через `wp_tempnam` и удаляется после `wp_mail`. SEQUENCE отмены не меньше номера письма (`seq`), поэтому календарь не игнорирует отмену.
- Результат отправителя: `true` / строка-ошибка (письмо `failed`, повтор из cron до 5 попыток, затем одно `admin_mail_failed`; сбой самого `admin_mail_failed` новых писем не порождает) / строка с префиксом `skip:` (письмо больше не актуально → `skipped`, без повторов: напоминание после начала встречи, Meet без ссылки, ответ без сообщения).
- Новый тип `customer_confirmation_resend` в `Notifier::CUSTOMER_TYPES` (запас под повторную отправку подтверждения; кнопки в упрощённой админке нет).

### `.ics`
`Booking\IcsBuilder::build(array $booking): string` — `METHOD:PUBLISH` либо `CANCEL` + `STATUS:CANCELLED` (статусы `cancelled`, `refunded`), `UID` = `ics_uid`, `SEQUENCE` = `ics_sequence`, `DTSTART/DTEND/DTSTAMP` в UTC, `SUMMARY` только с названием формата, без контакта, вопроса, email. Экранирование RFC 5545, складывание по 75 октетов без разрыва многобайтовых символов, CRLF. Для письменной записи — пустая строка.

### Напоминания
`Booking\Reminders` на действии `bgdvzh_consult_tick`: `confirmed` синхронные записи с началом в `(now, now + reminder_hours]` и `reminder_sent_at IS NULL` → `customer_reminder` (seq = `ics_sequence`) и `reminder_sent_at`. После начала не ставится. Повтор неудавшихся писем — прежний `Notifier::retryPending()` в `BookingCron`, не дублируется.

### Возврат инструкции, давшей зачёт
`Booking\CreditRefundHandler` (вызывается из `OrderEvents` для покупки инструкции при полном возврате): `released`/`reserved` → `void` (`CreditRepository::voidReserved`; `CheckoutGuard` требует `reserved`, поэтому отвечает `credit_changed`); `used`/`locked` → `bookings.credit_conflict = 1`, событие и письмо `admin_credit_conflict` с `seq = 1`. Оплаченная консультация не меняется. Идемпотентно.

### Админка
- **«Консультации»**: один список (`WP_List_Table`, новые сверху, без фильтров и календаря) и карточка записи: время или срок, формат, контакт, email, вопрос, статус, ссылка на анкету, заказ EDD, сумма и зачёт, поле Meet. Действия (POST, `manage_options`, nonce, версия состояния записи против устаревшей страницы): «Перенести» (выбор из свободных слотов или дат), «Отменить» (галочка; деньги автоматически не возвращаются — возврат в EDD отдельно), «Сохранить ссылку Meet» (строгая проверка `^https://meet\.google\.com/[a-z]{3}-[a-z]{4}-[a-z]{3}$`, письмо `customer_meet_link`, seq++), «Выполнено», для email — «Отправить письменный ответ» (`messages`, письмо `customer_written_answer`, seq = номер ответа; первый ответ, затем не больше `written_followups` уточнений до `deadline + followup_days`). Неудавшееся письмо показывается с кнопкой «Отправить повторно»; флаги конфликта времени и зачёта — уведомлением на карточке, конфликт зачёта снимает кнопка «Разобрано». Заметки админа — одно поле; в REST и письма не попадают.
- **«Настройки консультаций»**: включение; товар EDD и вариант цены (одна базовая цена для всех форматов, `format_price_ids` при сохранении обнуляются); консультант (имя, опыт, фото через `wp.media`, скрипт `src/Admin/assets/consult-settings.js` подключается только на этой странице); пояс (по умолчанию `Europe/Sofia`); часы работы — 7 строк «10:00-13:00, 14:00-18:00» (пусто = выходной); «Выходные даты» (по одной `YYYY-MM-DD` в строке); длительность и перерыв; письменных разборов в день; форматы (включён + текст «кто связывается»); email админа. Шаг начала = длительность + перерыв (сохраняется автоматически). Остальное — фиксированные значения по умолчанию, в интерфейсе не показывается: уведомление за 24 ч, горизонт 30 дней, резерв 15 минут, напоминание за 24 ч, письменный срок 2 рабочих дня, одно уточнение в течение 7 дней. Вверху — причина `ConsultGate::reason()` и ошибки `ScheduleConfig`; пока они есть, запись выключена, продажа инструкции работает.

### Шаблоны `offers`
Новый ключ `offers` в `DefaultTemplates` (9 текстов), редактируется на экране «Шаблоны» (версия на каждое сохранение; пустое поле оставляет прежний текст). Тексты отдаются в `GET /state` (верхний ключ `offers`) и в `GET /consult/options` (`offers`) через `TemplateStore::offers()`.
