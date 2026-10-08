# Прогресс: консультации (версия 0.2.0)

Контракт: [CONSULTATIONS.md](CONSULTATIONS.md). Серверная часть описана в его разделе «Реализация консультаций (бэкенд)».

## Сделано (серверная часть, этап A)
- [x] Схема БД версии 3: `units`, `seats`, `bookings`, `credits`, `notifications`, `messages`, `purchases.kind/booking_id`; обновление v1/v2 через dbDelta, дедупликация покупок сохранена.
- [x] `BookingRepository`, `Notifier` (очередь с UNIQUE и подключаемым отправителем), `BookingSync`, `BookingCron`.
- [x] Настройки `consult` (`ConsultSettings`, `Settings::consultation()`), `ConsultGate`, исправление `EddGate` (опубликован и `can_purchase`).
- [x] `CreditCalculator`, `CreditService`, фильтры `edd_cart_item_price` и `edd_get_cart_item_name`.
- [x] `CartService::replaceWithConsultation`, `CheckoutGuard`, `OrderBinder`, `OrderEvents` для консультаций; доступ к инструкции по любой оплаченной покупке снимка.
- [x] REST `consult/*`, grant `booking`, поле `consultations` в `/state` и `/result`.
- [x] Расписание cron `bgdvzh_5min`, обработка истёкших резервов.
- [x] Тесты: расписание и DST, зачёт, правила корзины, настройки, репозитории и BookingService на SQLite.

## Осталось
- [x] Агент B (упрощённая версия, «это не CRM»): письма `Mailer`, `.ics` `IcsBuilder`, напоминания `Reminders`, возврат инструкции `CreditRefundHandler`, админка (один список + карточка, короткие настройки), шаблоны `offers`, документация. Перенос посетителем убран. Подробности: [CONSULTATIONS.md](CONSULTATIONS.md), раздел «Реализация (письма и админка)».
- [ ] Агент C: фронтенд (карточки, выбор времени, экран записи, аналитика).
- [ ] Ревью Codex по всему диффу задачи.
- [ ] Проверка на стенде: `edd_add_to_cart` из REST, уменьшенная цена в заказе Stripe, поведение pending-заказов, обновление схемы на установке версии 0.1.x.
- [ ] Сборка ZIP (`bin/build-zip.php`) после ревью и тестов.
