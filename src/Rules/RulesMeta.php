<?php
/**
 * Версия правил, источники и дата их проверки.
 *
 * При изменении логики Evaluator или AbsenceCalculator поднимать VERSION:
 * она сохраняется в каждом снимке, поэтому купленные инструкции остаются
 * привязанными к правилам, по которым были рассчитаны.
 *
 * Тексты источников: docs/sources/*.md (загружены 2026-10-08).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Rules;

final class RulesMeta {

	public const VERSION     = '2026.10.08-2';
	public const VERIFIED_AT = '2026-10-08';

	/**
	 * Реестр источников. Ключ используется в findings.
	 *
	 * @return array<string,array{title:string,url:string,note:string,verified_at:string}>
	 */
	public static function sources(): array {
		$zchrb  = 'https://lex.bg/bg/laws/ldoc/2134455296';
		$ppz    = 'https://lex.bg/laws/ldoc/2135738597';
		$iisda  = 'https://iisda.government.bg/adm_services/services/service_provision/22087';
		$v      = self::VERIFIED_AT;

		return array(
			'zchrb_24g_1'  => array( 'title' => 'ЗЧРБ, чл. 24г, ал. 1', 'url' => $zchrb, 'note' => '5 лет законного и непрерывного пребывания перед подачей; статус постоянный после приобретения. Ред. ДВ бр. 55 от 16.06.2026.', 'verified_at' => $v ),
			'zchrb_24g_2'  => array( 'title' => 'ЗЧРБ, чл. 24г, ал. 2', 'url' => $zchrb, 'note' => 'Не засчитываются периоды пребывания исключительно на временном основании (au pair, сезонная работа, командирование и др.) и по формально ограниченным разрешениям.', 'verified_at' => $v ),
			'zchrb_24g_3'  => array( 'title' => 'ЗЧРБ, чл. 24г, ал. 3', 'url' => $zchrb, 'note' => 'Пребывание как студент/ученик/стажёр по ст. 24в засчитывается наполовину.', 'verified_at' => $v ),
			'zchrb_24g_45' => array( 'title' => 'ЗЧРБ, чл. 24г, ал. 4–7', 'url' => $zchrb, 'note' => 'Особый порядок для лиц с международной защитой; другие виды защиты и висящие производства не являются основанием.', 'verified_at' => $v ),
			'zchrb_24g_8'  => array( 'title' => 'ЗЧРБ, чл. 24г, ал. 8', 'url' => $zchrb, 'note' => 'Отсутствия не прерывают срок, если каждое — под 6 последовательных месяцев и в общей сложности не более 10 месяцев за 5 лет.', 'verified_at' => $v ),
			'zchrb_24g_9'  => array( 'title' => 'ЗЧРБ, чл. 24г, ал. 9', 'url' => $zchrb, 'note' => 'Достаточные средства не ниже минимальной заработной платы и минимальной пенсии; обязательное медицинское страхование или страховка.', 'verified_at' => $v ),
			'zchrb_24d'    => array( 'title' => 'ЗЧРБ, чл. 24д', 'url' => $zchrb, 'note' => 'Разрешение долгосрочно пребывающего действует 5 лет и подновляется; истечение срока документа не означает потерю статуса.', 'verified_at' => $v ),
			'zchrb_33m'    => array( 'title' => 'ЗЧРБ, чл. 33м', 'url' => $zchrb, 'note' => 'Особые правила для владельцев Синей карты ЕС (суммирование периодов в ЕС, иные пределы отсутствий).', 'verified_at' => $v ),
			'zchrb_40'     => array( 'title' => 'ЗЧРБ, чл. 40', 'url' => $zchrb, 'note' => 'Основания для отнятия статуса.', 'verified_at' => $v ),
			'ppz_51'       => array( 'title' => 'ППЗЧРБ, чл. 51', 'url' => $ppz, 'note' => 'Личная подача, документы: паспорт, средства не менее чем на год, страховка (если нет страхования по ЗЗО), жильё; служебные справки МВР о пребывании. Ред. ДВ бр. 7 от 20.01.2026.', 'verified_at' => $v ),
			'ppz_52'       => array( 'title' => 'ППЗЧРБ, чл. 52', 'url' => $ppz, 'note' => 'Дополнительные документы для владельцев Синей карты ЕС.', 'verified_at' => $v ),
			'ppz_53'       => array( 'title' => 'ППЗЧРБ, чл. 53', 'url' => $ppz, 'note' => 'Срок рассмотрения — до 2 месяцев, может быть продлён ещё на 2 месяца.', 'verified_at' => $v ),
			'ppz_annex6'   => array( 'title' => 'ППЗЧРБ, Приложение № 6', 'url' => $ppz, 'note' => 'Форма заявления.', 'verified_at' => $v ),
			'iisda_22087'  => array( 'title' => 'Административная услуга 22087 (ИИСДА)', 'url' => $iisda, 'note' => 'Опубликованная процедура, такса, срок, место подачи. Последнее изменение услуги 30.09.2025.', 'verified_at' => $v ),
		);
	}

	/** @return array{title:string,url:string,note:string,verified_at:string}|null */
	public static function source( string $id ): ?array {
		return self::sources()[ $id ] ?? null;
	}
}
