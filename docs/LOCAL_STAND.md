# Локальный тестовый стенд (WordPress + EDD)

Стенд лежит во временной папке сессии (вне репозитория), плагин подключён в него junction-ссылкой.

## Пути

- Каталог WordPress: `C:\Users\Vlad\AppData\Local\Temp\claude\d--1PythonProjects-20260807TranslatePress-pmdztodvdz\b81ec8a3-7639-4da6-b590-96a30b79ecbe\scratchpad\wp`
- Плагин: `wp-content/plugins/bginfo-pmzh-dvzh` - junction на корень проекта (`mklink /J`). Не активирован, пока в проекте нет главного файла плагина.
- База: SQLite (плагин SQLite Database Integration, `wp-content/db.php`, файл БД в `wp-content/database/`). MySQL не нужен.
- Лог PHP/WordPress: `wp-content/debug.log` (`WP_DEBUG` и `WP_DEBUG_LOG` включены, `WP_DEBUG_DISPLAY` выключен).
- Инструменты проекта: `.tools/` (в `.gitignore`): `composer.phar`, `phpunit.phar`, `strauss.phar`, `php-scoper.phar`, `wp-cli.phar`, `start-wp.sh`, `wp.sh`.

Каталог плагина в стенде может быть junction на проект (правки видны сразу) или установленной копией из ZIP (`dist/_N...zip`). Проверить: `dir wp-content\plugins` (у junction пометка `<JUNCTION>`). Безопасно переключить: остановить сервер (`bash .tools/start-wp.sh stop`), удалить только ссылку (`rmdir wp-content\plugins\bginfo-pmzh-dvzh` для junction - содержимое проекта не удаляется; обычную папку-копию переименовать, а не стирать), затем создать junction `mklink /J` на корень проекта либо распаковать ZIP в `wp-content/plugins/`, и запустить сервер. Не использовать `rm -rf` на junction: удалится содержимое проекта.

## Доступ

- Сайт: http://127.0.0.1:8899/
- Админка: http://127.0.0.1:8899/wp-admin/ (логин `admin`, пароль `admin`)
- Версии: WordPress 7.1.3, Easy Digital Downloads 3.7.1.1, SQLite Database Integration 3.1.0, PHP 8.5.9 (32-bit CLI).
- EDD: тестовый режим включён, включён шлюз `manual`.
- Товары: ID 11 «Тестовый товар» (5.00, для регрессии), ID 12 «Персональная инструкция ПМЖ → ДВЖ» (29.00).

## Команды (Git Bash, из корня проекта)

```bash
bash .tools/start-wp.sh          # (пере)запустить php -S на 127.0.0.1:8899 в фоне
bash .tools/start-wp.sh stop     # остановить сервер
bash .tools/wp.sh plugin list    # wp-cli с нужными расширениями и путями
bash .tools/wp.sh plugin activate bginfo-pmzh-dvzh
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8899/    # ожидается 200
```

## Особенности

- В `C:\php\php.ini` расширения `pdo_sqlite` и `sqlite3` закомментированы, поэтому скрипты передают их через `-d extension=...`. Глобальный `php.ini` не менялся. При запуске `php` вручную добавляйте те же флаги.
- Локальный PHP 32-битный: php-font-lib при встраивании шрифтов пишет предупреждения «float not representable as an int». На 64-битном сервере их нет.
- wp-cli на PHP 8.5 печатает `Deprecated` из `Colors.php` - это шум самого wp-cli.
- Стенд лежит во временной папке: при очистке temp его нужно пересоздать (скачать WordPress, плагины SQLite и EDD, скопировать `db.copy` в `wp-content/db.php`, подставить пути).

## Таблицы EDD

Если корзина EDD всегда пуста, а в `debug.log` есть `Table 'wp_edd_sessions' doesn't exist`, на SQLite не создались часть таблиц EDD. Создать недостающие:

```bash
bash .tools/wp.sh eval 'foreach(glob(WP_PLUGIN_DIR."/easy-digital-downloads/src/Database/Tables/*.php") as $f){$c="EDD\Database\Tables\\".basename($f,".php"); if(!class_exists($c)||(new ReflectionClass($c))->isAbstract()) continue; $t=new $c(); if(!$t->exists()) $t->install();}'
```

## MariaDB для тестов конкурентности

Портативный MariaDB 11.4.5 (ZIP, без установки в систему), порт 3407, InnoDB, REPEATABLE-READ.

- DSN: `mysql:host=127.0.0.1;port=3407;dbname=bgdvzh_test;charset=utf8mb4`, пользователь `root`, пароль `root`.
- Запуск: `bash .tools/mariadb.sh start`
- Остановка: `bash .tools/mariadb.sh stop`
- Проверка: `bash .tools/mariadb.sh status` (ответ `mysqld is alive`).
- Файлы сервера лежат в scratchpad сессии (пути в `.tools/mariadb.sh`); при очистке temp нужно скачать ZIP заново и выполнить `mariadb-install-db`.
