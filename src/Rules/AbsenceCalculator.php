<?php
/**
 * Расчёт отсутствий за пределами Болгарии — ПРЕДЛОЖЕННАЯ ТЕХНИЧЕСКАЯ
 * МЕТОДИКА (см. docs/ABSENCE_METHOD.md), а не утверждённое правило
 * подсчёта. Всё, что попадает в зону неопределённости методики,
 * классифицируется как «boundary» и уходит в дополнительную проверку.
 *
 * Принципы:
 * 1. Отсутствие = интервал [выезд, возвращение]. Длина каждого
 *    отсутствия для проверки «менее 6 последовательных месяцев» берётся
 *    ПО ПОЛНОМУ интервалу, даже если он начался до пятилетнего окна.
 * 2. Порог 6 месяцев — календарный: «дата выезда + 6 календарных
 *    месяцев» (с прижатием к концу месяца). Возвращение в эту дату или
 *    позже — уже не «менее шести месяцев».
 * 3. Не установлено, считаются ли дни выезда и возвращения днями
 *    пребывания. Поэтому вокруг порога есть зона ±N дней
 *    (N = 2 для точных дат: по одному дню на каждый конец интервала;
 *    N = 31 для дат, известных с точностью до месяца) — это boundary.
 * 4. Сумма за окно: интервалы объединяются (пересечения не считаются
 *    дважды) и обрезаются по окну [начало окна, дата подачи). Для суммы
 *    считается нижняя и верхняя оценка в сутках с учётом неясного счёта
 *    концевых дней. 10 календарных месяцев переводятся не в 300 дней,
 *    а в диапазон [минимум, максимум] реальных длин 10 месяцев
 *    (DateMath::monthSpanBounds). Верхняя оценка ниже минимума — норма,
 *    нижняя выше максимума — превышение, иначе boundary.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Rules;

use DateTimeImmutable;

final class AbsenceCalculator {

	public const SINGLE_LIMIT_MONTHS = 6;
	public const TOTAL_LIMIT_MONTHS  = 10;

	/** Неопределённость концов интервала в сутках для точных дат. */
	public const EXACT_END_UNCERTAINTY = 1;
	/** Для дат с точностью до месяца. */
	public const APPROX_END_UNCERTAINTY = 31;

	/**
	 * @param array<int,array{out:?DateTimeImmutable,back:?DateTimeImmutable,approx:bool,label?:string}> $trips
	 * @param DateTimeImmutable $windowStart Начало пятилетнего окна (включительно).
	 * @param DateTimeImmutable $filing      Дата подачи (не включительно).
	 *
	 * @return array{
	 *   trips: list<array<string,mixed>>,
	 *   invalid: list<int>,
	 *   overlaps: list<array{0:int,1:int}>,
	 *   total: array<string,mixed>,
	 *   log: list<string>
	 * }
	 */
	public function calculate( array $trips, DateTimeImmutable $windowStart, DateTimeImmutable $filing ): array {
		$log      = array();
		$rows     = array();
		$invalid  = array();
		$valid    = array();

		$log[] = sprintf(
			'Окно проверки: с %s по %s (не включая дату подачи).',
			DateMath::human( $windowStart ),
			DateMath::human( DateMath::addDays( $filing, -1 ) )
		);

		foreach ( $trips as $i => $trip ) {
			$out    = $trip['out'] ?? null;
			$back   = $trip['back'] ?? null;
			$approx = (bool) ( $trip['approx'] ?? false );
			$row    = array(
				'index'          => $i,
				'out'            => DateMath::format( $out ),
				'back'           => DateMath::format( $back ),
				'approx'         => $approx,
				'nights'         => null,
				'limit_date'     => null,
				'single_class'   => 'unknown',
				'crosses_window' => false,
				'crosses_window_possible' => false,
				'in_window'      => false,
				'window_nights'  => 0,
			);

			if ( null === $out || null === $back ) {
				$row['single_class'] = 'unknown';
				$invalid[]           = $i;
				$rows[]              = $row;
				$log[]               = sprintf( 'Поездка %d: даты не указаны полностью — длительность не рассчитана.', $i + 1 );
				continue;
			}

			if ( $back < $out ) {
				$row['single_class'] = 'invalid';
				$invalid[]           = $i;
				$rows[]              = $row;
				$log[]               = sprintf( 'Поездка %d: дата возвращения раньше даты выезда — исключена из расчёта.', $i + 1 );
				continue;
			}

			$nights = DateMath::daysBetween( $out, $back );
			$limit  = DateMath::addMonths( $out, self::SINGLE_LIMIT_MONTHS );
			$margin = $approx ? self::APPROX_END_UNCERTAINTY : 2 * self::EXACT_END_UNCERTAINTY;
			$delta  = DateMath::daysBetween( $limit, $back ); // < 0 — вернулся раньше порога.

			if ( $delta < -$margin ) {
				$class = 'ok';
			} elseif ( $delta > $margin ) {
				$class = 'exceeds';
			} else {
				$class = 'boundary';
			}

			$row['nights']         = $nights;
			$row['limit_date']     = DateMath::format( $limit );
			$row['single_class']   = $class;
			$row['crosses_window'] = $out < $windowStart && $back > $windowStart;
			$row['in_window']      = $back > $windowStart && $out < $filing;
			// Пересечение начала окна возможно в пределах неопределённости дат.
			$uTrip                           = $approx ? self::APPROX_END_UNCERTAINTY : self::EXACT_END_UNCERTAINTY;
			$row['crosses_window_possible'] = DateMath::addDays( $out, -$uTrip ) < $windowStart && DateMath::addDays( $back, $uTrip ) > $windowStart;

			$log[] = sprintf(
				'Поездка %d: %s — %s, %d сут. между датами; 6 календарных месяцев от выезда истекают %s; разница с порогом %+d сут., допуск методики ±%d → %s.%s',
				$i + 1,
				DateMath::human( $out ),
				DateMath::human( $back ),
				$nights,
				DateMath::human( $limit ),
				$delta,
				$margin,
				self::classLabel( $class ),
				$row['crosses_window'] ? ' Отсутствие пересекает начало окна: длительность взята по полному интервалу.' : ''
			);

			$rows[]  = $row;
			$valid[] = array( 'i' => $i, 'out' => $out, 'back' => $back, 'approx' => $approx );
		}

		// Пересечения между поездками (ошибка ввода или дублирование).
		$overlaps = array();
		$count    = count( $valid );

		for ( $a = 0; $a < $count; $a++ ) {
			for ( $b = $a + 1; $b < $count; $b++ ) {
				if ( $valid[ $a ]['out'] < $valid[ $b ]['back'] && $valid[ $b ]['out'] < $valid[ $a ]['back'] ) {
					$overlaps[] = array( $valid[ $a ]['i'], $valid[ $b ]['i'] );
				}
			}
		}

		foreach ( $overlaps as $pair ) {
			$log[] = sprintf( 'Поездки %d и %d пересекаются по датам — в сумме общий период учтён один раз.', $pair[0] + 1, $pair[1] + 1 );
		}

		$total = $this->total( $valid, $windowStart, $filing, $rows, $log );

		return array(
			'trips'    => $rows,
			'invalid'  => $invalid,
			'overlaps' => $overlaps,
			'total'    => $total,
			'log'      => $log,
		);
	}

	/**
	 * Сумма отсутствий в окне после объединения пересекающихся интервалов.
	 *
	 * @param list<array{i:int,out:DateTimeImmutable,back:DateTimeImmutable,approx:bool}> $valid
	 * @param list<array<string,mixed>> $rows
	 * @param list<string> $log
	 * @return array<string,mixed>
	 */
	private function total( array $valid, DateTimeImmutable $windowStart, DateTimeImmutable $filing, array &$rows, array &$log ): array {
		usort(
			$valid,
			static fn( array $x, array $y ): int => $x['out'] <=> $y['out']
		);

		// Объединение интервалов; неопределённость концов накапливаем по
		// исходным концам, попавшим в окно.
		$merged = array();

		foreach ( $valid as $v ) {
			$last = count( $merged ) - 1;

			if ( $last >= 0 && $v['out'] <= $merged[ $last ]['back'] ) {
				$merged[ $last ]['back']    = DateMath::max( $merged[ $last ]['back'], $v['back'] );
				$merged[ $last ]['approx']  = $merged[ $last ]['approx'] || $v['approx'];
				$merged[ $last ]['members'][] = $v['i'];
				continue;
			}

			$merged[] = array(
				'out'     => $v['out'],
				'back'    => $v['back'],
				'approx'  => $v['approx'],
				'members' => array( $v['i'] ),
			);
		}

		$base  = 0;
		$lower = 0;
		$upper = 0;

		foreach ( $merged as $m ) {
			$from = DateMath::max( $m['out'], $windowStart );
			$to   = DateMath::min( $m['back'], $filing );

			$u = $m['approx'] ? self::APPROX_END_UNCERTAINTY : self::EXACT_END_UNCERTAINTY;

			if ( $to <= $from ) {
				// Вычисленная часть в окне пуста, но при неточных датах возвращение
				// могло быть позже начала окна: верхняя оценка включает до u сут.
				if ( $m['back'] <= $windowStart ) {
					$possible = $u - DateMath::daysBetween( $m['back'], $windowStart );

					if ( $possible > 0 ) {
						$upper += $possible;
					}
				}

				continue;
			}

			$nights = DateMath::daysBetween( $from, $to );
			// Конец, обрезанный границей окна, не добавляет неопределённости
			// со своей стороны.
			$uOut  = $m['out'] >= $windowStart ? $u : 0;
			$uBack = $m['back'] <= $filing ? $u : 0;

			$base  += $nights;
			$lower += max( 0, $nights - $uOut - $uBack );
			$upper += $nights + $uOut + $uBack;

			foreach ( $m['members'] as $idx ) {
				foreach ( $rows as &$row ) {
					if ( $row['index'] === $idx ) {
						$row['window_nights'] = DateMath::daysBetween(
							DateMath::max( DateMath::parse( $row['out'] ) ?? $from, $windowStart ),
							DateMath::min( DateMath::parse( $row['back'] ) ?? $to, $filing )
						);
						$row['window_nights'] = max( 0, $row['window_nights'] );
					}
				}
				unset( $row );
			}
		}

		[ $minTen, $maxTen ] = DateMath::monthSpanBounds( self::TOTAL_LIMIT_MONTHS );

		if ( $upper < $minTen ) {
			$class = 'ok';
		} elseif ( $lower > $maxTen ) {
			$class = 'exceeds';
		} else {
			$class = 'boundary';
		}

		$log[] = sprintf(
			'Сумма отсутствий в окне: %d сут. (оценка с учётом неясного счёта дней выезда/возвращения: от %d до %d). 10 календарных месяцев в зависимости от начала отсчёта составляют от %d до %d сут. → %s.',
			$base,
			$lower,
			$upper,
			$minTen,
			$maxTen,
			self::classLabel( $class )
		);

		return array(
			'nights'      => $base,
			'lower'       => $lower,
			'upper'       => $upper,
			'limit_min'   => $minTen,
			'limit_max'   => $maxTen,
			'class'       => $class,
		);
	}

	public static function classLabel( string $class ): string {
		return match ( $class ) {
			'ok'       => 'в пределах',
			'exceeds'  => 'превышение',
			'boundary' => 'пограничный случай, нужна проверка',
			'invalid'  => 'ошибка в датах',
			default    => 'нет данных',
		};
	}
}
