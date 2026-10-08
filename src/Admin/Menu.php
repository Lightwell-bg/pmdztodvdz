<?php
/**
 * Меню админки «ПМЖ → ДВЖ» и маршрутизация экранов. Собирается только
 * в админке (Plugin::wire() создаёт объект под is_admin()).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Storage\DocumentRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;

final class Menu {

	private const CAPABILITY = 'manage_options';

	private ?SubmissionView $view = null;
	private ?ConsultationsPage $consultations = null;
	private ?ConsultSettingsPage $consultSettings = null;

	/** @param array<string,object> $services Plugin::instance()->services */
	public function __construct( private array $services ) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_post_' . SettingsPage::ACTION, array( $this->settingsPage(), 'handleSave' ) );
		add_action( 'admin_post_' . TemplatesPage::ACTION, array( $this->templatesPage(), 'handleSave' ) );
		add_action( 'admin_post_' . SubmissionView::ACTION_REGEN, array( $this->submissionView(), 'handleRegen' ) );
		add_action( 'admin_post_' . SubmissionView::ACTION_PURGE, array( $this->submissionView(), 'handlePurge' ) );
		add_action( 'admin_post_' . SubmissionView::ACTION_DOWNLOAD, array( $this->submissionView(), 'handleDownload' ) );
		add_action( 'admin_post_' . ConsultationsPage::ACTION, array( $this->consultationsPage(), 'handleAction' ) );
		add_action( 'admin_post_' . ConsultSettingsPage::ACTION, array( $this->consultSettingsPage(), 'handleSave' ) );
		$this->consultSettingsPage()->registerAssets();
	}

	public function addMenu(): void {
		add_menu_page( 'ПМЖ → ДВЖ', 'ПМЖ → ДВЖ', self::CAPABILITY, SubmissionView::PAGE, array( $this, 'renderSubmissions' ), 'dashicons-clipboard', 58 );
		add_submenu_page( SubmissionView::PAGE, 'Анкеты', 'Анкеты', self::CAPABILITY, SubmissionView::PAGE, array( $this, 'renderSubmissions' ) );
		add_submenu_page( SubmissionView::PAGE, 'Консультации', 'Консультации', self::CAPABILITY, ConsultationsPage::SLUG, array( $this->consultationsPage(), 'render' ) );
		add_submenu_page( SubmissionView::PAGE, 'Настройки', 'Настройки', self::CAPABILITY, SettingsPage::SLUG, array( $this->settingsPage(), 'render' ) );
		add_submenu_page( SubmissionView::PAGE, 'Настройки консультаций', 'Настройки консультаций', self::CAPABILITY, ConsultSettingsPage::SLUG, array( $this->consultSettingsPage(), 'render' ) );
		add_submenu_page( SubmissionView::PAGE, 'Шаблоны', 'Шаблоны', self::CAPABILITY, TemplatesPage::SLUG, array( $this->templatesPage(), 'render' ) );
		add_submenu_page( SubmissionView::PAGE, 'Правила и источники', 'Правила и источники', self::CAPABILITY, RulesPage::SLUG, array( new RulesPage(), 'render' ) );
	}

	/** Список анкет либо экран просмотра (`action=view`). */
	public function renderSubmissions(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce проверяет экран просмотра.

		if ( 'view' === $action ) {
			$this->submissionView()->render();
			return;
		}

		/** @var SnapshotRepository $snapshots */
		$snapshots = $this->services['snapshots'];
		/** @var PurchaseRepository $purchases */
		$purchases = $this->services['purchases'];
		/** @var DocumentRepository $documents */
		$documents = $this->services['documentRepo'];

		$table = new SubmissionsList( $snapshots, $purchases, $documents );
		$table->prepare_items();

		echo '<div class="wrap"><h1>' . esc_html( 'ПМЖ → ДВЖ: анкеты' ) . '</h1>';
		echo '<p>' . esc_html( 'Ответы в списке не показываются: откройте анкету, чтобы увидеть подробности.' ) . '</p>';
		$table->display();
		echo '</div>';
	}

	private function settingsPage(): SettingsPage {
		return new SettingsPage( $this->services['settings'], $this->services['gate'] );
	}

	private function consultationsPage(): ConsultationsPage {
		if ( null === $this->consultations ) {
			$this->consultations = new ConsultationsPage( $this->services );
		}

		return $this->consultations;
	}

	private function consultSettingsPage(): ConsultSettingsPage {
		if ( null === $this->consultSettings ) {
			$this->consultSettings = new ConsultSettingsPage( $this->services['settings'], $this->services['consultGate'] );
		}

		return $this->consultSettings;
	}

	private function templatesPage(): TemplatesPage {
		return new TemplatesPage( $this->services['templates'], $this->services['templateRepo'] );
	}

	private function submissionView(): SubmissionView {
		if ( null === $this->view ) {
			$this->view = new SubmissionView(
				$this->services['snapshots'],
				$this->services['purchases'],
				$this->services['documentRepo'],
				$this->services['events'],
				$this->services['policy'],
				$this->services['templates'],
				$this->services['settings'],
				$this->services['purger'],
				$this->services['documents'],
				$this->services['renderer']
			);
		}

		return $this->view;
	}
}
