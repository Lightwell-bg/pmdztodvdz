# Интеграция с Easy Digital Downloads (EDD) 3.x: проверенные факты

## Проверено по версиям

Дата проверки: 2026-10-08. Источник: официальные ZIP с downloads.wordpress.org (только чтение исходников, код не запускался).

| Версия | Откуда | EDD_VERSION | Min PHP | Min WP |
|---|---|---|---|---|
| 3.7.1.1 (latest-stable) | `easy-digital-downloads.latest-stable.zip` | `define( 'EDD_VERSION', '3.7.1.1' )` — `includes/class-easy-digital-downloads.php:367` | 8.0 — `easy-digital-downloads.php:12`, `readme.txt:8` | 6.7 — `readme.txt:6` |
| 3.2.0 | `easy-digital-downloads.3.2.0.zip` | `includes/class-easy-digital-downloads.php:365` | 7.4 — `easy-digital-downloads.php:12`, `readme.txt:8` | 5.8 — `readme.txt:6` |

Условные обозначения: пути относительно корня плагина `easy-digital-downloads/`. Если не сказано иное, ссылки относятся к 3.7.1.1; отличия 3.2.0 помечены "3.2.0:". Всё, что не удалось подтвердить чтением кода, помечено "НЕ ПОДТВЕРЖДЕНО".

Важное: в 3.7.x Stripe встроен в ядро (`includes/gateways/stripe/` + `src/Gateways/Stripe/`), а оформление заказа вынесено в `src/Sessions/PurchaseData.php`. Поэтому часть мест в 3.2.0 другая (см. ниже).

---

## a. Order API

### Функции (все возвращают объекты `EDD\Orders\*`)

- `edd_get_order( $order_id = 0 )` — `includes/orders/functions/orders.php:431`. Возвращает `EDD\Orders\Order|false`.
- `edd_get_order_by( $field = '', $value = '' )` — `orders.php:464` (например `edd_get_order_by( 'payment_key', $key )`).
- `edd_get_orders( $args = array() )` — `orders.php:482`; по умолчанию `'number' => 30` (`orders.php:488`) — всегда задавайте `number` явно. Возвращает `Order[]`. Допустимые аргументы (док-блок `src/Database/Queries/Order.php:107-161`): `id`, `id__in`, `parent`, `parent__in`, `order_number`, `status`, `status__in`, `status__not_in`, `type`, `type__in`, `user_id`, `customer_id`, `email`, `gateway`, `mode`, `currency`, `payment_key`, `date_*_query`, `count`, `fields`, `number`, `offset`, `orderby`, `order`.
- `edd_get_order_item( $order_item_id = 0 )` — `includes/orders/functions/items.php:132`.
- `edd_get_order_items( $args = array() )` — `items.php:168`; дефолт `'number' => 30` (`items.php:174`). Аргументы (`src/Database/Queries/Order_Item.php:118-165`): `order_id`, `order_id__in`, `parent`, `product_id`, `price_id`, `cart_index`, `type`, `status`, `status__in`, `status__not_in`, `quantity`, `orderby`, `number` и т.д. `Order::get_items()` использует `'number' => 200` (`src/Orders/Order.php:359-370`).
- `edd_get_order_adjustments( $args = array() )` — `includes/orders/functions/adjustments.php:180`; дефолт `'number' => 30`.
- `edd_get_order_meta( $order_id, $key = '', $single = false )` — `includes/orders/functions/meta.php:63`; `edd_add_order_meta/edd_update_order_meta/edd_delete_order_meta` — `meta.php:29/84/47`.
- `edd_get_order_item_meta( $order_item_id, $key = '', $single = false )` — `meta.php:149`; `edd_add_order_item_meta` — `meta.php:115`; `edd_update_order_item_meta` — `meta.php:170`.
- `edd_get_order_id_from_transaction_id( $transaction_id )` — `includes/orders/functions/transactions.php:208`.
- `edd_update_order_status( $order_id, $new_status )` — `orders.php:640` (идёт через `EDD_Payment::save()`, обновляет статус ордера И всех его order items, см. раздел c).
- `edd_get_order_refunds( $order_id = 0 )` — `includes/orders/functions/refunds.php:370`: `Order[]` с `type='refund', parent=$order_id`.

### Поля `EDD\Orders\Order` (`src/Orders/Order.php`, protected-свойства, читаются через `__get`, строки в скобках)

`id` (60), `parent` (68), `order_number` (76), `status` (84), `type` (92; значения `sale`/`refund`, регистрация — `includes/orders/functions/types.php:78-110`), `date_created` (100), `date_modified` (108), `date_completed` (116), `date_refundable` (124), `date_actions_run` (132), `user_id` (140), `customer_id` (148), `email` (156), `ip` (164), `gateway` (172), `mode` (180), `currency` (188), `payment_key` (196), `tax_rate_id` (204), `tax_rate` (212), `subtotal` (220), `tax` (228), `discount` (236), `total` (244), `items` (252, lazy), `adjustments` (260), `address` (268). Методы: `get_items()` (359), `get_adjustments()` (419), `get_transaction_id()` (527), `is_complete()` (682 — НО использует `edd_get_complete_order_statuses()`, куда входят `refunded`/`revoked`, поэтому для "оплачено и актуально" не годится), `get_receipt_hash()` (794), `is_receipt_hash_valid()` (810).

### Поля `EDD\Orders\Order_Item` (`src/Orders/Order_Item.php`)

`id` (54), `parent` (63; у проданной позиции 0, у позиции возврата = id исходной позиции), `order_id` (71), `product_id` (79), `product_name` (87; включает " — Название цены" для вариативных), `price_id` (95, `int|null`), `cart_index` (103; = ключ в `$order_data['cart_details']`, см. `orders.php:1005`), `type` (111; `download`), `status` (116), `quantity` (124; у позиции возврата отрицательное), `amount` (132; цена за единицу), `subtotal` (140), `tax` (148), `discount` (156), `total` (164), `date_created` (172), `date_modified` (180), `adjustments` (188). Методы: `get_refunded_items()` (256: `edd_get_order_items( array( 'parent' => $this->id ) )`), `is_deliverable()` (272), `get_refundable_amounts()` (`includes/traits/trait-refundable-item.php:43`).

### Статусы

- `edd_get_payment_statuses()` — `includes/payments/functions.php:538`: `pending`, `processing`, `complete`, `refunded`, `partially_refunded`, `revoked`, `failed`, `abandoned`, `on_hold` (фильтр `edd_payment_statuses`). Функции `edd_get_order_status_labels` в коде НЕТ (grep пуст) — метки берутся из `edd_get_payment_statuses()`.
- Дополнительно встречаются вне этого списка: `trash` (`orders.php:87,111,161`), `preapproval` (Stripe, `src/Gateways/Stripe/Checkout/Complete.php:206`), устаревшие `publish`/`completed` (нормализуются в `complete`, `orders.php:655`), `cancelled` (в `edd_get_incomplete_order_statuses`).
- `edd_get_complete_order_statuses()` — `includes/orders/functions/statuses.php:100`: `publish, complete, completed, partially_refunded, revoked, refunded` (фильтр `edd_complete_order_statuses`). Это "финальные", НЕ "оплаченные и действующие".
- `edd_get_gross_order_statuses()` (19), `edd_get_net_order_statuses()` (49: `complete, partially_refunded, revoked, on_hold`), `edd_recoverable_order_statuses()` (78: `pending, abandoned, failed`), `edd_get_incomplete_order_statuses()` (122), `edd_get_refundable_order_statuses()` (144: `complete, publish, partially_refunded, on_hold`).
- Статусы позиций (order item): отдельного списка нет. Источники значений: при создании — `$order_data['status']` или `pending` (`orders.php:1008`); при смене статуса заказа через `EDD_Payment::save()` ВСЕ позиции получают тот же статус, кроме `refunded` (`includes/payments/class-edd-payment.php:1892-1898`); при возврате — `refunded` или `partially_refunded` (Validator ниже). Практически: `pending, complete, on_hold, revoked, failed, partially_refunded, refunded` (+ `trash`).
- `edd_get_deliverable_order_item_statuses()` — `statuses.php:163`: `complete`, `partially_refunded` (фильтр `edd_deliverable_order_item_statuses`). `Order_Item::is_deliverable()` дополнительно требует `quantity > 0`.
- 3.2.0: все перечисленные функции и значения идентичны (`statuses.php:100,144,163`).

---

## b. Возвраты

### Хранение

- Возврат — отдельная запись в `edd_orders`: `type='refund'`, `parent=<id исходного заказа>`, `status='complete'`, суммы отрицательные (`edd_negate_amount`) — `refunds.php:238-255`. Создаётся `edd_add_order()` (`refunds.php:260`).
- Позиции возврата — записи в `edd_order_items` с `order_id=<id возврата>`, `parent=<id исходной позиции>`, отрицательные `quantity/subtotal/tax/total`, `status='complete'` — `src/Orders/Refunds/Validator.php:500-530` (`set_common_item_args`: `parent = id`, отрицание, `status = 'complete'`); вставка — `refunds.php:272-275`.
- Статус исходной позиции обновляется сразу: `refunds.php:281-284`. Значение вычисляет `Validator::validate_item_and_add_totals` (`Validator.php:365-437`): `refunded`, если возвращаемая сумма `total` равна максимально доступной (остаток после прежних возвратов), иначе `partially_refunded` (сравнение суммы, не количества: `Validator.php:430-434`).
- Статус исходного заказа: `refunded`, если нет позиций со статусом не `refunded` и `edd_get_order_total() <= 0`, иначе `partially_refunded` — `refunds.php:325-341`.
- Несколько частичных возвратов одной позиции допустимы (комментарий `Validator.php:355`); каждый создаёт свою запись возврата.

### Функции

- `edd_refund_order( $order_id, $order_items = 'all', $adjustments = 'all' )` — `refunds.php:194`. `$order_items` — `'all'` или массив элементов `array( 'order_item_id' => int, 'quantity' => int, 'subtotal' => float, 'tax' => float )` (док-блок 173-181). Возвращает `int|WP_Error` (id возврата).
- Проверки: `edd_is_order_refundable( $order_id )` (`refunds.php:23`; статус из `edd_get_refundable_order_statuses()`, нет уже полного возврата, окно возврата, права), фильтр `edd_should_process_order_refund` (216).
- `edd_get_order_refunds( $order_id )` — `refunds.php:370`. `edd_get_order_total( $order_id )` (сумма заказа минус возвраты) — `refunds.php:388`.
- Остаток по исходной позиции: `$item->get_refundable_amounts()` — `trait-refundable-item.php:43-74`; возвращает `subtotal/tax/total/quantity` после вычитания возвратов (`total == 0` → вернули полностью).

### Хук возврата

`do_action( 'edd_refund_order', $order_id, $refund_id, $all_refunded )` — `refunds.php:355`. `$order_id` — исходный заказ, `$refund_id` — id новой записи-возврата, `$all_refunded` — bool, вернули ли заказ целиком. К моменту хука статусы исходных позиций и заказа уже обновлены (`refunds.php:281-284`, `341`). 3.2.0: тот же хук и аргументы (`refunds.php:395`).

Устаревшие `edd_pre_refund_payment`/`edd_post_refund_payment` (`class-edd-payment.php:2714,2725`) срабатывают ТОЛЬКО при переводе статуса в `refunded` через `EDD_Payment` (не создают запись возврата).

### Как посчитать, сколько возвращено по исходной позиции

1. `edd_get_order_items( array( 'parent' => $item_id, 'number' => 200 ) )` (= `Order_Item::get_refunded_items()`, но у метода дефолтный лимит 30) — суммировать `total`/`quantity` (отрицательные).
2. Или `get_refundable_amounts()['total'] <= 0` → позиция возвращена полностью.
3. Или `$item->status === 'refunded'` / `'partially_refunded'`.

### Важная оговорка: возврат инициирован в Stripe (webhook)

- 3.7.1.1: `src/Gateways/Stripe/Webhooks/Events/ChargeRefunded.php:38-82`. Полный возврат (`$charge->refunded`) → `get_refund_id()` (`Traits/Order.php:58-79`) вызывает `edd_refund_order( $order->id )` (с принудительным override) → создаётся запись возврата, отработает `edd_refund_order`. Если заказ не refundable — `edd_update_order_status( $id, 'refunded' )` (ChargeRefunded.php:66) без записи возврата и без смены статуса позиций (`class-edd-payment.php:1892`). Частичный возврат в Stripe → только `edd_update_order_status( $id, 'partially_refunded' )` (ChargeRefunded.php:71): записи возврата и статусы позиций НЕ создаются.
- 3.2.0: `includes/gateways/stripe/includes/webhooks.php:59-97` — любой возврат из Stripe только `edd_update_order( $id, array( 'status' => ... ) )`, без записи возврата и без смены статуса позиций.
- Вывод: надёжный сигнал — комбинация (1) `edd_refund_order`, (2) `edd_transition_order_status` в `refunded`/`partially_refunded`, (3) пересчёт по данным (статус позиции + суммы возвратов).

---

## c. Хуки смены статуса и создания заказа

### Механизм transition (генерируется базой данных)

`src/Database/Query.php:2306-2364` (`transition_item`): для колонок со схемным флагом `transition => true` при add/update вызывается `do_action( "edd_transition_{item_name}_{column}", $old_value, $new_value, $item_id )`. `$prefix = 'edd'` (`src/Database/Base.php:53`). Поддерживающие колонки: `status` заказа (`src/Database/Schemas/Orders.php:65-70`), `status` и `type` позиции (`src/Database/Schemas/Order_Items.php:108-123`). Итого:

- `edd_transition_order_status( $old_status, $new_status, $order_id )`. При создании `$old_status = 'new'` (`Query.php:2325-2332`, вызов из add — `Query.php:1941`). Только при реальном изменении значения (`Query.php:2339-2345`).
- `edd_transition_order_item_status( $old_status, $new_status, $order_item_id )` (то же правило, `'new'` при создании).
- `edd_transition_order_item_type( ... )` — аналогично.

Также: `edd_order_added( $order_id, $data )`, `edd_order_updated( $order_id, $data, $item_raw )`, `edd_order_item_added( $item_id, $data )`, `edd_order_item_updated( $item_id, $data, $item_raw )` — `Query.php:1951`, `Query.php:2045`.

### Обратная совместимость

- `edd_update_payment_status( $order_id, $new_status, $old_status )` — `includes/orders/functions/transitions.php:49-56`, подписан на `edd_transition_order_status`. ВНИМАНИЕ: если `$old_status === 'complete'`, хук срабатывает ДВАЖДЫ (вторым вызовом с `$old_status = 'publish'`, transitions.php:52-55). Дополнительно `EDD_Payment::save()` для статуса `refunded` вызывает его ещё раз (`class-edd-payment.php:1916`). Подписчики на `edd_update_payment_status` должны быть идемпотентны; предпочтительнее `edd_transition_order_status`.
- `edd_before_payment_status_change( $payment_id, $status, $old_status )` — `class-edd-payment.php:1862`. Фильтры блокировки: `edd_should_update_payment_status`, `edd_should_update_order_status` ($do_change, $order_id, $new_status, $old_status) — `orders.php:676-677`.

### Завершение покупки

`edd_complete_purchase()` (функция) — `includes/payments/actions.php:28`, подписана `add_action( 'edd_update_payment_status', 'edd_complete_purchase', 100, 3 )` (225). Логика: срабатывает, только если старый статус НЕ в `publish|complete|completed` и новый в этом списке (30-40), заказ `type='sale'` (44). Затем:

- `do_action( 'edd_pre_complete_purchase', $order_id )` — 56.
- Для каждой позиции и единицы количества, если `date_completed` пуст: `do_action( 'edd_complete_download_purchase', $product_id, $order_id, $download_type, $cart_details, $cart_index )` — 133. В `$cart_details['item_number']['options']` подмешаны `price_id`, `quantity` и все `_option_*` из meta позиции без префикса (97-114).
- Один раз за жизнь заказа (если `date_completed` пуст, 158): ставит `date_completed`/`date_refundable` (167) и вызывает `do_action( 'edd_complete_purchase', $order_id, $payment /* EDD_Payment */, $customer /* EDD_Customer */ )` — 185.
- `do_action( 'edd_after_payment_actions', $order_id, $payment, $customer )` (203) и `do_action( 'edd_after_order_actions', $order_id, $order /* EDD\Orders\Order */, $customer )` (215; по умолчанию вызывается позже из `src/Orders/DeferredActions.php:158` через cron — фильтр `edd_use_after_payment_actions`, строка 188).
- В конце `edd_empty_cart()` (223).
- Повторный перевод `on_hold` → `complete` НЕ вызовет `edd_complete_purchase` (внутренний блок требует пустой `date_completed`), но вызовет `edd_transition_order_status`.
- 3.2.0: те же `edd_complete_purchase` (`actions.php:185`) и `edd_after_order_actions` (`actions.php:215`).

### Создание заказа

`edd_build_order( $order_data )` — `orders.php:703`. Заказ создаётся со статусом `pending` (если в `$order_data['status']` не указано иное), позиции — с `cart_index = ключ cart_details` (1005). Хуки в конце:

- `do_action( 'edd_insert_payment', $order_id, $order_data )` — `orders.php:1274` (совместимость).
- `do_action( 'edd_built_order', $order_id, $order_data )` — `orders.php:1284`. К этому моменту: заказ и все позиции созданы, опции позиций записаны в meta (`1099-1106`), выполнен `$order->recalculate()` (1252). 3.2.0: `orders.php:1181` / `1191`.
- `edd_order_added` (id заказа) срабатывает ВНУТРИ `edd_add_order` — раньше, чем созданы позиции и meta. `edd_order_item_added` срабатывает ДО записи `_option_*` meta (meta пишется после возврата `edd_add_order_item`, `orders.php:1093-1106`) — для чтения опций непригоден.
- Восстановление заказа (resume, `orders.php:709-775`): если в сессии `edd_resume_payment` указывает на recoverable-заказ без transaction id, то существующий заказ ПЕРЕИСПОЛЬЗУЕТСЯ: все его позиции и adjustments удаляются и создаются заново (НОВЫЕ id позиций), и `edd_built_order` вызывается повторно для того же `$order_id`. Следствие: привязку нужно хранить по `order_id` (+ `cart_index`/`product_id`/`price_id`), а обработчик `edd_built_order` должен быть идемпотентным (перезаписывать, а не дублировать).

### Stripe (core, 3.7.1.1)

Нативного `payment_intent.succeeded` в обработчике НЕТ. Реализованные события: `src/Gateways/Stripe/Webhooks/Events/` — `ChargeSucceeded`, `ChargeRefunded`, `ChargeFailed`, `ChargeDisputeCreated`, `InvoiceCreated`, `RadarEarlyFraudWarningCreated`, `ReviewOpened`, `ReviewClosed`. Диспетчер `src/Gateways/Stripe/Webhooks/Listener.php:48-86` (на `init`; для каждого события дополнительно `do_action( 'edds_stripe_event_' . $type, $event )`, строка 86).

- Основное завершение заказа происходит СИНХРОННО в AJAX при подтверждении платежа: `src/Gateways/Stripe/Checkout/Complete.php:35-71` — создаёт заказ (`Order::create()` → `edd_build_order`, `Checkout/Order.php:52`), затем `Complete::mark_complete()` (123-137) → `edd_update_order_status( $id, 'complete' )` (или `preapproval` при предавторизации). После успеха: `do_action( 'edds_order_complete', $order, $intent_id )` (`Complete.php:236`, строка 228-236 для варианта из charge).
- `charge.succeeded` — `ChargeSucceeded.php:38-67`: пишет адрес/способ оплаты; если заказ `pending` и без `parent` — `Complete::mark_complete_from_charge()` (`Complete.php:205-237`, обновляет статус на `complete`) — страховка для медленных методов (банковские переводы).
- `charge.failed` — `ChargeFailed.php:38`: `edd_update_order_status( $id, 'failed' )`.
- `charge.refunded` — см. раздел b.
- `charge.dispute.created` — `ChargeDisputeCreated.php:37-43`: `edd_record_order_dispute( $order_id, $dispute_id, $reason )` (`includes/orders/functions/disputes.php:23-34`) → `edd_update_order_status( $id, 'on_hold' )` + order meta `dispute_id`, `hold_reason`; затем `do_action( 'edd_stripe_dispute_created', $dispute, $order_id )`. Чтение: `edd_get_order_dispute_id()` (disputes.php:43), `edd_get_order_hold_reason()` (54). `on_hold` НЕ входит в `edd_get_complete_order_statuses()` и в deliverable-статусы позиций, но входит в refundable.
- Статус `on_hold` ставится всем позициям заказа (`class-edd-payment.php:1892-1898`) → событие `edd_transition_order_item_status( 'complete', 'on_hold', $item_id )`.
- 3.2.0: webhooks в одном файле `includes/gateways/stripe/includes/webhooks.php` (`charge.succeeded` 35, `charge.refunded` 59, `charge.dispute.created` 188-200); `edd_record_order_dispute` вызывался и там.
- `payment_intent.succeeded`: НЕ ПОДТВЕРЖДЕНО (в ядре такого обработчика нет; для него остаётся только общий хук `edds_stripe_event_payment_intent.succeeded`, который срабатывает для любого события, получившего ответ — `Listener.php:86`, но только если событие включено в настройках вебхука на стороне Stripe).

---

## d. Корзина

- `edd_add_to_cart( $download_id, $options = array() )` — `includes/cart/functions.php:59` → `EDD()->cart->add()` (`includes/cart/class-edd-cart.php:~505-638`). Возвращает ключ позиции (`count($contents) - 1`, 637) или null, если не товар/нельзя купить (`can_purchase`).
- Структура позиции корзины: `array( 'id' => $download_id, 'options' => $options, 'quantity' => N, 'hash' => md5(...) )` (`class-edd-cart.php:578-583`, 623-624). Произвольные ключи в `$options` СОХРАНЯЮТСЯ в `options` (`class-edd-cart.php:578-583`), КРОМЕ случаев: (1) `quantity` извлекается из options в поле позиции (545-557); (2) если `price_id` передан массивом/строкой через запятую (несколько цен за раз), `options` пересоздаётся только из `price_id` (561-577) — кастомные ключи теряются; (3) для вариативных товаров без `price_id` подставляется цена по умолчанию (540-543).
- ВНИМАНИЕ (слияние): `edd_item_in_cart()` / `is_item_in_cart` (`class-edd-cart.php:876-898`) сравнивает только `id` и `price_id`, кастомные опции не учитываются. При включённых количествах (`edd_item_quantities_enabled()`, `cart/functions.php:133`) повторное добавление того же товара с теми же `price_id` лишь увеличивает quantity существующей строки (613-620), и опции второго вызова ПОТЕРЯНЫ. Если количества выключены — добавляется вторая строка (621-625).
- Фильтры/действия: `do_action( 'edd_pre_add_to_cart', $download_id, $options )` (520); `apply_filters( 'edd_pre_add_to_cart_contents', $contents, $download_id, $options )` (после 520); `apply_filters( 'edd_add_to_cart_item', $item )` — для каждой позиции перед записью (602; `$item = array( id, options, quantity )`; можно вернуть не-массив, чтобы пропустить); `do_action( 'edd_post_add_to_cart', $download_id, $options, $items )` (632).
- `edd_remove_from_cart( $cart_key )` — `cart/functions.php:97`; хуки `edd_pre_remove_from_cart( $key )` (651), `edd_post_remove_from_cart( $key, $item_id )` (663).
- `edd_get_cart_contents()` — `cart/functions.php:21` (массив позиций вида выше; `get_contents()` `class-edd-cart.php:250`, переиндексирует `array_values`, выбрасывает недоступные товары). `edd_get_cart_content_details()` — `cart/functions.php:33` → `get_contents_details()`; каждый элемент содержит `'item_number' => $item` (`class-edd-cart.php:394`, где `$item` — позиция корзины с `options`), `name`, `id`, `item_price`, `quantity`, `discount`, `subtotal`, `tax`, `fees`, `price`.
- `edd_empty_cart()` — `cart/functions.php:561` → `do_action( 'edd_empty_cart' )` (`class-edd-cart.php:732`).
- `edd_item_quantities_enabled()` — `cart/functions.php:133` (опция `item_quantities`, фильтр `edd_item_quantities_enabled`); `edd_get_cart_item_quantity( $download_id = 0, $options = array() )` — `cart/functions.php:160`.
- Add-to-cart по URL: `edd_process_add_to_cart` (`includes/cart/actions.php:59-99`): `edd_options[...]` из запроса → `$options` (61), `edd_download_quantity` → `options['quantity']`.

### Попадание опций корзины в заказ

- Путь: `EDD\Sessions\PurchaseData::set()` (`src/Sessions/PurchaseData.php:64-125`) формирует `$purchase_data` с `'downloads' => edd_get_cart_contents()` (79) и `'cart_details' => edd_get_cart_content_details()` (87), фильтр `edd_purchase_data_before_gateway( $purchase_data, $valid_data )` (105), кладёт в сессию `edd_set_purchase_session()` (120). Шлюз затем вызывает `edd_build_order( $purchase_data )` (для Stripe — `src/Gateways/Stripe/Checkout/Order.php:52`, данные берутся из сессии: `Order.php:203`).
- В `edd_build_order`: `price_id` берётся из `$item['item_number']['options']['price_id']` (`orders.php:971-973`); остальные ключи `options` (без `price_id` и `quantity`) записываются в meta позиции с ключом `'_option_' . sanitize_key( $option_key )` через `edd_add_order_item_meta( $order_item_id, $option_key, $value )` (`orders.php:1096-1106`). Чтение после создания: `edd_get_order_item_meta( $item->id, '_option_<ключ в нижнем регистре>', true )`. 3.2.0: то же (`orders.php:999`).
- Ограничения: ключи проходят `sanitize_key` (нижний регистр, только `a-z0-9_-`); значение записывается как есть (скаляр/массив → сериализуется WP).
- `Sessions\Handler::sanitize()` (`src/Sessions/Handler.php:397-412`) удаляет из сессии только `post_data` и `card_info`; опции корзины не затрагиваются.

### Лучшая точка для привязки "в момент создания заказа"

`add_action( 'edd_built_order', $cb, 10, 2 )` — `function ( int $order_id, array $order_data )` (`orders.php:1284`). Внутри:

- `$order_data['cart_details'][ $i ]['id']` — product id; `['item_number']['options']` — опции из корзины (включая `price_id`, кастомные ключи; в локальной копии `edd_build_order` ключи удаляются, но `$order_data` хука остаётся нетронутым: `unset` идёт по копии `$item` внутри `foreach`, `orders.php:1098-1099`); `['quantity']`, `['item_price']`, `['subtotal']`, `['tax']`, `['price']`.
- Соответствие позиции в БД: `edd_get_order_items( array( 'order_id' => $order_id, 'number' => 200 ) )`, сопоставление по `cart_index === $i` (ключ `cart_details`). НЕ ПОДТВЕРЖДЕНО на практике, что ключи `cart_details` всегда последовательны без пропусков (сам `cart_index` = ключ массива, `orders.php:1005`); надёжнее дополнительно сверять `product_id` и `price_id`.
- Запись своих данных: `edd_add_order_item_meta()`/`edd_update_order_meta()` (хук идемпотентен — при resume заказа вызывается повторно, позиции пересоздаются).
- Дополнительно: `$order_data['user_info']`, `['user_email']`, `['purchase_key']`, `['gateway']`, `['price']`.

---

## e. Валидация при оформлении

- `do_action( 'edd_checkout_error_checks', $valid_data, $_POST )` — `src/Sessions/PurchaseData.php:33` (в 3.2.0: `includes/process-purchase.php:37`; Stripe compat — `includes/gateways/stripe/includes/compat.php:239`). Аргументы: `$valid_data` (массив: `gateway`, `discount`, `cc_info`, ...), `$_POST` (второй параметр, `add_action(..., 10, 2)`).
- Второй хук: `do_action( 'edd_checkout_user_error_checks', $user, $valid_data, $_POST )` — `PurchaseData.php:41`.
- Ошибка: `edd_set_error( $error_id, $error_message )` — `includes/error-tracking.php:90`. После обоих хуков `PurchaseData::start()` прерывается, если `edd_get_errors()` не пусто (`PurchaseData.php:49-51`; 3.7.1 — сообщение в док-блоке 24-26; в старых версиях проверка могла отличаться — для 3.2.0 НЕ ПОДТВЕРЖДЕНО).
- Корзина для проверки: `edd_get_cart_contents()` (опции позиций доступны). Перед этим при отправке формы срабатывает `do_action( 'edd_pre_process_purchase' )` (`process-purchase.php:26`).
- AJAX: ошибки возвращаются через `edd_ajax_checkout_errors` (`process-purchase.php:50`).

---

## f. Страница успеха и квитанция

- `edd_get_success_page_uri( $query_string = null )` — `includes/checkout/pages.php:16`; фильтр `apply_filters( 'edd_get_success_page_uri', $success_page )` (28) — БЕЗ информации о заказе (один аргумент).
- `edd_send_to_success_page( $query_string = null )` — `pages.php:56`; фильтр `apply_filters( 'edd_success_page_redirect', $redirect, $gateway, $query_string )` (60). Используется только шлюзами `manual` (`includes/gateways/manual.php:69`) и Amazon; Stripe эту функцию НЕ вызывает.
- `edd_is_success_page()` — `pages.php:37`; `edd_get_receipt_page_uri( $order_id )` — `pages.php:88` (параметры `id` + `order` = `$order->get_receipt_hash()`). Фильтра `edd_success_page_url` в коде НЕТ.
- Как Stripe редиректит (3.7.1.1): после подтверждения платежа редирект ДЕЛАЕТСЯ НА КЛИЕНТЕ в JS: `window.location.replace( edd_stripe_vars.successPageUri )` (`assets/src/js/frontend/gateways/stripe/card-elements/payment-forms/checkout/payment-form.js:90`) либо `successPageUri + '?payment_intent=<id>&redirect_status=<status>'` (`payment-elements/contexts/checkout/form.js:113,153-156`). `successPageUri` = `edd_get_success_page_uri()` ВЫЧИСЛЕННЫЙ ПРИ ЗАГРУЗКЕ СТРАНИЦЫ КАССЫ (`includes/gateways/stripe/includes/scripts.php:99`), когда заказа ещё нет, но корзина есть. Следствие: фильтр `edd_get_success_page_uri` в этот момент можно вычислять по `edd_get_cart_contents()`; URL не должен содержать `?` (JS дописывает `?payment_intent=...` конкатенацией) — НЕ ПОДТВЕРЖДЕНО в рантайме.
- Затем на странице успеха: `add_action( 'template_redirect', 'edds_validate_confirmation_data' )` (`includes/gateways/stripe/includes/payment-receipt.php:31`) при наличии `payment_intent` и `redirect_status` вызывает `Confirmation::validate()` (`src/Gateways/Stripe/Checkout/Confirmation.php:26-52`), который делает `edd_redirect( edd_get_success_page_uri() )` либо `add_query_arg( array( 'payment-confirmation' => 'stripe' [, 'status' => 'processing'] ), edd_get_success_page_uri() )`; при ошибке — `edd_get_failed_transaction_uri()`. К этому моменту корзина обычно уже очищена (`edd_complete_purchase` → `edd_empty_cart()`), поэтому здесь надёжнее определять заказ по сессии.
- Как квитанция определяет заказ (3.7.1.1): `includes/blocks/includes/orders/functions.php:137-170` (`get_payment_key()`): (1) `$_GET['payment_key']`; (2) `$_GET['id']` + `$_GET['order']` — токен `Order::is_receipt_hash_valid()` (`Order.php:810`, данные хеша `id . payment_key . email`, `Order.php:825`); (3) `edd_get_purchase_session()['purchase_key']`. 3.2.0: тот же порядок, но для (2) токен НЕ проверяется — берётся `edd_get_payment_key( $_GET['id'] )` (`functions.php:148-150`).
- Права просмотра: `edd_can_view_receipt( $order_or_key )` — `includes/misc-functions.php:1170` (admin `edit_shop_payments` → всегда; владелец по `user_id`/email; гость — по `session['purchase_key'] === $order->payment_key`). `maybe_show_receipt()` показывает чек, если залогинен ИЛИ `session['purchase_key'] === $order->payment_key` (`includes/blocks/includes/orders/orders.php:277-293`); иначе форма подтверждения email (`verify_guest_email`, 318-335).
- Блок подтверждения заказа (`confirmation()`) берёт заказ только из сессии: `orders.php:112-114`.
- Хуки на странице квитанции:
  - `do_action( 'edd_order_receipt_after_table', $order /* EDD\Orders\Order */, $edd_receipt_args /* array, global */ )` — `includes/blocks/includes/orders/orders.php:291` и legacy-шорткод `templates/shortcode-receipt.php:189` (3.2.0: `orders.php:264`).
  - Прочие: `edd_order_receipt_before_table( $order, array() )` и `edd_order_receipt_before( $order, array() )` (`includes/blocks/views/orders/totals.php:10,13`), `edd_order_receipt_order_details( $order )` (там же :69), `edd_order_receipt_after_files( $item, $order )` (`views/orders/receipt-item.php:43`).
  - Устаревший `edd_payment_receipt_after_table( $payment /* EDD_Payment */, $edd_receipt_args )` — вызывается только если есть подписчики, через обёртку в `includes/deprecated-hooks.php:154-160`. Для новых интеграций использовать `edd_order_receipt_after_table`.

---

## g. Письма

- Создание письма-квитанции в 3.2+ — класс `EDD\Emails\Types\OrderReceipt` (`src/Emails/Types/OrderReceipt.php`, `@since 3.2.0`). Текст письма собирается в `set_email_body_content()` (`OrderReceipt.php:104-113`) ДО подстановки тегов:
  1. legacy-фильтры (запускаются только если на них есть подписчики, `LegacyPaymentFilters.php:67-87`, список — `OrderReceipt.php:295-304`): `edd_purchase_receipt_{template}` и `edd_purchase_receipt` с аргументами `( $body, $order_id, $payment_meta )`;
  2. `wpautop`;
  3. `apply_filters( 'edd_order_receipt_' . $template, $body, $order )` (111);
  4. `apply_filters( 'edd_order_receipt', $body, $order )` (112) — `$order` это `EDD\Orders\Order`. 3.2.0: фильтр `edd_order_receipt` присутствует (`OrderReceipt.php:114`).
- Этот `$body` затем проходит через `edd_do_email_tags()`, значит в добавляемый текст можно вставлять теги EDD (например `{receipt_link}`) и собственные.
- Дополнить письмо только для некоторых заказов: `add_filter( 'edd_order_receipt', function ( $body, $order ) { ... return $body . $extra; }, 10, 2 )`; проверять по записанным ранее данным (order meta / order item meta). Учитывать, что письмо может быть HTML или plain (шаблон `$this->processor()->get_template()`); `wpautop` уже применён до этих фильтров.
- Теги: `edd_add_email_tag( $tag, $description, $func, $label = '', $contexts = null, $recipients = null )` — `includes/emails/tags.php:42` (3.0+; в 3.7.1 колбэк `function ( $order_id, $email_object = null, $context = 'order' ): string`, примеры `tags.php:476`). Регистрировать надо на `add_action( 'edd_add_email_tags', ... )` (`tags.php:~175`: `edd_load_email_tags()` вызывает этот action). `edd_remove_email_tag( $tag )` — `tags.php:~58`.
- Новое в 3.7.1: `edd_register_email_tag( \EDD\Emails\Tags\Definitions\Tag $tag )` — `tags.php:55`; абстрактный класс `src/Emails/Tags/Definitions/Tag.php:21-83` (методы `get_label()`, `get_description()`, `render( $object_id, $email_object = null, $context = '' ): string`, `get_contexts()`, `get_recipients()`). Старый `edd_add_email_tag` остаётся рабочим. Фильтр `edd_email_tags` объявлен устаревшим для классовых тегов (`src/Emails/Tags/Registry.php:61`).
- Устаревший фильтр `edd_email_template_tags( $content, $payment_meta, $order_id )` — `tags.php:150-152` (только для контекста `order`, когда есть подписчики).
- Реестр типов писем: `src/Emails/Registry.php` (фильтр `edd_email_registered_types`, ~45; `Registry::register( $id, $class )`, 58). НЕ ПОДТВЕРЖДЕНО: нужна ли регистрация собственного типа для нашей задачи — для добавки к квитанции она не требуется.
- Прочие фильтры квитанции: `edd_order_receipt_email_subject`, `edd_order_receipt_email_heading`, `edd_order_receipt_headers`, `edd_order_receipt_email_attachments`, `edd_order_receipt_from_name/from_email` (все с аргументами `( $value, $order )`; `OrderReceipt.php:126-222`); отключение: `edd_disable_{email id}` (244).

---

## h. Товары, цены, налоги

- Товар: post type `download` (`includes/post-types.php:100`). Список: `get_posts( array( 'post_type' => 'download', 'post_status' => 'publish', 'numberposts' => -1 ) )` или `WP_Query`; объект — `edd_get_download( $id )` (`includes/download-functions.php:88`) → `EDD_Download`.
- `edd_has_variable_prices( $download_id )` — `download-functions.php:340`; `edd_get_variable_prices( $download_id )` — `download-functions.php:318` (массив `price_id => array( 'name', 'amount', ... )`); `edd_get_default_variable_price()` — 364; `edd_get_price_option_name( $download_id, $price_id, $order_id = 0 )` — 388; `edd_get_lowest_price_option()` — 450.
- `edd_get_download_price( $download_id )` — `download-functions.php:203` (базовая цена; для вариативных используйте `get_prices()`).
- `edd_format_amount( $amount, $decimals = true, $currency = '', $context = 'display' )` — `includes/formatting.php:125`; `edd_currency_filter( $price, $currency = '' )` — `formatting.php:161` (добавляет символ валюты по настройкам); `edd_sanitize_amount( $amount )` — `formatting.php:29`.
- `edd_get_currency()` — `includes/currency/functions.php:80` (опция `currency`, фильтр `edd_currency`).
- Налоги: `edd_use_taxes()` — `includes/taxes/functions.php:26`; `edd_prices_include_tax()` — `taxes/functions.php:325` (`prices_include_tax === 'yes'` И `edd_use_taxes()`); `edd_get_tax_rate( $country = '', $region = '', $fallback = true )` — `taxes/functions.php:125` (доля, а не проценты: `0.2`); `edd_calculate_tax( $amount, $country, $region, $fallback, $tax_rate = null )` — `taxes/functions.php:238-262` (если цены с налогом: `tax = amount - amount/(1+rate)`, иначе `amount*rate`); `edd_display_tax_rate()` — 352; `edd_prices_show_tax_on_checkout()` — 337.
- Цена позиции в корзине (с учётом налогов): `edd_get_cart_item_price( $download_id, $options = array(), $remove_tax_from_inclusive = false )` — `cart/functions.php:191`.
- В заказе: `Order_Item::amount/subtotal/tax/total` (`subtotal` при включённых в цену налогах уже очищен от налога: `orders.php:~1077-1079`).

---

## i. Сессия гостя

- `EDD()->session` — `EDD\Sessions\Handler` (`includes/class-easy-digital-downloads.php:223`). `get( $key, $default_value = null )` — `src/Sessions/Handler.php:112`; `set( $key, $value )` — `Handler.php:134` (ключ проходит `sanitize_key`; пустое значение удаляет ключ; запись в БД в конце запроса, при AJAX — сразу). Сессия стартует cookie-механизмом (`maybe_start_session`, `Handler.php:170`), только на frontend/REST-запросах (`is_request_valid_for_session`, 420-422).
- `edd_get_purchase_session()` — `includes/cart/functions.php:590` (`EDD()->session->get( 'edd_purchase' )`); `edd_set_purchase_session( $purchase_data )` — `cart/functions.php:~577`. Содержимое `edd_purchase`: полный `$purchase_data` (включая `purchase_key`, `cart_details`, `user_info`, без `post_data`/`card_info`). После `verify_guest_email` в сессии лежит только `purchase_key` (`blocks/includes/orders/orders.php:325-331`).
- Для гостя "заказ по сессии": `edd_get_order_by( 'payment_key', edd_get_purchase_session()['purchase_key'] )` (как в `orders.php:112-114`).

---

## j. Версия и PHP

- Константа версии: `EDD_VERSION` (`includes/class-easy-digital-downloads.php:367`, латест `3.7.1.1`). Синглтон — `EDD()`. Min PHP latest: 8.0; min WP: 6.7 (3.2.0: PHP 7.4, WP 5.8).
- Проверка наличия: `class_exists( 'Easy_Digital_Downloads' ) && defined( 'EDD_VERSION' ) && version_compare( EDD_VERSION, '3.0', '>=' )` — рекомендация, строка в коде ядра не проверялась.

---

## Рекомендуемые точки интеграции

| Потребность | Точка | Сигнатура / замечания |
|---|---|---|
| Привязка заказа к снимку в момент создания | `edd_built_order` | `( int $order_id, array $order_data )`. Опции корзины: `$order_data['cart_details'][ $i ]['item_number']['options']`; позиция БД: `edd_get_order_items( array( 'order_id' => $order_id, 'number' => 200 ) )` + совпадение `cart_index === $i` (+ `product_id`, `price_id`). Писать `edd_update_order_meta`/`edd_add_order_item_meta`. Идемпотентно (resume заказа вызывает хук повторно, позиции пересоздаются с новыми id). Опции также остаются в meta позиции как `_option_<ключ>` (`edd_get_order_item_meta( $id, '_option_x', true )`) — запасной путь. |
| Как попадает снимок в корзину | `edd_add_to_cart( $download_id, array( 'price_id' => N, 'свой_ключ' => ... ) )` + фильтр `edd_add_to_cart_item` | Свои ключи сохраняются в `options`, но (1) при слиянии одинаковых `id`+`price_id` и включённых количествах опции второго добавления теряются; (2) `price_id` массивом обнуляет прочие ключи. Для надёжности можно хранить снимок в собственной сессии/transient, ключ = `hash` позиции или `cart_index`. |
| Определить "оплачено/завершено" | `edd_transition_order_status` ( `$old, $new, $order_id` ), условие `'complete' === $new` (+ `'sale' === edd_get_order( $order_id )->type`) | Однократно за переход, `old` = `new` при создании. Для единственного срабатывания за жизнь заказа: `edd_complete_purchase( $order_id, $payment, $customer )` (`payments/actions.php:185`), но он не повторяется при `on_hold` → `complete`. `edd_update_payment_status` вызывается дважды при `old = complete`. Асинхронно (cron): `edd_after_order_actions( $order_id, $order, $customer )`. Для Stripe также `edds_order_complete( $order, $intent_id )`. |
| Полный возврат конкретной позиции, включая несколько частичных | `edd_refund_order( $order_id, $refund_id, $all_refunded )` + `edd_transition_order_item_status( $old, $new, $item_id )` | В `edd_refund_order`: `edd_get_order_items( array( 'order_id' => $refund_id, 'number' => 200 ) )` → для каждого `->parent` = исходная позиция; считать позицию полностью возвращённой, если `edd_get_order_item( $parent )->status === 'refunded'` или `get_refundable_amounts()['total'] <= 0` (накопительно по всем частичным возвратам, `trait-refundable-item.php:43`). Не опираться на количество (статус считается по сумме). Оговорка: при возврате из Stripe, когда `edd_refund_order` не вызывается (частичный возврат; в 3.2.0 любой), меняется только статус заказа (`edd_transition_order_status` → `refunded`/`partially_refunded`), статусы позиций и записи возврата НЕ создаются — такой случай обрабатывать как возврат на уровне заказа (решение: считать `refunded` заказа возвратом всех его позиций; `partially_refunded` без записи возврата — НЕ определяется по позиции). |
| Revoke / dispute | `edd_transition_order_status` в `revoked` (админ-действие) и в `on_hold` (dispute: meta `dispute_id`/`hold_reason`, `edd_get_order_dispute_id()`); для Stripe дополнительно `edd_stripe_dispute_created( $dispute, $order_id )` | Позиции получают тот же статус (`edd_transition_order_item_status`), `on_hold`/`revoked` не входят в `edd_get_deliverable_order_item_statuses()`. Возврат в `complete` после выигранного dispute — тоже через `edd_transition_order_status` (`edd_complete_purchase` повторно не сработает). Критерий "доступ разрешён": `in_array( $item->status, edd_get_deliverable_order_item_statuses(), true ) && $item->quantity > 0` (= `$item->is_deliverable()`). |
| Редирект на успех только для заказов только с нашим продуктом | Stripe: фильтр `edd_get_success_page_uri` на странице кассы (корзина ещё есть) + запасной вариант `template_redirect` на странице успеха по `edd_get_purchase_session()['purchase_key']` → заказ → проверка позиций → `wp_safe_redirect()` (приоритет раньше 10, до `edds_validate_confirmation_data`, иначе сначала отработает Confirmation::validate). Шлюзы `manual`/Amazon: `edd_success_page_redirect( $redirect, $gateway, $query_string )` | `edd_success_page_redirect` для Stripe НЕ вызывается. Фильтр `edd_get_success_page_uri( $uri )` не получает заказ. URL из фильтра не должен содержать `?` (JS дописывает `?payment_intent=`). Какой из двух вариантов выбрать — решение по результатам рантайм-проверки (НЕ ПОДТВЕРЖДЕНО). |
| Кнопка/блок на странице квитанции | `edd_order_receipt_after_table` | `( EDD\Orders\Order $order, array $edd_receipt_args )`. Показывать только если `edd_can_view_receipt( $order )` (хук уже выполняется после проверки доступа). Устаревший `edd_payment_receipt_after_table( EDD_Payment $payment, $args )` не использовать. |
| Добавка в письмо-квитанцию только для некоторых заказов | фильтр `edd_order_receipt` | `( string $body, EDD\Orders\Order $order )` → вернуть изменённый `$body`; тело ещё не прошло через теги, поэтому можно использовать `{теги}`. Свой тег: `add_action( 'edd_add_email_tags', fn() => edd_add_email_tag( 'my_tag', 'Описание', 'callback( $order_id, $email_object = null, $context = "order" )', 'Метка' ) )`; в 3.7.1+ также `edd_register_email_tag( Tag $tag )`. Для 3.0–3.1 (без `OrderReceipt`) — использовать устаревшие `edd_purchase_receipt` / `edd_email_template_tags` (НЕ ПОДТВЕРЖДЕНО для этих версий, исходники 3.0/3.1 не скачивались). |
| Валидация при оформлении | `edd_checkout_error_checks` ( `array $valid_data, array $post` ), при необходимости `edd_checkout_user_error_checks( $user, $valid_data, $post )` | Ошибка: `edd_set_error( 'my_id', __( 'Текст', ... ) )`; корзина: `edd_get_cart_contents()`. Любая ошибка прерывает оформление до создания заказа. |

### Общие предупреждения

- Всегда задавайте `number` в `edd_get_orders()`/`edd_get_order_items()` (по умолчанию 30).
- Статусы заказа и позиции могут расходиться (см. разделы b, c); решение о доступе принимайте по статусу ПОЗИЦИИ и её `quantity`, а при отсутствии возврата на уровне позиций — по статусу заказа.
- Проверяйте `type === 'sale'` у заказа: возвраты — тоже записи `edd_orders` с `type='refund'` и отрицательными суммами; их `edd_transition_order_status( 'new', 'complete', $refund_id )` также будет сработывать при создании возврата.
- `Order::is_complete()` включает `refunded`/`revoked` — не использовать как признак действующей покупки.
