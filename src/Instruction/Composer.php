<?php
/**
 * Сборка предварительного результата и персональной инструкции из
 * ответов, результата правил и версии шаблонов.
 *
 * Результат — структурированный документ (секции и блоки), а не HTML:
 * HtmlRenderer строит из него и страницу, и PDF, поэтому оба формата
 * всегда совпадают. Документ собирается один раз при создании снимка
 * и хранится в нём неизменным.
 *
 * Блоки: p {text}, note {text}, list {ordered, items}, table {head, rows},
 * links {items: [{title, url, note}]}. Весь текст — простой текст,
 * экранируется при выводе.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Instruction;

use Bginfo\PmzhDvzh\Questionnaire\Labels;
use Bginfo\PmzhDvzh\Rules\AbsenceCalculator;
use Bginfo\PmzhDvzh\Rules\DateMath;
use Bginfo\PmzhDvzh\Rules\RulesMeta;
use Bginfo\PmzhDvzh\Rules\ResidenceTimeline;

final class Composer {

	/**
	 * @param array<string,mixed> $templates Набор шаблонов (DefaultTemplates::get() или версия из админки).
	 * @param array<string,mixed> $settings  fee_text, fee_verified_at, income_guide_text, income_verified_at.
	 */
	public function __construct( private array $templates, private array $settings = array() ) {
	}

	/**
	 * Бесплатная часть: статус и 1–3 пояснения. Ничего из платной
	 * инструкции сюда не попадает.
	 *
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	public function preview( array $result ): array {
		$reasons = array();

		foreach ( \Bginfo\PmzhDvzh\Rules\Evaluator::previewFindings( $result, 6 ) as $f ) {
			$text = $this->text( $f, 'short' );

			if ( '' !== $text && count( $reasons ) < 3 ) {
				$reasons[] = $text;
			}
		}

		$status = (string) $result['status'];

		return array(
			'status'       => $status,
			'title'        => (string) ( $this->templates['status_titles'][ $status ] ?? '' ),
			'intro'        => (string) ( $this->templates['status_intro'][ $status ] ?? '' ),
			'reasons'      => $reasons,
			'calc_date'    => DateMath::human( DateMath::parse( (string) $result['calc_date'] ) ),
			'filing_date'  => DateMath::human( DateMath::parse( (string) $result['filing_date'] ) ),
			'filing_is_default' => (bool) $result['filing_is_default'],
			'disclaimer'   => (string) ( $this->templates['disclaimer'] ?? '' ),
			'offer'        => $this->offer( $status ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function offer( string $status ): array {
		$o = (array) ( $this->templates['offer'] ?? array() );

		return array(
			'title'  => (string) ( $o['title'] ?? '' ),
			'lead'   => (string) ( $o['lead'] ?? '' ),
			'items'  => array_values( array_map( 'strval', (array) ( $o['items'] ?? array() ) ) ),
			'caveat' => (string) ( $o[ 'caveat_' . $status ] ?? '' ),
		);
	}

	/**
	 * Полная персональная инструкция.
	 *
	 * @param array<string,mixed> $answers
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	public function instruction( array $answers, array $result, string $templatesVersion ): array {
		$status   = (string) $result['status'];
		$findings = (array) $result['findings'];

		$sections = array(
			$this->sectionSummary( $answers, $result ),
			$this->sectionConclusion( $status, $findings ),
			$this->sectionResidence( $answers, $result ),
			$this->sectionDocuments( $answers, $result ),
			$this->sectionSteps( $answers, $result ),
			$this->sectionQuestions( $findings ),
			$this->sectionIssues( $findings ),
			$this->sectionSources( $findings, $result, $templatesVersion ),
		);

		return array(
			'title'             => 'Персональная инструкция: переход с ПМЖ на ДВЖ в Болгарии',
			'status'            => $status,
			'status_title'      => (string) ( $this->templates['status_titles'][ $status ] ?? '' ),
			'calc_date'         => (string) $result['calc_date'],
			'filing_date'       => (string) $result['filing_date'],
			'rules_version'     => (string) $result['rules_version'],
			'templates_version' => $templatesVersion,
			'sections'          => $sections,
		);
	}

	// ---------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	private function sectionSummary( array $a, array $r ): array {
		$ap = (array) ( $a['applicant'] ?? array() );
		$pm = (array) ( $a['pmzh'] ?? array() );
		$ho = (array) ( $a['housing'] ?? array() );
		$he = (array) ( $a['health'] ?? array() );
		$fu = (array) ( $a['funds'] ?? array() );

		$citizenship = trim( (string) ( $ap['citizenship'] ?? '' ) );
		$other       = trim( (string) ( $ap['other_citizenships'] ?? '' ) );

		$pmDate = 'unknown' === ( $pm['date_precision'] ?? 'unknown' )
			? 'нет точных данных'
			: DateMath::human( DateMath::parse( (string) ( $pm['decision_date'] ?? '' ) ) ) . ( 'approx' === ( $pm['date_precision'] ?? '' ) ? ' (примерно)' : '' );

		$basis = trim( (string) ( $pm['basis'] ?? '' ) . ' ' . (string) ( $pm['basis_article'] ?? '' ) );

		$health = 'yes' === ( $he['nzok'] ?? '' )
			? 'Застрахован(а) по ЗЗО; взносы: ' . Labels::label( 'contributions', (string) ( $he['contributions'] ?? 'unknown' ) )
			: Labels::label( 'yes_no_unknown', (string) ( $he['nzok'] ?? 'unknown' ) ) . ' (ЗЗО); другое покрытие: ' . Labels::label( 'other_cover', (string) ( $he['other_cover'] ?? 'unknown' ) );

		$income = Labels::labels( 'income_sources', array_map( 'strval', (array) ( $fu['income_sources'] ?? array() ) ) );

		if ( isset( $fu['income_amount'] ) && null !== $fu['income_amount'] ) {
			$income .= sprintf( '; около %s %s в месяц', number_format( (float) $fu['income_amount'], 2, ',', ' ' ), (string) ( $fu['income_currency'] ?? '' ) );
		}

		$rows = array(
			array( 'Дата расчёта', DateMath::human( DateMath::parse( (string) $r['calc_date'] ) ) ),
			array( 'Дата подачи для расчёта', DateMath::human( DateMath::parse( (string) $r['filing_date'] ) ) . ( $r['filing_is_default'] ? ' (дата не указана — взята дата расчёта)' : '' ) ),
			array( 'Проверяемый пятилетний период', DateMath::human( DateMath::parse( (string) $r['window_start'] ) ) . ' — ' . DateMath::human( DateMath::addDays( DateMath::parse( (string) $r['filing_date'] ) ?? DateMath::make( 2026, 1, 1 ), -1 ) ) ),
			array( 'Гражданство', '' !== $citizenship ? $citizenship : '—' ),
			array( 'Другие гражданства', '' !== $other ? $other : 'нет' ),
			array( 'Гражданство ЕС / Болгарии', Labels::label( 'eu_status', (string) ( $ap['eu_status'] ?? 'unknown' ) ) ),
			array( 'Возраст', isset( $ap['age'] ) && null !== $ap['age'] ? (string) $ap['age'] : 'не указан' ),
			array( 'ПМЖ: дата решения', $pmDate ),
			array( 'ПМЖ: основание', '' !== $basis ? $basis : 'не указано' ),
			array( 'Место проживания', trim( (string) ( $ho['region'] ?? '' ) ) ?: 'не указано' ),
			array( 'Жильё', Labels::label( 'tenure', (string) ( $ho['tenure'] ?? 'unknown' ) ) . '; документы: ' . Labels::labels( 'housing_proofs', array_map( 'strval', (array) ( $ho['proofs'] ?? array() ) ) ) ),
			array( 'Медицинское покрытие', $health ),
			array( 'Доход', $income ),
			array( 'Иждивенцы', isset( $fu['dependents'] ) && null !== $fu['dependents'] ? (string) $fu['dependents'] : 'не указано' ),
		);

		return array(
			'id'     => 'summary',
			'title'  => '1. Сведения кандидата',
			'blocks' => array(
				array( 'type' => 'p', 'text' => 'Инструкция составлена по ответам одного кандидата. Сведения не проверялись по документам.' ),
				array( 'type' => 'table', 'head' => array( 'Параметр', 'Значение' ), 'rows' => $rows ),
			),
		);
	}

	/**
	 * @param list<array<string,mixed>> $findings
	 * @return array<string,mixed>
	 */
	private function sectionConclusion( string $status, array $findings ): array {
		$blocks = array(
			array( 'type' => 'p', 'text' => (string) ( $this->templates['status_titles'][ $status ] ?? '' ) . '. ' . (string) ( $this->templates['status_intro'][ $status ] ?? '' ) ),
		);

		$reasons = array();

		foreach ( array( 'obstacle', 'check', 'ok' ) as $severity ) {
			foreach ( $findings as $f ) {
				if ( $f['severity'] === $severity ) {
					$text = $this->text( $f, 'detail' );

					if ( '' !== $text ) {
						$reasons[] = self::severityLabel( $severity ) . ': ' . $text;
					}
				}
			}
		}

		if ( ! empty( $reasons ) ) {
			$blocks[] = array( 'type' => 'list', 'ordered' => false, 'items' => $reasons );
		}

		$blocks[] = array( 'type' => 'note', 'text' => 'Ограничения: оценка основана только на ваших ответах; миграция проверяет законность и непрерывность пребывания по своим служебным справкам и документам. Сроки и суммы требований могут меняться.' );

		return array( 'id' => 'conclusion', 'title' => '2. Предварительный вывод', 'blocks' => $blocks );
	}

	/**
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	private function sectionResidence( array $a, array $r ): array {
		$blocks = array();

		// Статусы.
		$rows = array();
		$pm   = (array) ( $a['pmzh'] ?? array() );

		$rows[] = array(
			'Постоянное пребывание (ПМЖ)',
			trim( (string) ( $pm['basis'] ?? '' ) . ' ' . (string) ( $pm['basis_article'] ?? '' ) ) ?: '—',
			'unknown' === ( $pm['date_precision'] ?? 'unknown' ) ? 'нет данных' : DateMath::human( DateMath::parse( (string) ( $pm['decision_date'] ?? '' ) ) ),
			'действует',
		);

		foreach ( (array) ( $a['statuses'] ?? array() ) as $s ) {
			$unknown = 'unknown' === ( $s['precision'] ?? '' );
			$rows[]  = array(
				Labels::label( 'status_kind', (string) ( $s['kind'] ?? 'other' ) ),
				trim( (string) ( $s['basis'] ?? '' ) ) ?: '—',
				$unknown ? 'нет данных' : DateMath::human( DateMath::parse( (string) ( $s['from'] ?? '' ) ) ),
				'ended' === ( $s['end'] ?? '' ) && ! $unknown ? DateMath::human( DateMath::parse( (string) ( $s['to'] ?? '' ) ) ) : Labels::label( 'status_end', (string) ( $s['end'] ?? 'unknown' ) ),
			);
		}

		$blocks[] = array( 'type' => 'p', 'text' => 'История статусов (по датам решений, а не по срокам карт):' );
		$blocks[] = array( 'type' => 'table', 'head' => array( 'Статус', 'Основание', 'С', 'По' ), 'rows' => $rows );

		$uncovered = (array) ( $r['timeline']['uncovered'] ?? array() );

		if ( ! empty( $uncovered ) ) {
			$blocks[] = array(
				'type'    => 'list',
				'ordered' => false,
				'items'   => array_map(
					static fn( array $u ): string => 'Промежуток без подтверждённого статуса: ' . DateMath::human( DateMath::parse( (string) $u['from'] ) ) . ' — ' . DateMath::human( DateMath::addDays( DateMath::parse( (string) $u['to'] ) ?? DateMath::make( 2026, 1, 1 ), -1 ) ),
					$uncovered
				),
			);
		}

		// Поездки.
		$tripRows = array();
		$inputs   = array_values( (array) ( $a['trips'] ?? array() ) );

		foreach ( (array) ( $r['absences']['trips'] ?? array() ) as $row ) {
			$src        = $inputs[ $row['index'] ] ?? array();
			$tripRows[] = array(
				(string) ( $row['index'] + 1 ),
				'' !== $row['out'] ? DateMath::human( DateMath::parse( (string) $row['out'] ) ) . ( $row['approx'] ? ' (прим.)' : '' ) : 'нет данных',
				'' !== $row['back'] ? DateMath::human( DateMath::parse( (string) $row['back'] ) ) : 'нет данных',
				trim( (string) ( $src['countries'] ?? '' ) ) ?: '—',
				null === $row['nights'] ? '—' : (string) $row['nights'],
				null === $row['limit_date'] ? '—' : DateMath::human( DateMath::parse( (string) $row['limit_date'] ) ),
				AbsenceCalculator::classLabel( (string) $row['single_class'] ) . ( $row['crosses_window'] ? '; пересекает начало периода' : '' ),
			);
		}

		if ( empty( $tripRows ) ) {
			$blocks[] = array( 'type' => 'p', 'text' => 'yes' === ( $a['trips_any'] ?? '' ) ? 'Поездки не указаны.' : 'Вы указали, что не выезжали из Болгарии в проверяемый период (или не знаете).' );
		} else {
			$blocks[] = array( 'type' => 'p', 'text' => 'Поездки за пределы Болгарии, включая страны ЕС:' );
			$blocks[] = array( 'type' => 'table', 'head' => array( '№', 'Выезд', 'Возвращение', 'Страны', 'Сут.', '6 мес. истекают', 'Результат' ), 'rows' => $tripRows );
		}

		$t = (array) ( $r['absences']['total'] ?? array() );

		if ( ! empty( $t ) ) {
			$blocks[] = array(
				'type' => 'p',
				'text' => sprintf(
					'Сумма отсутствий в пятилетнем периоде: %d сут. (с учётом неясного счёта дней выезда и возвращения — от %d до %d). Предел — 10 календарных месяцев, это от %d до %d сут. Результат: %s.',
					(int) $t['nights'],
					(int) $t['lower'],
					(int) $t['upper'],
					(int) $t['limit_min'],
					(int) $t['limit_max'],
					AbsenceCalculator::classLabel( (string) $t['class'] )
				),
			);
		}

		$blocks[] = array( 'type' => 'note', 'text' => (string) ( $this->templates['method_note'] ?? '' ) );
		$blocks[] = array( 'type' => 'p', 'text' => 'Журнал расчёта:' );
		$blocks[] = array( 'type' => 'list', 'ordered' => false, 'items' => array_values( array_map( 'strval', (array) ( $r['log'] ?? array() ) ) ) );

		return array( 'id' => 'residence', 'title' => '3. Проживание и поездки', 'blocks' => $blocks );
	}

	/**
	 * Персональный перечень документов: [документ, состояние, комментарий].
	 *
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	private function sectionDocuments( array $a, array $r ): array {
		$have    = 'Есть';
		$need    = 'Подготовить';
		$clarify = 'Уточнить';
		$rows    = array();
		$filing  = DateMath::parse( (string) $r['filing_date'] );
		$codes   = array_column( (array) $r['findings'], 'code' );

		$rows[] = array( 'Заявление по образцу (Приложение № 6 к ППЗЧРБ)', $need, 'Заполняется и подаётся лично. Актуальную форму возьмите в подразделении или на сайте МВР.' );

		$passport = DateMath::parse( (string) ( $a['documents']['passport_until'] ?? '' ) );
		$rows[]   = array(
			'Действующий паспорт и его копия',
			null === $passport ? $clarify : ( null !== $filing && $passport > $filing ? $have : $need ),
			null === $passport ? 'Проверьте срок действия.' : 'Действует до ' . DateMath::human( $passport ) . '.',
		);

		$card   = DateMath::parse( (string) ( $a['documents']['pmzh_card_until'] ?? '' ) );
		$rows[] = array( 'Карта ПМЖ', null === $card ? $clarify : $have, null === $card ? 'Срок действия не указан.' : 'Действует до ' . DateMath::human( $card ) . '. Как оформляется сдача прежней карты, уточните до подачи.' );

		$decision = (string) ( $a['pmzh']['decision_doc'] ?? 'unknown' );
		$rows[]   = array( 'Решение о предоставлении ПМЖ', 'yes' === $decision ? $have : $clarify, 'yes' === $decision ? 'Пригодится для объяснения истории пребывания.' : 'Если не сохранилось — миграция получает сведения сама; при расхождениях запросите справку.' );

		// Жильё.
		$tenure = (string) ( $a['housing']['tenure'] ?? 'unknown' );
		$proofs = array_map( 'strval', (array) ( $a['housing']['proofs'] ?? array() ) );

		if ( 'own' === $tenure ) {
			$rows[] = array( 'Нотариальный акт на жильё', in_array( 'notarial_act', $proofs, true ) ? $have : $need, 'Подтверждение обеспеченного жилья.' );
		} elseif ( 'rent' === $tenure ) {
			$rows[] = array( 'Договор аренды', in_array( 'lease', $proofs, true ) ? $have : $need, 'Срок договора должен покрывать дату подачи.' );
			$rows[] = array( 'Декларация собственника', in_array( 'owner_declaration', $proofs, true ) ? $have : $need, 'По опубликованной процедуре — с нотариальным заверением; форму уточните в подразделении.' );
		} elseif ( in_array( 'owner_declaration', $proofs, true ) ) {
			$rows[] = array( 'Декларация владельца жилья', $have, 'По опубликованной процедуре — с нотариальным заверением; форму уточните в подразделении.' );
		} else {
			$rows[] = array( 'Документ о праве пользования жильём', $clarify, 'Для жилья родственников или иного основания уточните, какой документ подходит (как правило, декларация собственника с нотариальным заверением).' );
		}

		// Медицина.
		if ( 'yes' === ( $a['health']['nzok'] ?? '' ) ) {
			$rows[] = array( 'Подтверждение здравного осигуряване по ЗЗО', 'ok' === ( $a['health']['contributions'] ?? '' ) ? $need : $clarify, 'Например, справка НАП. Отдельный полис нужен только если вы не застрахованы по ЗЗО.' );
		} elseif ( 'policy' === ( $a['health']['other_cover'] ?? '' ) ) {
			$rows[] = array( 'Медицинская страховка, действующая в Болгарии', in_array( 'HEALTH_POLICY_OK', $codes, true ) ? $have : $clarify, 'Срок и покрытие уточните в подразделении.' );
		} else {
			$rows[] = array( 'Медицинское страхование по ЗЗО или страховка', $need, 'Обязательное условие.' );
		}

		// Средства.
		$incomeProofs = array_map( 'strval', (array) ( $a['funds']['income_proofs'] ?? array() ) );
		$hasProofs    = ! empty( array_diff( $incomeProofs, array( 'none' ) ) );
		$rows[]       = array(
			'Документы о стабильных и регулярных средствах на срок не меньше года',
			$hasProofs ? $have : $need,
			$hasProofs ? 'Есть: ' . Labels::labels( 'income_proofs', array_values( array_diff( $incomeProofs, array( 'none' ) ) ) ) . '.' : 'Договор, справки о доходе, решение о пенсии, налоговая декларация, выписки с регулярными поступлениями.',
		);

		if ( 'yes' === ( $a['funds']['savings'] ?? '' ) ) {
			$rows[] = array( 'Банковская справка и выписка по счёту', $need, 'Дополняет, но не заменяет подтверждение регулярного дохода.' );
		}

		// Особые случаи.
		if ( in_array( 'SPEC_BLUE_CARD', $codes, true ) ) {
			$rows[] = array( 'Документы о пребывании по Синей карте ЕС (в т. ч. из других стран ЕС)', $need, 'Чл. 52 ППЗЧРБ.' );
		}

		if ( in_array( 'SPEC_INTL_PROTECTION', $codes, true ) ) {
			$rows[] = array( 'Копия решения ДАБ о предоставлении международной защиты', $need, 'Чл. 51, ал. 2, т. 6 ППЗЧРБ.' );
		}

		if ( in_array( 'SPEC_STUDY', $codes, true ) ) {
			$rows[] = array( 'Решения и карты по разрешению для обучения (ст. 24в)', $clarify, 'Нужны для расчёта половинного зачёта.' );
		}

		$tripsComplete = 'no' === ( $a['trips_any'] ?? '' ) || 'complete' === ( $a['trips_complete'] ?? '' );
		$rows[]        = array( 'Собственная хронология статусов и поездок с подтверждениями', $tripsComplete ? $have : $need, 'Для предварительной проверки и объяснения расхождений; миграция получает служебные справки сама.' );

		$feeText = trim( (string) ( $this->settings['fee_text'] ?? '' ) );
		$rows[]  = array( 'Документ об оплате государственной таксы', $clarify, '' !== $feeText ? $feeText : 'Размер и момент оплаты уточните в подразделении.' );

		return array(
			'id'     => 'documents',
			'title'  => '4. Персональный перечень документов',
			'blocks' => array(
				array( 'type' => 'table', 'head' => array( 'Документ', 'Состояние', 'Комментарий' ), 'rows' => $rows ),
				array( 'type' => 'note', 'text' => 'Точную форму декларации, требования к заверению и переводам, а также состав финансовых документов согласуйте с подразделением, которое будет принимать заявление.' ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	private function sectionSteps( array $a, array $r ): array {
		$steps    = array();
		$findings = (array) $r['findings'];
		$region   = trim( (string) ( $a['housing']['region'] ?? '' ) );

		$obstacleActions = $this->actions( $findings, 'obstacle' );
		$checkActions    = $this->actions( $findings, 'check' );

		if ( ! empty( $obstacleActions ) ) {
			$steps[] = 'Сначала устраните препятствия: ' . implode( ' ', $obstacleActions );
		}

		if ( ! empty( $checkActions ) ) {
			$steps[] = 'Восстановите и уточните сведения: ' . implode( ' ', $checkActions );
		}

		$steps[] = 'Уточните в подразделении миграции' . ( '' !== $region ? ' по месту проживания (' . $region . ')' : ' по месту проживания' ) . ' актуальный перечень документов, форму декларации собственника и требования к заверению и переводам.';
		$steps[] = 'Соберите документы из раздела 4: сначала те, что отмечены «Подготовить», затем «Уточнить».';
		$steps[] = sprintf(
			'Выберите дату подачи. Расчёт сделан на %s; при другой дате пересчитайте пятилетний период и поездки. Не планируйте до подачи поездки, которые могут нарушить пределы отсутствий.',
			DateMath::human( DateMath::parse( (string) $r['filing_date'] ) )
		);
		$steps[] = 'Подайте заявление лично в дирекцию «Миграция» или отдел «Миграция» ОДМВР и получите подтверждение приёма.';
		$steps[] = 'Следите за запросами дополнительных документов и отвечайте в указанный срок. Срок рассмотрения — до двух месяцев, при сложности может быть продлён ещё на два месяца.';
		$steps[] = 'После положительного решения оплатите таксу и оформите разрешение долгосрочно пребывающего. Сам статус постоянный; пятилетний срок относится к документу, который продлевается.';
		$steps[] = 'Не подавайте заранее отказ от ПМЖ: такого этапа в перечне документов нет. Порядок оформления прежнего статуса и сдачи карты выясните до подачи.';

		return array(
			'id'     => 'steps',
			'title'  => '5. Последовательность действий',
			'blocks' => array( array( 'type' => 'list', 'ordered' => true, 'items' => $steps ) ),
		);
	}

	/**
	 * @param list<array<string,mixed>> $findings
	 * @return array<string,mixed>
	 */
	private function sectionQuestions( array $findings ): array {
		$questions = array();

		foreach ( $findings as $f ) {
			if ( 'info' === $f['severity'] && 'DOC_PMZH_CARD' !== $f['code'] ) {
				continue;
			}

			$q = $this->text( $f, 'question' );

			if ( '' !== $q ) {
				$questions[ $q ] = true;
			}
		}

		foreach ( array(
			'Какие документы о доходе и средствах принимаются в моей ситуации?',
			'Какая декларация собственника нужна при аренде и требуется ли нотариальное заверение?',
			'Нужны ли дополнительные документы по истории моего пребывания?',
			'Как оформляется изменение статуса после положительного решения и сдача прежней карты ПМЖ?',
			'Как согласовать дату подачи со сроками карты ПМЖ и паспорта?',
		) as $q ) {
			$questions[ $q ] = true;
		}

		return array(
			'id'     => 'questions',
			'title'  => '6. Вопросы для подразделения миграции',
			'blocks' => array( array( 'type' => 'list', 'ordered' => false, 'items' => array_keys( $questions ) ) ),
		);
	}

	/**
	 * @param list<array<string,mixed>> $findings
	 * @return array<string,mixed>
	 */
	private function sectionIssues( array $findings ): array {
		$items = array();

		foreach ( array( 'obstacle', 'check' ) as $severity ) {
			foreach ( $findings as $f ) {
				if ( $f['severity'] !== $severity ) {
					continue;
				}

				$short  = $this->text( $f, 'short' );
				$action = $this->text( $f, 'action' );

				if ( '' === $short && '' === $action ) {
					continue;
				}

				$items[] = self::severityLabel( $severity ) . ': ' . $short . ( '' !== $action ? ' Что сделать: ' . $action : '' );
			}
		}

		$blocks = empty( $items )
			? array( array( 'type' => 'p', 'text' => 'По вашим ответам препятствий и неизвестных сведений не выявлено. Всё равно сверьте данные с документами перед подачей.' ) )
			: array(
				array( 'type' => 'list', 'ordered' => true, 'items' => $items ),
				array( 'type' => 'p', 'text' => 'Когда уточните сведения, пройдите анкету заново с новыми данными, чтобы получить обновлённую предварительную оценку.' ),
			);

		return array( 'id' => 'issues', 'title' => '7. Препятствия, неизвестные сведения и дальнейшие действия', 'blocks' => $blocks );
	}

	/**
	 * @param list<array<string,mixed>> $findings
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	private function sectionSources( array $findings, array $r, string $templatesVersion ): array {
		$ids = array( 'zchrb_24g_1', 'zchrb_24g_8', 'zchrb_24g_9', 'zchrb_24d', 'ppz_51', 'ppz_53', 'ppz_annex6', 'iisda_22087' );

		foreach ( $findings as $f ) {
			foreach ( (array) $f['sources'] as $id ) {
				$ids[] = (string) $id;
			}
		}

		$links = array();

		foreach ( array_unique( $ids ) as $id ) {
			$s = RulesMeta::source( $id );

			if ( null !== $s ) {
				$links[] = array( 'title' => $s['title'], 'url' => $s['url'], 'note' => $s['note'] . ' Проверено: ' . DateMath::human( DateMath::parse( $s['verified_at'] ) ) . '.' );
			}
		}

		$links[] = array(
			'title' => 'МВР: разрешения за пребиваване (формы и инструкции)',
			'url'   => 'https://www.mvr.bg/migration/административни-услуги/административни-услуги-за-граждани/разрешения-за-пребиваване',
			'note'  => 'Официальные материалы и формы.',
		);

		$blocks = array( array( 'type' => 'links', 'items' => $links ) );

		$fee = trim( (string) ( $this->settings['fee_text'] ?? '' ) );

		if ( '' !== $fee ) {
			$blocks[] = array( 'type' => 'p', 'text' => 'Такса: ' . $fee . ( '' !== (string) ( $this->settings['fee_verified_at'] ?? '' ) ? ' (актуально на ' . DateMath::human( DateMath::parse( (string) $this->settings['fee_verified_at'] ) ) . ')' : '' ) . '.' );
		}

		$guide = trim( (string) ( $this->settings['income_guide_text'] ?? '' ) );

		if ( '' !== $guide ) {
			$blocks[] = array( 'type' => 'p', 'text' => 'Ориентир по средствам: ' . $guide . ( '' !== (string) ( $this->settings['income_verified_at'] ?? '' ) ? ' (актуально на ' . DateMath::human( DateMath::parse( (string) $this->settings['income_verified_at'] ) ) . ')' : '' ) . '.' );
		}

		$blocks[] = array(
			'type' => 'note',
			'text' => sprintf(
				'%s Версия правил: %s (источники проверены %s). Версия шаблонов: %s.',
				(string) ( $this->templates['disclaimer'] ?? '' ),
				(string) $r['rules_version'],
				DateMath::human( DateMath::parse( (string) $r['verified_at'] ) ),
				$templatesVersion
			),
		);

		return array( 'id' => 'sources', 'title' => '8. Официальные источники и оговорка', 'blocks' => $blocks );
	}

	/**
	 * @param list<array<string,mixed>> $findings
	 * @return list<string>
	 */
	private function actions( array $findings, string $severity ): array {
		$out = array();

		foreach ( $findings as $f ) {
			if ( $f['severity'] === $severity ) {
				$text = $this->text( $f, 'action' );

				if ( '' !== $text ) {
					$out[ $text ] = $text;
				}
			}
		}

		return array_values( $out );
	}

	/**
	 * Текст шаблона для finding с подстановкой параметров.
	 *
	 * @param array<string,mixed> $finding
	 */
	public function text( array $finding, string $kind ): string {
		$tpl = (string) ( $this->templates['findings'][ $finding['code'] ][ $kind ] ?? '' );

		if ( '' === $tpl ) {
			return '';
		}

		$replace = array();

		foreach ( (array) $finding['params'] as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$replace[ '{' . $k . '}' ] = is_bool( $v ) ? ( $v ? 'да' : 'нет' ) : (string) $v;
			}
		}

		// Неподставленные плейсхолдеры не показываем.
		return trim( (string) preg_replace( '/\{[a-z_]+\}/', '—', strtr( $tpl, $replace ) ) );
	}

	private static function severityLabel( string $severity ): string {
		return match ( $severity ) {
			'obstacle' => 'Препятствие',
			'check'    => 'Нужно проверить',
			'ok'       => 'Подтверждается',
			default    => 'Примечание',
		};
	}
}
