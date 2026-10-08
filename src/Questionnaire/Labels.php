<?php
/**
 * Подписи значений перечислимых полей анкеты. Единый источник для
 * схемы анкеты (Schema), сводки, инструкции и админки.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Questionnaire;

final class Labels {

	/** @return array<string,array<string,string>> */
	public static function all(): array {
		return array(
			'yes_no_unknown'  => array( 'yes' => 'Да', 'no' => 'Нет', 'unknown' => 'Не знаю' ),
			'eu_status'       => array(
				'none'       => 'Нет, только гражданство(а) третьих стран',
				'eu_citizen' => 'Да, есть гражданство страны ЕС/ЕЭП/Швейцарии',
				'bg_citizen' => 'Да, есть гражданство Болгарии',
				'unknown'    => 'Не знаю',
			),
			'family'          => array(
				'self'   => 'Подаю только за себя',
				'family' => 'Есть вопросы и по членам семьи',
			),
			'precision'       => array(
				'exact'   => 'Точные даты',
				'approx'  => 'Примерно (известен месяц)',
				'unknown' => 'Нет точных данных',
			),
			'status_kind'     => array(
				'vnzh'            => 'Продолжительное пребывание (ВНЖ)',
				'study_24v'       => 'Разрешение для обучения (ст. 24в)',
				'blue_card'       => 'Синяя карта ЕС',
				'temp_protection' => 'Временная защита',
				'intl_protection' => 'Международная защита',
				'other'           => 'Иное основание',
			),
			'status_end'      => array(
				'ended'   => 'Закончился',
				'ongoing' => 'Действует сейчас',
				'unknown' => 'Не знаю',
			),
			'old_documents'   => array(
				'all'     => 'Да, сохранились все',
				'partial' => 'Сохранились частично',
				'none'    => 'Не сохранились',
				'unknown' => 'Не знаю',
			),
			'trips_complete'  => array(
				'complete' => 'Да, указаны все поездки',
				'partial'  => 'Указана только часть, остальные не помню',
				'unknown'  => 'Нет точных данных',
			),
			'protection'      => array(
				'none'          => 'Нет',
				'international' => 'Международная защита (статус беженца или гуманитарный статус)',
				'temporary'     => 'Временная защита',
				'unknown'       => 'Не знаю',
			),
			'other_eu_status' => array(
				'none'      => 'Нет',
				'residence' => 'Есть разрешение на проживание в другой стране ЕС',
				'ltr'       => 'Есть статус долгосрочно пребывающего в другой стране ЕС',
				'unknown'   => 'Не знаю',
			),
			'tenure'          => array(
				'own'     => 'Собственное жильё',
				'rent'    => 'Аренда',
				'family'  => 'Жильё родственников / предоставлено бесплатно',
				'other'   => 'Иное',
				'unknown' => 'Не знаю',
			),
			'housing_proofs'  => array(
				'notarial_act'         => 'Нотариальный акт',
				'lease'                => 'Договор аренды',
				'owner_declaration'    => 'Декларация собственника',
				'address_registration' => 'Адресная регистрация',
				'none'                 => 'Нет документов',
				'unknown'              => 'Не знаю',
			),
			'contributions'   => array(
				'ok'      => 'Взносы платятся без перерывов',
				'gaps'    => 'Есть перерывы или задолженность',
				'unknown' => 'Не знаю',
			),
			'other_cover'     => array(
				'none'    => 'Нет',
				'policy'  => 'Медицинская страховка (полис)',
				'other'   => 'Другой режим покрытия',
				'unknown' => 'Не знаю',
			),
			'income_sources'  => array(
				'salary'   => 'Зарплата',
				'pension'  => 'Пенсия',
				'business' => 'Доход от бизнеса / самозанятости',
				'rental'   => 'Доход от аренды',
				'other'    => 'Другой регулярный доход',
				'none'     => 'Регулярного дохода нет',
				'unknown'  => 'Не знаю',
			),
			'income_proofs'   => array(
				'employment_contract' => 'Трудовой договор',
				'payslips'            => 'Справки о доходе / расчётные листы',
				'pension_decision'    => 'Решение о пенсии / справка о пенсии',
				'tax_return'          => 'Налоговая декларация',
				'bank_statements'     => 'Банковские выписки с поступлениями',
				'other'               => 'Другие документы',
				'none'                => 'Подтверждений пока нет',
			),
			'currency'        => array(
				'EUR'   => 'EUR',
				'BGN'   => 'BGN',
				'other' => 'Другая валюта',
			),
			'bank_country'    => array(
				''      => 'Не указано',
				'bg'    => 'Болгария',
				'eu'    => 'Другая страна ЕС',
				'other' => 'Страна вне ЕС',
			),
		);
	}

	public static function label( string $group, string $value ): string {
		return self::all()[ $group ][ $value ] ?? $value;
	}

	/** @param list<string> $values */
	public static function labels( string $group, array $values ): string {
		if ( empty( $values ) ) {
			return '—';
		}

		return implode( ', ', array_map( static fn( string $v ): string => self::label( $group, $v ), $values ) );
	}

	/** @return list<string> */
	public static function keys( string $group ): array {
		return array_map( 'strval', array_keys( self::all()[ $group ] ?? array() ) );
	}
}
