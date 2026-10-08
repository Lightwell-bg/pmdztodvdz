<?php
/**
 * Превращает документ Composer (секции и блоки) в HTML.
 *
 * render()   — фрагмент для страницы (после подтверждения оплаты).
 * document() — самостоятельный HTML для PDF с печатным CSS.
 *
 * Весь текст проходит через esc_html, адреса — через esc_url (только
 * http/https), классы имеют префикс bgdvzh-. Обе функции работают с
 * одним и тем же документом, поэтому страница и PDF совпадают.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Instruction;

final class HtmlRenderer {

	/**
	 * Фрагмент HTML для вставки на страницу.
	 *
	 * @param array<string,mixed> $doc
	 */
	public function render( array $doc ): string {
		$html = '<div class="bgdvzh-instruction">';

		foreach ( (array) ( $doc['sections'] ?? array() ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$id    = preg_replace( '/[^a-z0-9_-]/i', '', (string) ( $section['id'] ?? '' ) );
			$html .= '<section class="bgdvzh-section"' . ( '' !== $id ? ' id="bgdvzh-sec-' . esc_attr( (string) $id ) . '"' : '' ) . '>';
			$html .= '<h3 class="bgdvzh-h">' . esc_html( (string) ( $section['title'] ?? '' ) ) . '</h3>';

			foreach ( (array) ( $section['blocks'] ?? array() ) as $block ) {
				if ( is_array( $block ) ) {
					$html .= $this->block( $block );
				}
			}

			$html .= '</section>';
		}

		return $html . '</div>';
	}

	/**
	 * Полный HTML-документ для генерации PDF.
	 *
	 * @param array<string,mixed> $doc
	 */
	public function document( array $doc ): string {
		$title = (string) ( $doc['title'] ?? '' );

		$meta = array();

		if ( ! empty( $doc['calc_date'] ) ) {
			$meta[] = 'Дата расчёта: ' . self::humanDate( (string) $doc['calc_date'] );
		}

		if ( ! empty( $doc['filing_date'] ) ) {
			$meta[] = 'Дата подачи для расчёта: ' . self::humanDate( (string) $doc['filing_date'] );
		}

		if ( ! empty( $doc['rules_version'] ) ) {
			$meta[] = 'Версия правил: ' . (string) $doc['rules_version'];
		}

		if ( ! empty( $doc['templates_version'] ) ) {
			$meta[] = 'Версия шаблонов: ' . (string) $doc['templates_version'];
		}

		$html  = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">';
		$html .= '<title>' . esc_html( $title ) . '</title>';
		$html .= '<style>' . self::printCss() . '</style></head><body>';
		$html .= '<div class="bgdvzh-doc-header"><h1 class="bgdvzh-doc-title">' . esc_html( $title ) . '</h1>';

		if ( ! empty( $doc['status_title'] ) ) {
			$html .= '<p class="bgdvzh-doc-status">' . esc_html( (string) $doc['status_title'] ) . '</p>';
		}

		$html .= '<p class="bgdvzh-doc-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p></div>';
		$html .= $this->render( $doc );

		return $html . '</body></html>';
	}

	/**
	 * Печатный CSS. Шрифт DejaVu Sans (кириллица), перенос длинных слов,
	 * таблицы без разрыва строк. Номера страниц рисует PdfRenderer.
	 */
	public static function printCss(): string {
		return '@page { margin: 22mm 16mm 20mm 16mm; }'
			. 'body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; line-height: 1.4; color: #111; word-wrap: break-word; overflow-wrap: anywhere; }'
			. 'h1.bgdvzh-doc-title { font-size: 16pt; margin: 0 0 4pt; }'
			. '.bgdvzh-doc-status { font-size: 11pt; font-weight: bold; margin: 0 0 4pt; }'
			. '.bgdvzh-doc-meta { font-size: 8pt; color: #444; margin: 0 0 10pt; border-bottom: 1px solid #999; padding-bottom: 6pt; }'
			. 'h3.bgdvzh-h { font-size: 12pt; margin: 14pt 0 5pt; page-break-after: avoid; }'
			. 'p { margin: 0 0 6pt; }'
			. 'ul, ol { margin: 0 0 6pt 0; padding-left: 16pt; }'
			. 'li { margin-bottom: 3pt; }'
			. 'table { border-collapse: collapse; width: 100%; margin: 0 0 8pt; table-layout: fixed; page-break-inside: auto; }'
			. 'tr { page-break-inside: avoid; }'
			. 'th, td { border: 1px solid #777; padding: 3pt 4pt; font-size: 9pt; vertical-align: top; text-align: left; word-wrap: break-word; overflow-wrap: anywhere; }'
			. 'th { background: #e8e8e8; }'
			. '.bgdvzh-note { border: 1px solid #999; background: #f4f4f4; padding: 5pt 7pt; margin: 0 0 8pt; font-size: 9pt; }'
			. 'a { color: #0b3d91; word-wrap: break-word; overflow-wrap: anywhere; }';
	}

	/** @param array<string,mixed> $b */
	private function block( array $b ): string {
		switch ( (string) ( $b['type'] ?? '' ) ) {
			case 'p':
				return '<p class="bgdvzh-p">' . $this->text( (string) ( $b['text'] ?? '' ) ) . '</p>';

			case 'note':
				return '<div class="bgdvzh-note">' . $this->text( (string) ( $b['text'] ?? '' ) ) . '</div>';

			case 'list':
				$tag   = ! empty( $b['ordered'] ) ? 'ol' : 'ul';
				$items = '';

				foreach ( (array) ( $b['items'] ?? array() ) as $item ) {
					$items .= '<li>' . $this->text( is_scalar( $item ) ? (string) $item : '' ) . '</li>';
				}

				return '<' . $tag . ' class="bgdvzh-list">' . $items . '</' . $tag . '>';

			case 'table':
				return $this->table( $b );

			case 'links':
				return $this->links( $b );
		}

		return '';
	}

	/** @param array<string,mixed> $b */
	private function table( array $b ): string {
		$html = '<table class="bgdvzh-table">';
		$head = (array) ( $b['head'] ?? array() );

		if ( ! empty( $head ) ) {
			$html .= '<thead><tr>';

			foreach ( $head as $cell ) {
				$html .= '<th>' . $this->text( is_scalar( $cell ) ? (string) $cell : '' ) . '</th>';
			}

			$html .= '</tr></thead>';
		}

		$html .= '<tbody>';

		foreach ( (array) ( $b['rows'] ?? array() ) as $row ) {
			$html .= '<tr>';

			foreach ( (array) $row as $cell ) {
				$html .= '<td>' . $this->text( is_scalar( $cell ) ? (string) $cell : '' ) . '</td>';
			}

			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/** @param array<string,mixed> $b */
	private function links( array $b ): string {
		$html = '<ul class="bgdvzh-links">';

		foreach ( (array) ( $b['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title = (string) ( $item['title'] ?? '' );
			$url   = esc_url( (string) ( $item['url'] ?? '' ), array( 'http', 'https' ) );
			$note  = (string) ( $item['note'] ?? '' );

			$html .= '<li>';
			$html .= '' !== $url
				? '<a href="' . $url . '" rel="noopener noreferrer" target="_blank">' . esc_html( $title ) . '</a>'
				: esc_html( $title );

			if ( '' !== $note ) {
				$html .= ' — ' . esc_html( $note );
			}

			$html .= '</li>';
		}

		return $html . '</ul>';
	}

	/** Экранированный текст; переводы строк — <br>. */
	private function text( string $text ): string {
		return str_replace( array( "\r\n", "\n", "\r" ), '<br>', esc_html( $text ) );
	}

	private static function humanDate( string $ymd ): string {
		return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $ymd;
	}
}
