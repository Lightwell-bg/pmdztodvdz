# BGINFO — Permanent to Long-term Residence (ПМЖ → ДВЖ)

A WordPress plugin for the bginfo site: a questionnaire for third-country nationals holding permanent residence (ПМЖ) in Bulgaria. From the answers it computes a **preliminary assessment** of the conditions for moving to the long-term resident status (ДВЖ, Art. 24g of the Law on Foreigners in the Republic of Bulgaria) and sells a **personal instruction** (an on-page document plus a PDF). Payment goes through Easy Digital Downloads (EDD) and the Stripe gateway already configured on the site.

Русская версия: [README_RU.md](README_RU.md). The user-facing text of the plugin is in Russian.

The plugin gives a preliminary assessment from the visitor's answers. It is not legal advice and not a decision of the migration authority.

## What it does (the funnel)

1. A visitor fills in the questionnaire on a page (7 steps plus a summary). The draft is autosaved.
2. After submitting, they see a free **preliminary result**: a status ("suitable", "needs checking", "obstacle"), 1-3 explanations and an offer for the paid instruction with the price taken from EDD.
3. "Go to payment" puts an EDD product bound to an immutable snapshot of the questionnaire into the cart and sends the visitor to the regular EDD checkout.
4. After the payment is confirmed, the same page shows the **personal instruction** (residence and trips analysis, document list, steps, questions for the migration office, sources) and a "Download PDF" button. A link for another device is emailed to the order email.
5. Paid content never reaches the browser before payment is confirmed: access is checked against the live EDD order on every request.

## Stack

- PHP >= 8.1, WordPress >= 6.4, Easy Digital Downloads >= 3.2.
- Dompdf is bundled inside the plugin (`vendor-prefixed/`, namespaces prefixed so it cannot clash with other plugins). **No Composer on the server.**
- No external APIs or LLMs: the evaluation is plain code in `src/Rules/`.
- **No new Stripe keys or webhooks**: the plugin uses what EDD already has configured.

## Structure

```text
bginfo-pmzh-dvzh.php   main plugin file
uninstall.php          data removal (only if enabled in settings)
src/                   code (Rules, Questionnaire, Instruction, Access, Commerce, Storage, Pdf, Http, Admin, Frontend, Cron)
assets/                questionnaire JS and CSS
vendor-prefixed/       prefixed Dompdf and dependencies
bin/build-zip.php      installable ZIP builder
tests/                 PHPUnit tests
docs/                  documentation
```

## Installation

1. Build the ZIP (on your computer): `php bin/build-zip.php`. The archive appears in `dist/` as `_<N>bginfo-pmzh-dvzh.zip` and the plugin version is bumped automatically. Flags: `--no-bump` (keep the version), `--dry-run` (list files only), `--out=<dir>`.
2. In the WordPress admin: **Plugins -> Add New -> Upload Plugin**, pick the ZIP, install and activate. If the plugin is already installed, WordPress offers to replace it; data and settings are kept.
3. Make sure Easy Digital Downloads 3.2 or newer is installed and active. Without it the questionnaire and the preview still work, but the payment button is disabled and the admin shows a warning.

## EDD product and binding

The plugin does **not** create the product. Create it manually:

1. **Downloads -> Add New**: a title (for example, "Personal instruction for the transition to ДВЖ"), a price, and a regular price if you use one. Attach no files. Save as a draft or publish it hidden from the catalog.
2. Open **ПМЖ → ДВЖ -> Настройки (Settings)**, pick the product in the list and press "Сохранить". If the product has variable prices, a variant selector appears after saving.
3. The "Готовность продажи" block must say "Всё готово" (all set).

The price on the questionnaire page always comes from EDD, never from the browser. The cart item is validated at checkout: an item without a valid questionnaire binding is rejected.

## Shortcode and placement

Shortcode: `[bginfo_pmzh_dvzh_questionnaire]`

Place it on the page **under the existing `h2` with `id="anketa-dvzh"`**, before the section "Законы, официальная процедура и формы". The plugin **outputs neither an anchor nor a heading**: they stay in the page content. Scripts and styles load only on a page that contains the shortcode. Choose the same page in the settings ("Страница с анкетой"): its URL is used in emails and in the post-payment redirect.

## Settings

Menu **ПМЖ → ДВЖ**:

- **Настройки (Settings)**: EDD product and price variant, questionnaire page, privacy policy URL (enter the URL of the site's current policy), retention (drafts 30 days, unpaid questionnaires 90 days), email link lifetime (30 days), fee text and its verification date, income guide (amount, currency, date, text), delete data on uninstall (off by default).
  - **Important:** the result "Основные условия предварительно подтверждаются" (main conditions preliminarily confirmed) is only possible when the admin sets the income guide (amount, currency, verification date) here. Without it the funds check is always "needs checking".
- **Шаблоны (Templates)**: texts of statuses, offer, caveats and explanations for each finding code. Every save creates a new version; already purchased instructions keep their own text. Plain text only.
- **Правила и источники (Rules and sources)**: the evaluation rules and official sources with their verification date (read-only). Changing rules requires a plugin update.
- **Анкеты (Questionnaires)**: a list (20 per page) and a detail screen: answers, findings, purchases with the live EDD state, events, document state. Actions: "Сгенерировать PDF заново" (regenerate PDF) and "Удалить данные анкеты" (delete questionnaire data; the EDD order is kept).

## Purchase binding and access protection

- Every submission creates an immutable **snapshot** (answers, result, preview, instruction, checksum). A purchase is bound to the pair "snapshot <-> EDD order item".
- **Access is checked against live EDD**, not the cache: order and item status, refunds, product and variant match. The cache in the purchases table is only for admin lists.
- A **guest** gets a session cookie (`bgdvzh_s`, HttpOnly, SameSite=Lax); only a hash of the token is stored. A logged-in user sees their questionnaires by `user_id`.
- The **secret email link** goes to the order email and opens **one** purchase only. The token is in the URL fragment (after `#`): browsers do not send it to the server; a script removes it from the address immediately and exchanges it for a separate access cookie. Limitation: scripts included in `head` before the plugin's can in theory read the fragment; verify on staging that no third-party scripts load on this page.
- **CSRF**: every state-changing request passes an Origin/Referer check and the session CSRF token (logged-in users also send `X-WP-Nonce`). A foreign Origin is rejected for any request to `bgdvzh/v1`.
- **Before payment** the server never returns paid text in any form. The snapshot checksum is verified before delivery.
- **PDF** is stored in the database by default. To keep files instead, define `BGINFO_PMZH_DVZH_PRIVATE_DIR` in `wp-config.php` with a path to a directory **outside the web root** (not inside the WordPress directory and not in `uploads`); on any write error the plugin falls back to database storage.

## Refunds and disputes

- A **partial refund** of the item (or a partial order refund in Stripe with no item record) **keeps access**. For a Stripe-initiated refund without an item record, the log gets the event `order_partial_refund_unattributed`.
- A **full refund, a revoked item, a dispute, an unpaid or cancelled order** closes access to the paid text.
- Several partial refunds that add up to the item total count as a full refund.

## Caching

The questionnaire page is a static shell identical for everyone: it may be cached. REST `bgdvzh/v1` responses are sent with `Cache-Control: no-store, private`. No special exclusions are needed in cache plugins, but verify that the CDN and server cache do **not cache `/wp-json/bgdvzh/`** (for example `curl -sI https://site/wp-json/bgdvzh/v1/state` must show `no-store`). The PDF download URL (`/?bgdvzh_download=...`) must not be cached either.

## Data retention and deletion

- Hourly cron deletes drafts older than the retention period, expired sessions and links, and clears the answers of unpaid questionnaires older than the retention period (the row stays).
- Paid questionnaires are not cleared automatically. Data of a specific questionnaire can be deleted on its detail screen: answers, instruction and PDF are removed, the EDD order stays.
- Deactivation only removes the cron schedule. Uninstalling the plugin drops the tables and settings **only if** "delete data on uninstall" is enabled.
- Analytics: answers never reach dataLayer, Google Analytics or Yandex Metrica.

## Verifying payment and PDF on staging

Run payment checks on **staging only**. Enable Stripe test mode on the staging copy only and never switch the live site.

1. Copy the site to staging, enable EDD test mode and the Stripe test keys (**Downloads -> Settings -> Payments**).
2. Install the plugin ZIP, choose the product and page, check the "Готовность продажи" block.
3. Walk the funnel: questionnaire -> preview -> "Перейти к оплате" -> test card `4242 4242 4242 4242` -> return to the instruction page -> "Скачать PDF".
4. Required checks:
   - EDD session creation and add-to-cart from a REST request (the cart must not be empty);
   - the questionnaire binding is kept in the cart item and a purchase row appears;
   - redirect from the success page to the questionnaire page, including a multi-item order (the standard receipt must stay);
   - the secret-link email opens in another browser and opens only that purchase;
   - a fresh browser without the access cookie sees no paid text;
   - double click on "Перейти к оплате" and buying an already paid questionnaire again (must say "already paid");
   - partial refund (access stays), full refund (access closed), a Stripe test dispute (access closed);
   - a refund from the Stripe dashboard with no item record: access stays and the event is logged;
   - the PDF opens, Cyrillic is readable, page numbers are present; generation fits PHP memory and time limits;
   - `no-store` headers on `/wp-json/bgdvzh/v1/state` and on the download, no CDN caching;
   - no third-party scripts in `head` on the questionnaire page that could read the URL;
   - "Сгенерировать PDF заново" and "Удалить данные анкеты" in the admin.
5. Restore staging afterwards; do not switch anything on the live site.

Detailed plan: [docs/TESTING.md](docs/TESTING.md).

## Development environment

A Python virtual environment does not apply here: the project is PHP.

- PHP CLI 8.1+ with the `zip` and `mbstring` extensions. Tools live in `.tools/` (git-ignored): `phpunit.phar`, `composer.phar`, `php-scoper.phar`, `strauss.phar`, `wp-cli.phar`.
- Run the tests: `php .tools/phpunit.phar -c phpunit.xml.dist`
- Syntax check: `php -l src/Plugin.php` (and likewise for changed files).
- Local stand (WordPress + EDD on SQLite): [docs/LOCAL_STAND.md](docs/LOCAL_STAND.md).
- Rebuilding the PDF dependencies: [bin/pdf-deps/README.md](bin/pdf-deps/README.md).
- Sample result and instruction on fictional data: [docs/examples/](docs/examples/).

## Third-party services

Nothing new has to be connected.

- **Easy Digital Downloads** and **Stripe** already run on the site. EDD settings: **Downloads -> Settings**; the Stripe gateway: **Downloads -> Settings -> Payments -> Stripe**; Stripe webhooks are configured in EDD the same way as for your other products. Documentation: [EDD](https://easydigitaldownloads.com/docs/), [Stripe in EDD](https://easydigitaldownloads.com/docs/stripe-payment-gateway/).
- There is **no `.env` file**: the plugin has no keys or secrets. All settings live in the WordPress option `bgdvzh_settings` and are edited in the admin; token salts come from `wp-config.php`.

## Known limitations

- The assessment is preliminary: the plugin is not legal advice and does not guarantee the migration office's decision.
- The questionnaire covers one person; family members are assessed separately.
- Rules are built into the code and reflect the sources as of their verification date; a change in the law needs a new plugin release.
- The secret link in `#` has the limitations described above; cross-device access is via the email sent to the order address.
- EDD order statuses unknown to the plugin are treated as "no access".
- Some EDD behaviour (session and cart in REST, load order) can only be confirmed on staging.
- The PDF is built by Dompdf: complex layouts are unsupported, and on 32-bit PHP font handling may emit warnings (not seen on 64-bit servers).

## Documentation

- [docs/QUICK_START.md](docs/QUICK_START.md) - quick start, step by step (Russian)
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) - architecture and contracts
- [docs/ABSENCE_METHOD.md](docs/ABSENCE_METHOD.md) - absence counting method
- [docs/TESTING.md](docs/TESTING.md) - test plan
- [docs/EDD_INTEGRATION.md](docs/EDD_INTEGRATION.md) - EDD and Stripe facts
- [docs/ANSWERS_FORMAT.md](docs/ANSWERS_FORMAT.md) - questionnaire answer format
- [docs/LOCAL_STAND.md](docs/LOCAL_STAND.md) - local stand

## Changelog

- **0.1.1** - first release. Rules version 2026.10.08-2: funds without a configured income guide are always "needs checking"; housing requires the full document set; the trips question wording was clarified.
