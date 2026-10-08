# Архитектура плагина bginfo-pmzh-dvzh

Здесь описаны контракты, по которым пишется код. Если код расходится с этим документом, ошибка в коде.
Факты об EDD взяты из [EDD_INTEGRATION.md](EDD_INTEGRATION.md). Источники права — в [sources/](sources/). Методика отсутствий — в [ABSENCE_METHOD.md](ABSENCE_METHOD.md).

## 1. Слои

| Слой | Классы | Зависимость от WP/EDD |
|---|---|---|
| Правила | `Rules\DateMath`, `AbsenceCalculator`, `ResidenceTimeline`, `Evaluator`, `RulesMeta` | нет |
| Анкета | `Questionnaire\Labels`, `Schema` (шаги/поля/условия для JS), `Validator` (нормализация) | нет |
| Инструкция | `Instruction\DefaultTemplates`, `Composer`, `HtmlRenderer`, `TemplateStore` | только TemplateStore ($wpdb) |
| Доступ | `Access\Tokens`, `AccessPolicy`, `SessionManager` (cookie ↔ сессия), `GrantManager` | частично |
| Торговля | `Commerce\OrderReader` (интерфейс), `EddOrderReader`, `EddGate`, `CartService`, `OrderBinder`, `CheckoutGuard`, `OrderEvents`, `ReceiptIntegration`, `SuccessRedirect` | EDD ≥ 3.2 |
| Хранилище | `Storage\Schema`, репозитории `Sessions`, `Drafts`, `Snapshots`, `Purchases`, `Grants`, `Documents`, `Events` | $wpdb + prepare |
| Документ | `Pdf\PdfRenderer` (Dompdf с префиксом), `Pdf\DocumentService` (state machine) | vendor-prefixed |
| HTTP | `Http\RestController` (`bgdvzh/v1`), `Http\DownloadController` | WP |
| Админка | `Admin\Settings`, `Admin\TemplatesPage`, `Admin\SubmissionsList`, `Admin\SubmissionView`, `Admin\Notices` | WP |
| Фронт | `Frontend\Shortcode`, `Frontend\Assets`, `assets/questionnaire.{js,css}` | WP |

`Plugin::boot()` собирает зависимости вручную, без DI-контейнера. Во всём коде запрещены `?>` в `//`-комментариях, `print`/`var_dump`, а также SQL без `$wpdb->prepare` при наличии переменных.

## 2. Нормализованные ответы

Канонический формат — [ANSWERS_FORMAT.md](ANSWERS_FORMAT.md). Его же использует Evaluator.
- Даты передаются строками `Y-m-d` или `''`.
- Перечисления — только из `Labels`.
- Неизвестные ключи отбрасываются.

Лимиты:
- статусов — 20, поездок — 150, периодов без разрешения — 10;
- строки — до 200 символов;
- JSON черновика — до 200 КБ.

## 3. Сессия и защита гостевых запросов (`Access\Tokens`, `SessionManager`)

- **Cookie сессии** `bgdvzh_s`: токен 64 hex. Атрибуты: HttpOnly, Secure на HTTPS, SameSite=Lax, path `/`, срок — `retention_days_drafts`. В БД хранится только `sha256`. Сессия создаётся `POST /session` при первом сохранении черновика.
- **CSRF** = `Tokens::csrf(csrf_secret, action, scope)` и передаётся в заголовке `X-Bgdvzh-Csrf`.
  - Токены выдаёт только `GET /state` по своей cookie.
  - Действия и scope: `draft` (scope ''), `submit` (''), `checkout` (public_id снимка), `retry` (public_id), `send_link` (public_id).
- **Каждый изменяющий запрос** проходит три проверки:
  1. метод POST;
  2. `Tokens::sameOrigin(Origin, Referer, home_url())`;
  3. CSRF своей сессии.

  Если Origin задан и он чужой, отказ получает **любой** запрос к `bgdvzh/v1`, в том числе GET.
- **Вошедшие пользователи**: JS отправляет `X-WP-Nonce` (wp_rest), иначе WP REST считает их гостями. Nonce не заменяет проверку владельца.
- **Grant-cookie** `bgdvzh_g`: до 5 токенов через `.`, HttpOnly, SameSite=Lax. Каждый токен — строка `grants` вида `kind='cookie'` с purchase_id и сроком. Grant открывает **только** свою покупку: `GET /result` и скачивание для её снимка. Список анкет сессии, черновик, покупка и выпуск ссылок по grant недоступны.
- **Лимиты частоты** (transient по sha256(IP + соль)):
  - `/session` — 20 в час;
  - `/submit` — 30 в час на сессию;
  - `/grant` — 10 за 10 минут на IP;
  - `/send-link` — 3 в сутки на покупку.

## 4. REST `bgdvzh/v1`

Все ответы отдаются с заголовками `Cache-Control: no-store, private`, `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`. Тексты ошибок — на русском, без технических слов.

| Метод, путь | Кто | Что делает |
|---|---|---|
| `POST /session` | любой, sameOrigin | создаёт сессию, если валидной cookie нет; идемпотентен |
| `GET /state` | своя сессия и/или пользователь | csrf-токены, черновик `{data, step, revision}`, снимки сессии (у вошедшего — ещё и свои по user_id): public_id, дата, статус, preview, состояние оплаты/документа; флаги `purchase_enabled`, `price_html` (цена из EDD), `privacy_url`, `today` |
| `POST /draft` | сессия + csrf | сохраняет черновик; `revision` — оптимистическая блокировка: при расхождении 409 и текущий черновик, клиент предлагает загрузить версию из другой вкладки |
| `POST /submit` | сессия + csrf | `Validator`: при ошибках 422 `{errors: {path: msg}}`. Иначе `Evaluator` → `Composer::preview` + `Composer::instruction` → новый неизменяемый снимок. Возвращает **только** preview и public_id. Черновик остаётся, его можно править и отправлять заново: каждый раз создаётся новый снимок |
| `POST /checkout` | владелец по сессии/пользователю + csrf(scope=public_id) | см. §6. Возвращает `{redirect: edd_get_checkout_uri()}` либо 409 `already_paid` / `in_progress` |
| `GET /result?id=` | владелец (сессия, пользователь или grant этой покупки) | preview. Если `AccessPolicy::paymentState` = paid — дополнительно `instruction_html` (`HtmlRenderer`, экранированный) и `document: {state, download_url}`. Иначе `payment: pending/failed/refunded/...` без платного текста |
| `GET /result?id=` (администратор) | владелец по сессии/пользователю + `AdminAccess::can()`, нет действующей оплаты | `payment: 'admin'`, `instruction_html` (после проверок purged и `content_hash`), `document: {state:'done', download_url, admin:true}`. Только для владельца по сессии/пользователю: доступ лишь по grant `admin` не даёт. Оплаченная покупка главнее (`paid`) |
| `POST /retry-document` | владелец + csrf | сбрасывает failed-документ (cooldown 60 с) и запускает генерацию |
| `POST /send-link` | владелец по сессии/пользователю + csrf | выпускает новую ссылку `kind='link'` и отправляет её `wp_mail` на email заказа EDD; email в ответе не раскрывается |
| `POST /grant` | любой, sameOrigin, лимит по IP | `{token}` из фрагмента → проверка hash, срока и revoked → новая grant-cookie того же scope → `{id: public_id}` |

**Скачивание** — не REST: `GET /?bgdvzh_download=<public_id>` на `template_redirect`.
1. Владелец проверяется по cookie (сессия, пользователь, grant), оплата — по живому состоянию EDD.
2. `DocumentService::ensure()`.
3. Ответ: `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="instrukciya-dvzh-<дата>.pdf"`, `no-store`, `noindex`, `no-referrer`, `nosniff`.
4. Без доступа — 403/404 с понятной страницей без деталей.

**Скачивание администратором**: обход действует только при `AdminAccess::can()` и верном nonce `bgdvzh_dl_<public_id>` (`_wpnonce` в `download_url` для `payment: 'admin'`); без nonce — обычные проверки владельца и оплаты. Разрешена любая неочищенная анкета с верным `content_hash`, независимо от владельца и оплаты. Есть оплаченная покупка с готовым документом — отдаётся он; иначе PDF строится из `snapshot.instruction` (`DocumentService::renderForSnapshot`, без хранения и строк документов). Заголовки те же. Событие `admin_download` (purchase_id 0 или id покупки; detail `user <id>, snapshot …<8 символов>`), токены и ответы не логируются.

## 5. Снимок

- Неизменяем: `answers`, `result`, `preview`, `instruction` (документ Composer) и `content_hash` = sha256(JSON всех четырёх).
- `rules_version` и `templates_version` записываются в момент создания.
- `instruction` собирается при submit и **никогда** не уходит в браузер до оплаты.
- Перед выдачей платной части сверяется `content_hash`. Если он не совпадает, отказ и событие в журнал.
- `summary` — минимальная строка для списка в админке: статус, регион, дата подачи. Без гражданства и имён.

## 6. Покупка (`CartService`, `CheckoutGuard`, `OrderBinder`)

`POST /checkout`:
1. Проверки: `EddGate::ok()`, товар и вариант настроены, снимок принадлежит запрашивающему и не очищен.
2. Покупки снимка проверяются по живому EDD:
   - есть paid → 409 `already_paid`;
   - есть заказ в статусе `processing` или `on_hold` → 409 `in_progress` («Ожидаем подтверждения оплаты»);
   - `pending`, `failed`, `abandoned`, `cancelled` повторную попытку не блокируют.
3. `locked_at` = now, если ещё не задан.
4. Из корзины удаляются все позиции нашего download_id (`edd_remove_from_cart` по индексам в обратном порядке).
5. `edd_add_to_cart( download_id, ['price_id' => int, 'bgdvzh_ref' => Tokens::cartRef(public_id, snapshot.session_id, wp_salt('auth'))] )`. `price_id` передаётся только для вариативных цен и только как int.
6. Количество: CheckoutGuard не пропускает quantity ≠ 1.

`CheckoutGuard` (`edd_checkout_error_checks`), для каждой позиции нашего товара:
- есть `bgdvzh_ref`, MAC совпадает (session_id берётся из снимка), снимок существует и не очищен;
- по снимку нет paid-покупки;
- позиция нашего товара в корзине одна, quantity 1;
- price_id совпадает с настройкой;
- если `EddGate` выключен — тоже ошибка.

Ошибка — `edd_set_error('bgdvzh_…', 'Сначала заполните анкету на странице …')`.

`OrderBinder` (`edd_built_order( $order_id, $order_data )`) — идемпотентный:
- для каждого `cart_details[i]` с нашим download и `options.bgdvzh_ref`: проверить ref и найти позицию заказа с `cart_index === i`;
- upsert purchases по паре (order_id, snapshot_id): обновить order_item_id, price_id, item_total, user_id заказа, session_id снимка;
- если в заказе есть наш товар без валидного ref — событие `unbound_item`.

Повторный `edd_built_order` при возобновлении заказа пересоздаёт позиции с новыми id, поэтому upsert обновляет order_item_id.

## 7. События оплаты (`OrderEvents`)

Хуки:
- `edd_transition_order_status($old, $new, $order_id)`;
- `edd_transition_order_item_status($old, $new, $item_id)` — заказ берётся из позиции;
- `edd_refund_order($order_id, $refund_id, $all)`;
- `edd_complete_purchase($order_id)`;
- `edd_stripe_dispute_created($dispute, $order_id)`.

Для каждой покупки заказа:
1. `AccessPolicy::paymentState()` — по живым данным.
2. Обновление кэша `state`, `state_reason`, `refunded_total`; `paid_at` выставляется при первом paid.
3. Запись события.
4. При переходе в paid — строка документа `pending` и `wp_schedule_single_event(time()+5, 'bgdvzh_generate', [purchase_id])` как запасной путь.

Повторные вызовы ничего не ломают: обработчик только пересчитывает состояние.

Частичный возврат на уровне заказа без записи по позиции (Stripe): доступ сохраняется, событие `order_partial_refund_unattributed` видно в админке.

Право доступа **никогда** не берётся из кэша `purchases.state`: только `AccessPolicy` при запросе.

## 8. Документ (`DocumentService`)

- Страница показывает HTML, отрендеренный из `snapshot.instruction` при каждом запросе. Он не зависит от состояния PDF, поэтому доступ к инструкции есть даже при ошибке PDF.
- PDF строится из того же HTML (`HtmlRenderer::document()` с печатным CSS).

`ensure(purchase)` — **атомарный захват**:

```
UPDATE documents
SET state='running', lock_token=:t, locked_until=now+120s, attempts=attempts+1
WHERE purchase_id=:p
  AND (state IN ('pending','failed') OR (state='running' AND locked_until<now))
  AND attempts<5
```

- Захват удался — генерация; затем `state='done'`, pdf, sha256, `generated_at` с условием `WHERE lock_token=:t`.
- Исключение — `state='failed'`, `last_error` до 255 символов, без персональных данных.
- Захват не удался, но state=done — PDF отдаётся. Если генерация идёт — «Документ готовится», клиент повторяет запрос.
- Хранение по умолчанию — `documents.pdf` (LONGBLOB). Если определена `BGINFO_PMZH_DVZH_PRIVATE_DIR`, путь реальный, лежит не под ABSPATH и не под uploads, а запись удалась — PDF кладётся в файл `<sha256>.pdf`, `pdf_file` хранит имя. При любой ошибке запись идёт в БД.
- Кнопка «Повторить» на странице (`/retry-document`): сбрасывает `attempts=0`, `state='pending'`, если state=failed.
- Cron `bgdvzh_cron` (hourly) дублирует обработку:
  - pending и failed документы, у которых attempts < 5;
  - пересчёт кэша для покупок в pending;
  - очистка по сроку хранения.

## 9. Ссылки для гостя (`GrantManager`, `ReceiptIntegration`)

- **Письмо**: фильтр `edd_order_receipt($body, Order $order)`. Если в заказе есть наша paid-покупка, к письму добавляется абзац со ссылкой `<page_url>#dvzh-k=<token>` (kind `link`, срок `link_ttl_days`). Каждое письмо получает новый токен. Остальные письма не меняются.
- **Квитанция**: `edd_order_receipt_after_table(Order $order)` выводит кнопку «Открыть инструкцию» → `<page_url>#anketa-dvzh` **без токена**, плюс текст «Ссылка для доступа с другого устройства отправлена на email заказа». Квитанция открывается по ссылке EDD, поэтому выдавать на ней доступ нельзя.
- **Переход со страницы успеха** (`SuccessRedirect`): `template_redirect` с приоритетом 20, то есть после проверки подтверждения Stripe (10).
  1. Срабатывает, если `edd_is_success_page()`, заказ найден по `edd_get_purchase_session()['purchase_key']`, **все** его позиции — наш товар и статус не failed.
  2. Тогда `wp_safe_redirect(page_url + '#anketa-dvzh')`.
  3. Смешанные заказы остаются на стандартной квитанции.
- **Фрагмент в адресе**:
  1. Встроенный скрипт в `wp_head` с приоритетом 0 (только на странице с шорткодом) читает `#dvzh-k=…`, кладёт токен в `sessionStorage['bgdvzh_k']` и сразу делает `history.replaceState` на `#anketa-dvzh`.
  2. Основной JS отправляет `POST /grant`, после чего удаляет токен из sessionStorage.

  Ограничения:
  - браузер не передаёт фрагмент на сервер и в Referer, но скрипты, подключённые в `<head>` раньше `wp_head`, могут его прочитать — это нужно проверить на staging;
  - вошедший пользователь видит свои покупки по user_id, ссылка ему не нужна.

## 10. Админка

Права — `manage_options`. Во всех формах есть nonce и `check_admin_referer`.

- **Настройки** (`bgdvzh_settings`, autoload=no):
  - товар EDD — select из существующих download, без создания и публикации; вариант цены;
  - страница анкеты, URL политики;
  - сроки хранения: черновики 30 дн., неоплаченные снимки 90 дн.; срок ссылки — 30 дн.;
  - такса — текст и дата актуальности;
  - ориентир дохода — сумма в месяц на человека, валюта, дата, текст;
  - удаление данных при деинсталляции — по умолчанию нет.
- **Шаблоны**: формы по `DefaultTemplates::get()`. Сохранение создаёт новую строку в `templates`, версия `Ymd-His`. Купленные снимки хранят собранный текст, поэтому старые инструкции не меняются.
- **Правила и источники**: только чтение — `RulesMeta::sources()`, VERSION, VERIFIED_AT.
- **Анкеты**: WP_List_Table.
  - Колонки: дата, № (последние 8 символов public_id), результат, `summary`, заказ (ссылка на экран заказа EDD), оплата (кэш), документ.
  - Полные ответы, токены и ссылки в списке не показываются.
- **Просмотр анкеты**: ответы (таблица), findings, preview, события.
  - Действия: «Сгенерировать PDF заново» (тот же снимок, тот же HTML) и «Удалить данные анкеты».
  - Для тех, у кого `AdminAccess::can()`: кнопка «Скачать PDF (без оплаты)» (admin-post `bgdvzh_admin_download`, nonce `bgdvzh_admin_download_<id>` + проверка права, потоковая выдача) и сворачиваемый `<details>` «Инструкция» (`HtmlRenderer::render`). В блоке предпросмотра показывается «Разбор по разделам».
  - Удаление обнуляет answers, result, preview, instruction и документ, отзывает grants и выставляет `purged_at`. Строки purchases и заказ EDD остаются.

## 11. Хранение и удаление

- Cron удаляет:
  - черновики старше `retention_days_drafts`;
  - сессии с истёкшим сроком;
  - неоплаченные снимки без purchases в paid/pending старше `retention_days_unpaid` — данные обнуляются, строка остаётся с `purged_at`;
  - истёкшие grants.
- Деактивация снимает cron и не трогает данные.
- `uninstall.php` удаляет таблицы и опции, только если включена опция удаления.

## 12. Фронтенд

- Шорткод выводит одинаковую для всех оболочку `<div class="bgdvzh" data-…>` и `<noscript>`. Якорь и заголовок не выводятся.
- Ассеты подключаются только при `has_shortcode()` на текущей записи. Ответы не попадают в dataLayer, GA или Метрику.
- 7 шагов + сводка, прогресс, «Назад»/«Далее». Строки статусов, поездок и периодов можно добавлять.
- В вопросах с датами есть варианты «Не знаю» / «Нет точных данных».
- Автосохранение черновика: debounce 1,5 с и при смене шага.
- Доступность: label/for, fieldset/legend, `aria-live` для ошибок, фокус на первой ошибке. Вёрстка mobile-first.
- После submit показываются статус, 1–3 пояснения и предложение: состав, цена из EDD, кнопка «Перейти к оплате». Выше — оговорки для статусов check и obstacle.
- Пока не подтверждена оплата — «Ожидаем подтверждения оплаты»: опрос `/result` каждые 5 с до 3 минут, затем кнопка «Проверить ещё раз».
- После оплаты — HTML инструкции, кнопка «Скачать инструкцию PDF», «Отправить ссылку на email заказа». При ошибке документа — «Повторить».

## Реализация

Отклонения от документа и уточнения, появившиеся при написании кода:

- **Формы ответов REST** подчинены контракту с фронтом (`assets/questionnaire.js`):
  - `GET /state` → `{csrf:{draft,submit}, draft:{data,step,revision}|null, snapshots:[{id, created_at ('d.m.Y H:i', время сайта), status, preview, payment, csrf:{checkout,retry,send_link}}], price_html, purchase_enabled, privacy_url, today}`. Без сессии — 401 `{code:'no_session'}`: клиент делает `POST /session` и повторяет запрос.
  - `payment`: `none` (покупок нет), `pending`, `paid`, `failed`, `refunded`, `revoked`, `disputed`, `mismatch`, `missing`, `unavailable` (EDD отключён: состояние неизвестно, доступа нет, кэш не меняется; фронт показывает «Инструкция временно недоступна. Попробуйте позже.» без предложения оплаты). `mismatch` также возвращается, если `content_hash` снимка не сошёлся (платный текст не отдаётся).
  - `POST /draft` → `{revision}`; при конфликте 409 `{code, message, draft}`.
  - `POST /submit` → `{id, preview, csrf}`; 422 → `{code, message, errors:{путь: текст}}`, пути вида `trips.3.back`, `statuses.0.to`, `history.gaps.1.from`, `applicant.citizenship`.
  - `GET /result` → csrf только владельцу по сессии/пользователю (не по grant). Чужой или несуществующий снимок — одинаково 404.
  - `POST /retry-document` → `{document:{state, download_url}}`; генерация откладывается на cron-событие `bgdvzh_generate` (+5 с), клиент опрашивает `/result`.
  - Ошибки: `{code, message}` с русским текстом.
- **Валидатор**: сроки действия документов (`documents.passport_until`, `documents.pmzh_card_until`, `health.cover_until`) допускаются до сегодня + 15 лет (паспорт выдают на 10 лет); для остальных дат верхняя граница сегодня + 3 года, как в ANSWERS_FORMAT. Поездка может начинаться в день возвращения предыдущей (пересечение строгое). Пустые перечисления нормализуются в `unknown`, а не в `''` (кроме `bank_country` и `income_currency`).
- **content_hash** считается от четырёх JSON-строк ровно в том виде, как они лежат в БД (через `\n`), а не от повторного `json_encode`: проверка не зависит от порядка ключей и точности чисел.
- **Сессии** с хотя бы одним снимком cron не удаляет: освободившийся `AUTO_INCREMENT` id мог бы быть выдан новой сессии и открыть ей чужие снимки. У очищенных снимков `session_id` обнуляется, после чего сессия удаляется.
- **Тело PDF** записывается в БД через `UNHEX(...)`, чтобы бинарные данные не зависели от кодировки соединения.
- **Вход на странице**: `Plugin::instance()->services` — набор сервисов для страниц админки; `Storage\SnapshotPurger::purge()` — единая очистка данных анкеты (cron и кнопка «Удалить данные анкеты»); `DocumentRepository::resetForRegenerate()` — «Сгенерировать PDF заново».
- **Правила 2026.10.08-2** (`RulesMeta::VERSION`): `FUNDS_REGULAR_NO_THRESHOLD` теперь `check` (достаточность не проверена); `RES_GAP_UNKNOWN` (`gap_without_permit = unknown`) и `RES_LATE_OR_REFUSAL` также для `unknown`; `trips_any = yes` без поездок → `TRIP_INCOMPLETE`; поездки у начала окна учитывают неопределённость дат (см. `docs/ABSENCE_METHOD.md`, `crosses_window_possible`); жильё по основанию: собственное — только `notarial_act`, аренда — `lease` и `owner_declaration` (`HOUSING_RENT_LEASE`, `HOUSING_RENT_DECLARATION`), семья/иное — `owner_declaration` (`HOUSING_OTHER_BASIS`), одна `address_registration` жильё не доказывает. Вопрос шага 3 про поездки переформулирован под отсутствия, частично попадающие в период (`docs/ANSWERS_FORMAT.md`).
- **EDD недоступен ≠ заказа нет** (B1): `OrderReader::available()`; `AccessPolicy::paymentState` возвращает `unavailable` (`AccessPolicy::UNAVAILABLE`, доступ закрыт). `OrderEvents::syncPurchase` и `/result` в этом состоянии кэш `purchases` не перезаписывают; скачивание владельцу отвечает 503. Автоочистка по сроку хранения (`CronHandler::retention`, `SnapshotPurger::canAutoPurge`) пропускается целиком при недоступном EDD; снимок с любой покупкой, у которой есть `paid_at` или кэш-состояние `paid/pending/disputed/refunded/revoked`, автоматически не удаляется; снимок с покупками удаляется, только если живое состояние каждой — `failed`/`missing`. Ручное удаление в админке работает как раньше.
- **PDF без cron** (B2): в `/result` при `generate=1` и документе `pending` вызывается `DocumentService::ensure()` синхронно (атомарный захват, не более одной генерации). Фронт при оплате всегда показывает ссылку «Скачать инструкцию PDF» (контроллер скачивания генерирует документ синхронно) и один раз отправляет `generate=1`; для `pending/running` добавлено пояснение «Документ готовится — если загрузка не началась, попробуйте через минуту».
- **Зависшая генерация** (B3): `DocumentRepository::failExhausted()` переводит `running` с истёкшей блокировкой и исчерпанными попытками в `failed` (внутри `claim()` и в начале ежечасного cron), после чего «Повторить» (`resetFailed`) работает.
- **Покупки** (B4): `Schema::DB_VERSION = '2'`, `UNIQUE KEY order_snapshot (order_id, snapshot_id)` рядом с `order_item_id`. Перед `dbDelta` `Schema::dedupePurchases()` удаляет дубли пары (остаётся наименьший `id`, grants и документы дублей удаляются, событие `purchase_deduplicated`). `PurchaseRepository::upsertForOrderSnapshot`: обычный `INSERT`, при дубле любого ключа — `UPDATE` строки по `(order_id, snapshot_id)`; ошибка записи → событие `bind_failed` и `null`. Работает на MySQL и на SQLite-интеграции (проверено на стенде).
- **Очистка и генерация** (B5): `SnapshotPurger::purge` сначала атомарно ставит `purged_at` (`SnapshotRepository::markPurged`), затем обнуляет данные (`wipeData`), затем удаляет документы и файлы. `DocumentService::ensure` не работает для очищенных снимков; после `finish` снимок перечитывается — если очищен, строка документа и файл удаляются; при потере блокировки записанный файл удаляется (если на него не ссылается ни одна строка документа).
- **Удаление при деинсталляции** (B6): `uninstall.php` перед `DROP TABLE` удаляет файлы PDF из проверенного `BGINFO_PMZH_DVZH_PRIVATE_DIR` (только имена `<sha256>.pdf`).
- **Бесплатный разбор (0.1.2)**: `Composer::preview( $result, $answers )` добавляет `analysis` `{period, facts:[{label,value}], sections:[{id,title,status,lines}]}` (7 разделов: residence, trips, special, housing, health, funds, documents; status — `ok|check|obstacle|info`, худшая severity findings раздела). В строки попадают только факты из ответов, цифры расчёта и `short`-тексты; `detail`, `action`, `question`, документы, план и вопросы остаются в платной инструкции (тесты `ComposerTest`, `PreviewIsolationTest`). В старых снимках `analysis` нет: фронт показывает список `reasons`. Контракт `/submit`, `/result`, `/state` не менялся: `analysis` лежит внутри `preview`.
- **Доступ администратора (0.1.2)**: `Access\AdminAccess::can()` = `get_current_user_id() > 0` и `current_user_can( apply_filters( 'bgdvzh_free_access_capability', 'manage_options' ) )`. `Access\ResultAccess::decide( owner, grantOnly, admin, paymentState )` — чистое решение: `paid` главнее, иначе `admin` при `admin && owner && !grantOnly`, иначе исходное состояние. Используется в `/result` и в `payment` каждого снимка в `/state` (для `granted` не применяется). Фронт при `payment = 'admin'` показывает инструкцию с пометкой, ссылку на PDF, без предложения и без «Отправить ссылку на email заказа». Фильтр `bgdvzh_free_access_capability` меняет требуемое право.
- **Статус `processing`** у заказа и `on_hold` дают `in_progress` в `/checkout`; остальные неоплаченные статусы повторную попытку не блокируют.

Хуки EDD, реально регистрируемые (только при `EddGate::eddAvailable()`, на `init` с приоритетом 5):
- `edd_checkout_error_checks` (CheckoutGuard);
- `edd_built_order` (OrderBinder);
- `edd_transition_order_status`, `edd_transition_order_item_status`, `edd_refund_order`, `edd_complete_purchase`, `edd_stripe_dispute_created` (OrderEvents);
- фильтр `edd_order_receipt`, действие `edd_order_receipt_after_table` (ReceiptIntegration);
- `template_redirect` с приоритетом 20 (SuccessRedirect).

Хуки WordPress: `rest_api_init`, `rest_post_dispatch` (no-store), `template_redirect` с приоритетом 5 (скачивание), `admin_notices` (только `manage_options`), `admin_init` (обновление схемы БД и расписания), `bgdvzh_cron` (hourly), `bgdvzh_generate` (одиночное событие).

Не проверено в рантайме (нужна проверка на стенде): создание сессии EDD и работа `edd_add_to_cart` из REST-запроса; сохранение пользовательской опции `bgdvzh_ref` в позиции корзины при включённых количествах; порядок `plugins_loaded`/`init` для EDD.
