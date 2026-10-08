<?php
/**
 * Покрытие пятилетнего окна законным пребыванием по истории статусов.
 *
 * История строится по СТАТУСАМ (решение о предоставлении → прекращение),
 * а не по срокам карт: окончание срока карты ПМЖ не создаёт разрыв.
 * Неизвестный промежуток означает «нужна проверка», а не препятствие.
 * Препятствием считается только разрыв, который человек подтвердил
 * сам (указал период без разрешения), если он попадает в окно.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Rules;

use DateTimeImmutable;

final class ResidenceTimeline {

	/** Виды статусов, требующие отдельной логики зачёта. */
	public const SPECIAL_KINDS = array( 'study_24v', 'blue_card', 'temp_protection', 'intl_protection', 'other' );

	/** Постоянные статусы: «ongoing» до прекращения, независимо от карты. */
	public const PERMANENT_KINDS = array( 'pmzh' );

	/** Промежуток между статусами, который не считается разрывом (смена в соседний день). */
	public const ADJACENT_DAYS = 1;

	/**
	 * @param list<array{kind:string,from:?DateTimeImmutable,to:?DateTimeImmutable,end:string,approx:bool}> $statuses
	 *        end: 'ongoing' | 'ended' | 'unknown'
	 * @param list<array{from:?DateTimeImmutable,to:?DateTimeImmutable}> $declaredGaps Периоды без разрешения со слов человека.
	 * @param bool $historyComplete Человек подтвердил, что перечислил все статусы с первого приезда.
	 *
	 * @return array<string,mixed>
	 */
	public function analyse(
		array $statuses,
		array $declaredGaps,
		bool $historyComplete,
		DateTimeImmutable $windowStart,
		DateTimeImmutable $filing
	): array {
		$log          = array();
		$intervals    = array();
		$unknownEnds  = array();
		$special      = array();
		$approxNearW0 = false;
		$earliest     = null;

		foreach ( $statuses as $i => $s ) {
			$from = $s['from'];

			if ( null === $from ) {
				$log[] = sprintf( 'Статус %d (%s): дата решения не указана — период не может быть учтён.', $i + 1, self::kindLabel( $s['kind'] ) );
				$unknownEnds[] = $i;
				continue;
			}

			$earliest = null === $earliest ? $from : DateMath::min( $earliest, $from );

			if ( 'ended' === $s['end'] && null !== $s['to'] ) {
				$to = $s['to'];
			} elseif ( 'ongoing' === $s['end'] || in_array( $s['kind'], self::PERMANENT_KINDS, true ) && 'ended' !== $s['end'] ) {
				$to = $filing;
			} else {
				$to = null;
			}

			if ( null !== $to && $to < $from ) {
				$log[] = sprintf( 'Статус %d (%s): дата окончания раньше даты начала — период исключён.', $i + 1, self::kindLabel( $s['kind'] ) );
				$unknownEnds[] = $i;
				continue;
			}

			if ( $s['approx'] && abs( DateMath::daysBetween( $from, $windowStart ) ) <= AbsenceCalculator::APPROX_END_UNCERTAINTY ) {
				$approxNearW0 = true;
			}

			if ( null === $to ) {
				// Конец неизвестен — особый статус мог продолжаться в окне.
				if ( in_array( $s['kind'], self::SPECIAL_KINDS, true ) && $from < $filing ) {
					$special[ $s['kind'] ] = true;
				}

				$unknownEnds[] = $i;
				$log[]         = sprintf( 'Статус %d (%s) с %s: дата окончания неизвестна.', $i + 1, self::kindLabel( $s['kind'] ), DateMath::human( $from ) );
				// Начало известно — считаем, что статус действовал хотя бы
				// в день решения; хвост останется «неизвестным» промежутком.
				$to = $from;
			}

			$intervals[] = array( 'from' => $from, 'to' => $to, 'kind' => $s['kind'] );

			if ( in_array( $s['kind'], self::SPECIAL_KINDS, true ) && $to > $windowStart && $from < $filing ) {
				$special[ $s['kind'] ] = true;
			}

			$log[] = sprintf(
				'Статус %d: %s, с %s по %s%s.',
				$i + 1,
				self::kindLabel( $s['kind'] ),
				DateMath::human( $from ),
				'ended' === $s['end'] ? DateMath::human( $to ) : ( null === $s['to'] && 'ongoing' !== $s['end'] && ! in_array( $s['kind'], self::PERMANENT_KINDS, true ) ? 'неизвестно' : 'настоящее время' ),
				$s['approx'] ? ' (даты приблизительные)' : ''
			);
		}

		usort( $intervals, static fn( array $a, array $b ): int => $a['from'] <=> $b['from'] );

		// Непокрытые участки окна.
		$uncovered = array();
		$cursor    = $windowStart;

		foreach ( $intervals as $iv ) {
			if ( $iv['to'] <= $cursor ) {
				continue;
			}

			if ( $iv['from'] > $cursor && DateMath::daysBetween( $cursor, $iv['from'] ) > self::ADJACENT_DAYS ) {
				$uncovered[] = array( 'from' => $cursor, 'to' => DateMath::min( $iv['from'], $filing ) );
			}

			$cursor = DateMath::max( $cursor, $iv['to'] );

			if ( $cursor >= $filing ) {
				break;
			}
		}

		if ( $cursor < $filing && DateMath::daysBetween( $cursor, $filing ) > self::ADJACENT_DAYS ) {
			$uncovered[] = array( 'from' => $cursor, 'to' => $filing );
		}

		// Подтверждённые человеком разрывы внутри окна.
		$confirmedGapInWindow = false;
		$gapDatesUnknown      = false;

		foreach ( $declaredGaps as $g ) {
			if ( null === $g['from'] || null === $g['to'] ) {
				$gapDatesUnknown = true;
				continue;
			}

			if ( $g['to'] > $windowStart && $g['from'] < $filing ) {
				$confirmedGapInWindow = true;
				$log[] = sprintf( 'Указан период без разрешения: %s — %s, он попадает в окно проверки.', DateMath::human( $g['from'] ), DateMath::human( $g['to'] ) );
			} else {
				$log[] = sprintf( 'Указан период без разрешения: %s — %s, он вне окна проверки.', DateMath::human( $g['from'] ), DateMath::human( $g['to'] ) );
			}
		}

		// Недостаточный срок: самое раннее известное начало позже начала
		// окна, и человек подтвердил, что история полная.
		$insufficient    = false;
		$earliestFiling  = null;
		$headUncovered   = ! empty( $uncovered ) && $uncovered[0]['from'] == $windowStart; // phpcs:ignore

		if ( null !== $earliest && $earliest > $windowStart ) {
			$earliestFiling = DateMath::addYears( $earliest, 5 );

			if ( $historyComplete && ! $approxNearW0 ) {
				$insufficient = true;
			}
		}

		foreach ( $uncovered as $u ) {
			$log[] = sprintf(
				'Промежуток %s — %s в окне не подтверждён известными статусами.',
				DateMath::human( $u['from'] ),
				DateMath::human( DateMath::addDays( $u['to'], -1 ) )
			);
		}

		if ( $insufficient ) {
			$log[] = sprintf(
				'Самое раннее законное пребывание начинается %s — пяти лет к дате подачи нет. Расчётная ближайшая дата, когда пять лет истекут: %s (при отсутствии других препятствий).',
				DateMath::human( $earliest ),
				DateMath::human( $earliestFiling )
			);
		}

		return array(
			'uncovered'               => array_map(
				static fn( array $u ): array => array( 'from' => DateMath::format( $u['from'] ), 'to' => DateMath::format( $u['to'] ) ),
				$uncovered
			),
			'head_uncovered'          => $headUncovered,
			'insufficient'            => $insufficient,
			'earliest_start'          => DateMath::format( $earliest ),
			'earliest_filing'         => DateMath::format( $earliestFiling ),
			'unknown_status_indexes'  => $unknownEnds,
			'confirmed_gap_in_window' => $confirmedGapInWindow,
			'gap_dates_unknown'       => $gapDatesUnknown,
			'approx_near_window'      => $approxNearW0,
			'special_kinds'           => array_keys( $special ),
			'log'                     => $log,
		);
	}

	public static function kindLabel( string $kind ): string {
		return match ( $kind ) {
			'vnzh'            => 'продолжительное пребывание (ВНЖ)',
			'pmzh'            => 'постоянное пребывание (ПМЖ)',
			'study_24v'       => 'разрешение для обучения (ст. 24в)',
			'blue_card'       => 'Синяя карта ЕС',
			'temp_protection' => 'временная защита',
			'intl_protection' => 'международная защита',
			'other'           => 'иное основание',
			default           => 'статус',
		};
	}
}
