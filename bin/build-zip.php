<?php
/**
 * Сборка ZIP плагина для загрузки в WordPress.
 *
 * Запуск: `php bin/build-zip.php [--no-bump] [--dry-run] [--out=<каталог>]`
 *
 * 1. Поднимает patch-версию в bginfo-pmzh-dvzh.php (заголовок `Version:` и
 *    define BGINFO_PMZH_DVZH_VERSION); `--no-bump` пропускает этот шаг.
 * 2. Берёт следующий номер N после максимального `_<N>bginfo-pmzh-dvzh.zip`
 *    в каталоге вывода (по умолчанию dist/). Существующий архив не перезаписывается.
 * 3. Кладёт в архив папку `bginfo-pmzh-dvzh/` только с рантаймом:
 *    bginfo-pmzh-dvzh.php, uninstall.php, README.md, README_RU.md, src/,
 *    assets/, vendor-prefixed/ (целиком, со шрифтами и лицензиями).
 *    Не попадают: tests, docs, bin, .tools, .git*, dotfiles, .env, dist,
 *    файлы phpunit, CLAUDE.md.
 * 4. После сборки открывает архив и проверяет корневую папку и главный файл.
 *
 * `--dry-run` только печатает список файлов: ничего не пишет и не меняет версию.
 * `--out=<каталог>` задаёт каталог вывода (для проверок; версия при этом
 * по-прежнему поднимается, если не указан --no-bump).
 */

declare( strict_types=1 );

const BUILD_SLUG = 'bginfo-pmzh-dvzh';

$root    = dirname( __DIR__ );
$options = array_slice( $argv, 1 );
$noBump  = in_array( '--no-bump', $options, true );
$dryRun  = in_array( '--dry-run', $options, true );
$distDir = $root . '/dist';

foreach ( $options as $opt ) {
	if ( str_starts_with( $opt, '--out=' ) ) {
		$distDir = rtrim( str_replace( '\\', '/', substr( $opt, 6 ) ), '/' );
	}
}

if ( ! class_exists( ZipArchive::class ) ) {
	fwrite( STDERR, "Нужно расширение PHP zip.\n" );
	exit( 1 );
}

$mainName = BUILD_SLUG . '.php';
$mainFile = $root . '/' . $mainName;
$source   = (string) file_get_contents( $mainFile );

if ( 1 !== preg_match( '/^(\s*\*\s*Version:\s*)(\d+)\.(\d+)\.(\d+)\s*$/m', $source, $m ) ) {
	fwrite( STDERR, "Не найдена строка Version: в {$mainName}.\n" );
	exit( 1 );
}

$version = "{$m[2]}.{$m[3]}.{$m[4]}";

if ( ! $noBump ) {
	$version = "{$m[2]}.{$m[3]}." . ( (int) $m[4] + 1 );
	$updated = preg_replace( '/^(\s*\*\s*Version:\s*)\d+\.\d+\.\d+/m', '${1}' . $version, $source, 1 );
	$updated = preg_replace( "/(define\(\s*'BGINFO_PMZH_DVZH_VERSION',\s*')\d+\.\d+\.\d+(')/", '${1}' . $version . '${2}', (string) $updated, 1, $count );

	if ( 1 !== $count ) {
		fwrite( STDERR, "Не найден define BGINFO_PMZH_DVZH_VERSION в {$mainName}.\n" );
		exit( 1 );
	}

	$source = (string) $updated;
}

// Файлы рантайма: относительный путь => абсолютный.
$files = array();

foreach ( array( $mainName, 'uninstall.php', 'README.md', 'README_RU.md' ) as $name ) {
	if ( is_file( $root . '/' . $name ) ) {
		$files[ $name ] = $root . '/' . $name;
	}
}

foreach ( array( 'src', 'assets', 'vendor-prefixed' ) as $dir ) {
	if ( ! is_dir( $root . '/' . $dir ) ) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		/** @var SplFileInfo $file */
		$name = $file->getFilename();

		if ( ! $file->isFile() || str_starts_with( $name, '.git' ) || '.DS_Store' === $name || '.env' === $name ) {
			continue;
		}

		$relative           = $dir . '/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root . '/' . $dir ) + 1 ) );
		$files[ $relative ] = $file->getPathname();
	}
}

if ( ! isset( $files[ $mainName ] ) ) {
	fwrite( STDERR, "Нет главного файла {$mainName}.\n" );
	exit( 1 );
}

// Защита от утечки секретов: настоящий .env в архив не попадает никогда.
foreach ( array_keys( $files ) as $relative ) {
	if ( '.env' === basename( (string) $relative ) ) {
		fwrite( STDERR, "В архив попал .env ({$relative}) - сборка прервана.\n" );
		exit( 1 );
	}
}

ksort( $files );

// Следующий номер архива.
$max = 0;

foreach ( (array) glob( $distDir . '/_*' . BUILD_SLUG . '.zip' ) as $existing ) {
	if ( 1 === preg_match( '/_(\d+)' . preg_quote( BUILD_SLUG, '/' ) . '\.zip$/', (string) $existing, $n ) ) {
		$max = max( $max, (int) $n[1] );
	}
}

$target = $distDir . '/_' . ( $max + 1 ) . BUILD_SLUG . '.zip';

if ( file_exists( $target ) ) {
	fwrite( STDERR, "Файл уже существует: {$target}\n" );
	exit( 1 );
}

if ( $dryRun ) {
	foreach ( array_keys( $files ) as $relative ) {
		echo BUILD_SLUG . '/' . $relative . "\n";
	}

	printf( "[dry-run] %s, версия %s, файлов: %d\n", $target, $version, count( $files ) );
	exit( 0 );
}

if ( ! is_dir( $distDir ) && ! mkdir( $distDir, 0777, true ) ) {
	fwrite( STDERR, "Не удалось создать каталог {$distDir}.\n" );
	exit( 1 );
}

/**
 * Прерывает сборку: удаляет временный файл, печатает ошибку в STDERR.
 *
 * @param string $message Текст ошибки.
 * @param string $temp    Путь временного архива.
 */
function build_fail( string $message, string $temp ): never {
	if ( is_file( $temp ) ) {
		unlink( $temp );
	}

	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/*
 * Транзакционная сборка: архив собирается во временный файл (имя не подпадает
 * под `_<N>bginfo-pmzh-dvzh.zip`), главный файл кладётся из уже поднятой версии
 * в памяти. Исходный файл правится только после успешной проверки архива, и
 * если запись не удалась - готовый архив удаляется, чтобы версия и архив не разошлись.
 */
$temp = $distDir . '/.building-' . ( $max + 1 ) . '.zip';

if ( is_file( $temp ) ) {
	unlink( $temp );
}

$zip = new ZipArchive();

if ( true !== $zip->open( $temp, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
	build_fail( "Не удалось создать временный архив {$temp}.", $temp );
}

foreach ( $files as $relative => $absolute ) {
	$added = $mainName === $relative
		? $zip->addFromString( BUILD_SLUG . '/' . $relative, $source )
		: $zip->addFile( $absolute, BUILD_SLUG . '/' . $relative );

	if ( true !== $added ) {
		$zip->close();
		build_fail( "Не удалось добавить в архив: {$relative}.", $temp );
	}
}

if ( true !== $zip->close() ) {
	build_fail( 'Не удалось закрыть архив: ' . $zip->getStatusString(), $temp );
}

// Проверка собранного архива: одна корневая папка и главный файл внутри.
$check = new ZipArchive();

if ( true !== $check->open( $temp ) ) {
	build_fail( 'Не удалось открыть собранный архив для проверки.', $temp );
}

$roots = array();

for ( $i = 0; $i < $check->numFiles; $i++ ) {
	$entry = (string) $check->getNameIndex( $i );
	$roots[ explode( '/', $entry )[0] ] = true;
}

$hasMain  = false !== $check->locateName( BUILD_SLUG . '/' . $mainName );
$numFiles = $check->numFiles;
$check->close();

if ( array_keys( $roots ) !== array( BUILD_SLUG ) || ! $hasMain ) {
	build_fail( 'Проверка архива не пройдена: корневая папка ' . implode( ',', array_keys( $roots ) ) . ', главный файл ' . ( $hasMain ? 'есть' : 'отсутствует' ) . '.', $temp );
}

if ( file_exists( $target ) ) {
	build_fail( "Файл уже существует: {$target}", $temp );
}

if ( ! rename( $temp, $target ) ) {
	build_fail( "Не удалось переименовать {$temp} в {$target}.", $temp );
}

if ( ! $noBump && false === file_put_contents( $mainFile, $source ) ) {
	unlink( $target );
	fwrite( STDERR, "Не удалось записать {$mainName}, архив удалён.\n" );
	exit( 1 );
}

printf(
	"Архив: %s\nВерсия: %s\nФайлов: %d\nРазмер: %.1f КБ\nПроверка: корневая папка %s/, главный файл на месте (записей в архиве: %d)\n",
	$target,
	$version,
	count( $files ),
	filesize( $target ) / 1024,
	BUILD_SLUG,
	$numFiles
);
