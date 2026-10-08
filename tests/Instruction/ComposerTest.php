<?php
/**
 * Тесты сборки предварительного результата и персональной инструкции.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Instruction;

use Bginfo\PmzhDvzh\Instruction\Composer;
use Bginfo\PmzhDvzh\Instruction\DefaultTemplates;
use Bginfo\PmzhDvzh\Rules\Evaluator;
use Bginfo\PmzhDvzh\Tests\Rules\AnswersFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposerTest extends TestCase {

	private const SETTINGS = array(
		'fee_text'           => '40 EUR',
		'fee_verified_at'    => '2026-10-08',
		'income_guide_text'  => 'ориентир 1000 EUR',
		'income_verified_at' => '2026-10-08',
	);

	private function composer(): Composer {
		return new Composer( DefaultTemplates::get(), self::SETTINGS );
	}

	/** @return array<string,mixed> */
	private static function evaluate( array $answers ): array {
		return ( new Evaluator() )->evaluate( $answers, AnswersFactory::config() );
	}

	/** @return array<string,mixed> */
	private function instruction( array $answers ): array {
		return $this->composer()->instruction( $answers, self::evaluate( $answers ), 'tpl-1' );
	}

	/** @return array<string,mixed> */
	private static function section( array $instruction, string $id ): array {
		foreach ( $instruction['sections'] as $s ) {
			if ( $id === $s['id'] ) {
				return $s;
			}
		}

		self::fail( 'нет секции ' . $id );
	}

	/** @return list<array<string,mixed>> */
	private static function blocks( array $section, string $type ): array {
		return array_values( array_filter( $section['blocks'], static fn( array $b ): bool => $type === $b['type'] ) );
	}

	/** @return list<string> Все строки структуры (ключи не учитываются). */
	private static function strings( mixed $node ): array {
		if ( is_string( $node ) ) {
			return array( $node );
		}

		$out = array();

		if ( is_array( $node ) ) {
			foreach ( $node as $v ) {
				$out = array_merge( $out, self::strings( $v ) );
			}
		}

		return $out;
	}

	private static function documentRow( array $instruction, string $title ): ?array {
		$table = self::blocks( self::section( $instruction, 'documents' ), 'table' )[0];

		foreach ( $table['rows'] as $row ) {
			if ( $title === $row[0] ) {
				return $row;
			}
		}

		return null;
	}

	// --- Предварительный результат ------------------------------------------

	public function testPreviewHasOnlyAllowedKeys(): void {
		$answers = AnswersFactory::with( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'none' ) ) );
		$preview = $this->composer()->preview( self::evaluate( $answers ) );

		$keys = array_keys( $preview );
		sort( $keys );
		$this->assertSame(
			array( 'calc_date', 'disclaimer', 'filing_date', 'filing_is_default', 'intro', 'offer', 'reasons', 'status', 'title' ),
			$keys
		);
		$this->assertSame( 'obstacle', $preview['status'] );
		$this->assertSame( 'Есть препятствия для подачи сейчас', $preview['title'] );
		$this->assertNotSame( '', $preview['intro'] );
		$this->assertNotSame( '', $preview['disclaimer'] );
		$this->assertSame( '08.10.2026', $preview['calc_date'] );
		$this->assertSame( '08.10.2026', $preview['filing_date'] );
		$this->assertTrue( $preview['filing_is_default'] );
		$this->assertSame( array( 'title', 'lead', 'items', 'caveat' ), array_keys( $preview['offer'] ) );
		$this->assertNotSame( '', $preview['offer']['caveat'] );
	}

	/** @return array<string,array{0:string}> */
	public static function scenarioNames(): array {
		$out = array();

		foreach ( array_keys( AnswersFactory::scenarioAnswers() ) as $name ) {
			$out[ $name ] = array( $name );
		}

		return $out;
	}

	#[DataProvider( 'scenarioNames' )]
	public function testPreviewReasonsAtMostThreeAndNoInstructionContent( string $name ): void {
		$answers     = AnswersFactory::scenarioAnswers()[ $name ];
		$result      = self::evaluate( $answers );
		$preview     = $this->composer()->preview( $result );
		$instruction = $this->composer()->instruction( $answers, $result, 'tpl-1' );

		$this->assertLessThanOrEqual( 3, count( $preview['reasons'] ) );
		$this->assertContains( $preview['status'], array( 'ok', 'check', 'obstacle' ) );

		$json = (string) json_encode( $preview, JSON_UNESCAPED_UNICODE );

		foreach ( $instruction['sections'] as $section ) {
			$this->assertStringNotContainsString( $section['title'], $json, "{$name}: заголовок раздела {$section['title']} попал в предпросмотр" );
		}

		// Журнал расчёта, таблицы и ссылки на источники принадлежат инструкции.
		foreach ( array( 'Журнал расчёта', 'lex.bg', 'Версия правил', 'Версия шаблонов', 'Что сделать:' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, $name );
		}

		foreach ( $preview['reasons'] as $reason ) {
			$this->assertNotSame( '', $reason );
		}
	}

	public function testPreviewForOkListsConfirmedItems(): void {
		$preview = $this->composer()->preview( self::evaluate( AnswersFactory::base() ) );
		$this->assertSame( 'ok', $preview['status'] );
		$this->assertNotEmpty( $preview['reasons'] );
		$this->assertLessThanOrEqual( 3, count( $preview['reasons'] ) );
	}

	public function testPreviewReasonsForObstacleStartWithTheObstacle(): void {
		$answers = AnswersFactory::with( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'none' ) ) );
		$preview = $this->composer()->preview( self::evaluate( $answers ) );
		$this->assertStringContainsString( 'медицинского страхования', $preview['reasons'][0] );
	}

	// --- Структура инструкции ----------------------------------------------

	public function testInstructionHasEightSectionsInOrder(): void {
		$i = $this->instruction( AnswersFactory::base() );

		$ids = array_column( $i['sections'], 'id' );
		$this->assertSame( array( 'summary', 'conclusion', 'residence', 'documents', 'steps', 'questions', 'issues', 'sources' ), $ids );
		$this->assertSame( 'ok', $i['status'] );
		$this->assertSame( 'tpl-1', $i['templates_version'] );
		$this->assertSame( '2026-10-08', $i['filing_date'] );

		foreach ( $i['sections'] as $s ) {
			$this->assertNotSame( '', $s['title'] );
			$this->assertNotEmpty( $s['blocks'], $s['id'] );
		}
	}

	#[DataProvider( 'scenarioNames' )]
	public function testNoUnsubstitutedPlaceholdersAnywhere( string $name ): void {
		$answers = AnswersFactory::scenarioAnswers()[ $name ];
		$result  = self::evaluate( $answers );

		$all = array_merge(
			self::strings( $this->composer()->preview( $result ) ),
			self::strings( $this->composer()->instruction( $answers, $result, 'tpl-1' ) )
		);

		foreach ( $all as $text ) {
			$this->assertStringNotContainsString( '{', $text, "{$name}: «{$text}»" );
			$this->assertStringNotContainsString( '}', $text, "{$name}: «{$text}»" );
		}
	}

	#[DataProvider( 'scenarioNames' )]
	public function testInstructionBuildsForEveryScenario( string $name ): void {
		$i = $this->instruction( AnswersFactory::scenarioAnswers()[ $name ] );
		$this->assertCount( 8, $i['sections'] );
		$this->assertContains( $i['status'], array( 'ok', 'check', 'obstacle' ) );
	}

	// --- Персонализация -----------------------------------------------------

	public function testRentWithoutOwnerDeclarationNeedsPreparing(): void {
		$i   = $this->instruction( AnswersFactory::with( array( 'housing' => array( 'tenure' => 'rent', 'proofs' => array( 'lease' ) ) ) ) );
		$row = self::documentRow( $i, 'Декларация собственника' );

		$this->assertNotNull( $row );
		$this->assertSame( 'Подготовить', $row[1] );
		$this->assertSame( 'Есть', self::documentRow( $i, 'Договор аренды' )[1] );
		$this->assertNull( self::documentRow( $i, 'Нотариальный акт на жильё' ) );
	}

	public function testRentWithOwnerDeclarationHasIt(): void {
		$i = $this->instruction( AnswersFactory::with( array( 'housing' => array( 'tenure' => 'rent', 'proofs' => array( 'lease', 'owner_declaration' ) ) ) ) );
		$this->assertSame( 'Есть', self::documentRow( $i, 'Декларация собственника' )[1] );
	}

	public function testOwnHousingAsksForNotarialAct(): void {
		$i = $this->instruction( AnswersFactory::with( array( 'housing' => array( 'tenure' => 'own', 'proofs' => array() ) ) ) );
		$this->assertSame( 'Подготовить', self::documentRow( $i, 'Нотариальный акт на жильё' )[1] );
		$this->assertNull( self::documentRow( $i, 'Декларация собственника' ) );
	}

	public function testNzokYesAsksForZzoConfirmationNotPolicy(): void {
		$i = $this->instruction( AnswersFactory::base() );

		$row = self::documentRow( $i, 'Подтверждение здравного осигуряване по ЗЗО' );
		$this->assertNotNull( $row );
		$this->assertSame( 'Подготовить', $row[1] );
		$this->assertStringContainsString( 'НАП', $row[2] );
		$this->assertNull( self::documentRow( $i, 'Медицинская страховка, действующая в Болгарии' ) );
		$this->assertNull( self::documentRow( $i, 'Медицинское страхование по ЗЗО или страховка' ) );
	}

	public function testPolicyHolderAsksForPolicy(): void {
		$i = $this->instruction( AnswersFactory::with( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'policy', 'cover_until' => '2028-01-01' ) ) ) );
		$this->assertSame( 'Есть', self::documentRow( $i, 'Медицинская страховка, действующая в Болгарии' )[1] );
		$this->assertNull( self::documentRow( $i, 'Подтверждение здравного осигуряване по ЗЗО' ) );
	}

	public function testNoCoverAsksForInsurance(): void {
		$i = $this->instruction( AnswersFactory::with( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'none' ) ) ) );
		$this->assertSame( 'Подготовить', self::documentRow( $i, 'Медицинское страхование по ЗЗО или страховка' )[1] );
	}

	public function testSavingsAddsBankStatementRow(): void {
		$i = $this->instruction( AnswersFactory::with( array( 'funds' => array( 'savings' => 'yes' ) ) ) );
		$this->assertNotNull( self::documentRow( $i, 'Банковская справка и выписка по счёту' ) );

		$i = $this->instruction( AnswersFactory::base() );
		$this->assertNull( self::documentRow( $i, 'Банковская справка и выписка по счёту' ) );
	}

	public function testSpecialRowsAppearOnlyForTheirFindings(): void {
		$i = $this->instruction( AnswersFactory::with( array( 'special' => array( 'blue_card' => 'yes' ) ) ) );
		$this->assertNotNull( self::documentRow( $i, 'Документы о пребывании по Синей карте ЕС (в т. ч. из других стран ЕС)' ) );

		$i = $this->instruction( AnswersFactory::base() );
		$this->assertNull( self::documentRow( $i, 'Документы о пребывании по Синей карте ЕС (в т. ч. из других стран ЕС)' ) );
	}

	public function testFeeFromSettingsAppearsInDocumentsAndSources(): void {
		$i = $this->instruction( AnswersFactory::base() );
		$this->assertSame( '40 EUR', self::documentRow( $i, 'Документ об оплате государственной таксы' )[2] );

		$texts = implode( "\n", self::strings( self::section( $i, 'sources' ) ) );
		$this->assertStringContainsString( 'Такса: 40 EUR (актуально на 08.10.2026)', $texts );
		$this->assertStringContainsString( 'Ориентир по средствам: ориентир 1000 EUR (актуально на 08.10.2026)', $texts );
	}

	public function testMissingSettingsAreHandled(): void {
		$answers = AnswersFactory::base();
		$i       = ( new Composer( DefaultTemplates::get() ) )->instruction( $answers, self::evaluate( $answers ), 'tpl-1' );
		$this->assertStringContainsString( 'уточните в подразделении', self::documentRow( $i, 'Документ об оплате государственной таксы' )[2] );
	}

	// --- Источники ----------------------------------------------------------

	public function testSourcesSectionHasLexLinksAndVerifiedDate(): void {
		$i       = $this->instruction( AnswersFactory::base() );
		$section = self::section( $i, 'sources' );
		$links   = self::blocks( $section, 'links' )[0]['items'];

		$lex = array_filter( $links, static fn( array $l ): bool => str_contains( $l['url'], 'lex.bg' ) );
		$this->assertNotEmpty( $lex );

		foreach ( $links as $l ) {
			$this->assertMatchesRegularExpression( '#^https://#', $l['url'] );
			$this->assertNotSame( '', $l['title'] );
		}

		$text = implode( "\n", self::strings( $section ) );
		$this->assertStringContainsString( 'Проверено: 08.10.2026', $text );
		$this->assertStringContainsString( 'источники проверены 08.10.2026', $text );
		$this->assertStringContainsString( 'Версия шаблонов: tpl-1', $text );
		$this->assertStringContainsString( 'чл. 24г, ал. 8', $text );
	}

	public function testSourcesIncludeFindingSourcesWithoutDuplicates(): void {
		$i     = $this->instruction( AnswersFactory::with( array( 'special' => array( 'blue_card' => 'yes' ) ) ) );
		$links = self::blocks( self::section( $i, 'sources' ), 'links' )[0]['items'];
		$titles = array_column( $links, 'title' );

		$this->assertContains( 'ЗЧРБ, чл. 33м', $titles );
		$this->assertContains( 'ППЗЧРБ, чл. 52', $titles );
		$this->assertSame( count( $titles ), count( array_unique( $titles ) ) );
	}

	// --- Тексты шаблонов ----------------------------------------------------

	public function testGapConfirmedQuestionHasNoBraces(): void {
		$answers = AnswersFactory::with( array( 'history' => array( 'gap_without_permit' => 'yes', 'gaps' => array( array( 'from' => '2023-05-10', 'to' => '2023-05-18' ) ) ) ) );
		$result  = self::evaluate( $answers );
		$finding = null;

		foreach ( $result['findings'] as $f ) {
			if ( 'RES_GAP_CONFIRMED' === $f['code'] ) {
				$finding = $f;
			}
		}

		$this->assertNotNull( $finding );
		$question = $this->composer()->text( $finding, 'question' );
		$this->assertNotSame( '', $question );
		$this->assertStringNotContainsString( '{', $question );
		$this->assertStringNotContainsString( '}', $question );

		$i = $this->composer()->instruction( $answers, $result, 'tpl-1' );
		$this->assertContains( $question, self::blocks( self::section( $i, 'questions' ), 'list' )[0]['items'] );
	}

	public function testTextSubstitutesParamsAndBlanksMissingOnes(): void {
		$c = $this->composer();
		$this->assertSame(
			'Дата подачи не указана — расчёт выполнен на 08.10.2026.',
			$c->text( array( 'code' => 'FILING_DATE_DEFAULT', 'params' => array( 'date' => '08.10.2026' ) ), 'short' )
		);
		$this->assertSame(
			'Дата подачи не указана — расчёт выполнен на —.',
			$c->text( array( 'code' => 'FILING_DATE_DEFAULT', 'params' => array() ), 'short' )
		);
		$this->assertSame( '', $c->text( array( 'code' => 'NO_SUCH_CODE', 'params' => array() ), 'short' ) );
	}

	public function testBooleanParamIsRenderedAsWords(): void {
		$c = new Composer( array( 'findings' => array( 'X' => array( 'short' => '{flag}/{other}' ) ) ) );
		$this->assertSame( 'да/нет', $c->text( array( 'code' => 'X', 'params' => array( 'flag' => true, 'other' => false ) ), 'short' ) );
	}

	public function testResidenceSectionContainsMethodNoteAndLog(): void {
		$i    = $this->instruction( AnswersFactory::base() );
		$text = implode( "\n", self::strings( self::section( $i, 'residence' ) ) );

		$this->assertStringContainsString( 'Как считались отсутствия', $text );
		$this->assertStringContainsString( 'Журнал расчёта', $text );
		$this->assertStringContainsString( 'Окно проверки', $text );
	}

	public function testStepsMentionObstaclesFirstWhenPresent(): void {
		$i     = $this->instruction( AnswersFactory::with( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'none' ) ) ) );
		$steps = self::blocks( self::section( $i, 'steps' ), 'list' )[0];

		$this->assertTrue( $steps['ordered'] );
		$this->assertStringStartsWith( 'Сначала устраните препятствия', $steps['items'][0] );
		$this->assertStringContainsString( 'София', implode( "\n", $steps['items'] ) );
	}

	// --- Бесплатный разбор по разделам -------------------------------------

	/** @return array<string,mixed> */
	private function analysisFor( array $answers ): array {
		$preview = $this->composer()->preview( self::evaluate( $answers ), $answers );
		$this->assertArrayHasKey( 'analysis', $preview );

		return $preview['analysis'];
	}

	public function testPreviewWithoutAnswersHasNoAnalysis(): void {
		$preview = $this->composer()->preview( self::evaluate( AnswersFactory::base() ) );
		$this->assertArrayNotHasKey( 'analysis', $preview );
	}

	#[DataProvider( 'scenarioNames' )]
	public function testAnalysisHasSixSectionsWithValidStatuses( string $name ): void {
		$analysis = $this->analysisFor( AnswersFactory::scenarioAnswers()[ $name ] );

		$this->assertSame(
			array( 'residence', 'trips', 'housing', 'health', 'funds', 'unknowns' ),
			array_column( $analysis['sections'], 'id' ),
			$name
		);
		$this->assertNotSame( '', $analysis['period'] );
		$this->assertNotEmpty( $analysis['facts'] );

		foreach ( $analysis['sections'] as $s ) {
			$this->assertContains( $s['status'], array( 'ok', 'check', 'obstacle', 'info' ), "{$name}: {$s['id']}" );
			$this->assertNotSame( '', $s['title'] );
			$this->assertNotEmpty( $s['lines'], "{$name}: {$s['id']} без строк" );
		}
	}

	public function testAnalysisStatusMatchesWorstFindingOfArea(): void {
		$areas = array(
			'residence' => array( 'residence', 'special' ),
			'trips'     => array( 'trips' ),
			'housing'   => array( 'housing' ),
			'health'    => array( 'health' ),
			'funds'     => array( 'funds' ),
			'unknowns'  => array( 'documents', 'applicant' ),
		);
		$rank  = array( 'info' => 0, 'ok' => 1, 'check' => 2, 'obstacle' => 3 );

		foreach ( AnswersFactory::scenarioAnswers() as $name => $answers ) {
			$result   = self::evaluate( $answers );
			$analysis = $this->analysisFor( $answers );

			foreach ( $analysis['sections'] as $s ) {
				$worst = 'info';

				foreach ( $result['findings'] as $f ) {
					if ( in_array( $f['area'], $areas[ $s['id'] ], true ) && $rank[ $f['severity'] ] > $rank[ $worst ] ) {
						$worst = $f['severity'];
					}
				}

				// Блок неизвестных может быть повышен до check сводной строкой о пробелах.
				if ( 'unknowns' === $s['id'] && $rank[ $s['status'] ] > $rank[ $worst ] ) {
					$this->assertSame( 'check', $s['status'], "{$name}: {$s['id']}" );
					continue;
				}

				$this->assertSame( $worst, $s['status'], "{$name}: {$s['id']}" );
			}
		}
	}

	public function testAnalysisObstacleScenarioMarksSectionAsObstacle(): void {
		$analysis = $this->analysisFor( AnswersFactory::with( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'none' ) ) ) );
		$statuses = array_column( $analysis['sections'], 'status', 'id' );

		$this->assertSame( 'obstacle', $statuses['health'] );
		$this->assertContains( 'obstacle', $statuses );
	}

	/** @return array<string,mixed> */
	private static function analysisSectionById( array $analysis, string $id ): array {
		foreach ( $analysis['sections'] as $s ) {
			if ( $id === $s['id'] ) {
				return $s;
			}
		}

		self::fail( 'нет блока ' . $id );
	}

	public function testSpecialFindingsAppearInResidenceBlock(): void {
		$answers  = AnswersFactory::with( array( 'special' => array( 'blue_card' => 'yes' ) ) );
		$result   = self::evaluate( $answers );
		$analysis = $this->analysisFor( $answers );
		$res      = self::analysisSectionById( $analysis, 'residence' );
		$text     = implode( "\n", $res['lines'] );
		$found    = false;

		foreach ( $result['findings'] as $f ) {
			if ( 'special' === $f['area'] && 'info' !== $f['severity'] ) {
				$found = true;
				$this->assertStringContainsString( $this->composer()->text( $f, 'short' ), $text, $f['code'] );
			}
		}

		$this->assertTrue( $found, 'нет особых findings в сценарии Синей карты' );
		$this->assertStringNotContainsString( 'Учебных разрешений по ст. 24в', $text );
	}

	public function testResidenceBlockSaysNoSpecialGroundsWhenNone(): void {
		$res = self::analysisSectionById( $this->analysisFor( AnswersFactory::base() ), 'residence' );
		$this->assertStringContainsString( 'Учебных разрешений по ст. 24в', implode( "\n", $res['lines'] ) );
	}

	public function testUnknownsBlockListsTripsAndIsCheckForIncompleteTrips(): void {
		$answers  = AnswersFactory::with( array( 'trips_any' => 'yes', 'trips' => array(), 'trips_complete' => 'partial' ) );
		$result   = self::evaluate( $answers );
		$codes    = array_column( $result['findings'], 'code' );
		$this->assertContains( 'TRIP_INCOMPLETE', $codes );

		$unknowns = self::analysisSectionById( $this->analysisFor( $answers ), 'unknowns' );
		$text     = implode( "\n", $unknowns['lines'] );

		$this->assertStringContainsString( 'Не хватает сведений или подтверждений', $text );
		$this->assertStringContainsString( 'отсутствия', $text );
		$this->assertSame( 'check', $unknowns['status'] );
		$this->assertStringNotContainsString( 'не выявлено', $text );
	}

	public function testUnknownsBlockForBaseScenarioHasNoGapsWhenThresholdConfigured(): void {
		// В AnswersFactory::config() задан порог дохода, поэтому «средства» в пробелы не попадают.
		$unknowns = self::analysisSectionById( $this->analysisFor( AnswersFactory::base() ), 'unknowns' );
		$text     = implode( "\n", $unknowns['lines'] );

		$this->assertStringContainsString( 'не выявлено', $text );
		$this->assertStringNotContainsString( 'Не хватает сведений', $text );
		$this->assertNotSame( 'check', $unknowns['status'] );
		$this->assertNotSame( 'obstacle', $unknowns['status'] );
	}

	public function testAnalysisTripsLineCountsOnlyCalculatedTripsAndReportsInvalid(): void {
		$answers  = AnswersFactory::with(
			array(
				'trips_any'      => 'yes',
				'trips_complete' => 'complete',
				'trips'          => array(
					AnswersFactory::trip( '2024-01-10', '2024-01-20' ),
					AnswersFactory::trip( '2023-05-10', '2023-05-01' ),
				),
			)
		);
		$analysis = $this->analysisFor( $answers );
		$trips    = array_values( array_filter( $analysis['sections'], static fn( array $s ): bool => 'trips' === $s['id'] ) )[0];
		$text     = implode( "\n", $trips['lines'] );

		$this->assertStringContainsString( 'Рассчитано поездок в проверяемом периоде: 1,', $text );
		$this->assertStringContainsString( 'Поездок без полных или с ошибочными датами: 1', $text );
		$this->assertStringContainsString( 'не вошли в расчёт', $text );
	}

	public function testAnalysisApproxTripShowsRange(): void {
		$answers  = AnswersFactory::with(
			array(
				'trips_any'      => 'yes',
				'trips_complete' => 'complete',
				'trips'          => array( AnswersFactory::trip( '2024-01-10', '2024-02-10', 'approx' ) ),
			)
		);
		$analysis = $this->analysisFor( $answers );
		$trips    = array_values( array_filter( $analysis['sections'], static fn( array $s ): bool => 'trips' === $s['id'] ) )[0];

		$this->assertStringContainsString( 'примерно от', implode( "\n", $trips['lines'] ) );
		$this->assertStringNotContainsString( 'Поездок без полных', implode( "\n", $trips['lines'] ) );
	}

	public function testAnalysisForOkScenarioHasNoObstacles(): void {
		$analysis = $this->analysisFor( AnswersFactory::base() );

		$this->assertNotContains( 'obstacle', array_column( $analysis['sections'], 'status' ) );
	}

	#[DataProvider( 'scenarioNames' )]
	public function testAnalysisDoesNotLeakDetailActionOrQuestionTexts( string $name ): void {
		$answers  = AnswersFactory::scenarioAnswers()[ $name ];
		$result   = self::evaluate( $answers );
		$analysis = $this->analysisFor( $answers );
		$lines    = self::strings( $analysis );

		foreach ( $result['findings'] as $f ) {
			$short = $this->composer()->text( $f, 'short' );

			foreach ( array( 'detail', 'action', 'question' ) as $kind ) {
				$text = $this->composer()->text( $f, $kind );

				if ( '' === $text || $text === $short ) {
					continue;
				}

				foreach ( $lines as $line ) {
					$this->assertStringNotContainsString( $text, $line, "{$name}: {$f['code']} / {$kind} попал в разбор" );
				}
			}
		}
	}

	#[DataProvider( 'scenarioNames' )]
	public function testPreviewWithAnalysisHasNoInstructionTitlesOrPlaceholders( string $name ): void {
		$answers = AnswersFactory::scenarioAnswers()[ $name ];
		$preview = $this->composer()->preview( self::evaluate( $answers ), $answers );
		// Рекламное описание состава платной инструкции (offer) названия разделов упоминает намеренно.
		$free = $preview;
		unset( $free['offer'] );
		$json = (string) json_encode( $free, JSON_UNESCAPED_UNICODE );

		foreach ( array( 'Персональный перечень документов', 'Последовательность действий', 'Вопросы для подразделения' ) as $title ) {
			$this->assertStringNotContainsString( $title, $json, $name );
		}

		foreach ( self::strings( $preview ) as $text ) {
			$this->assertStringNotContainsString( '{', $text, "{$name}: «{$text}»" );
			$this->assertStringNotContainsString( '}', $text, "{$name}: «{$text}»" );
		}
	}
}
