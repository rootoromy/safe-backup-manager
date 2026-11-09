<?php
/**
 * 動作確認用スモークテスト（WP-CLI から実行）。
 *
 *   wp eval-file wp-content/plugins/safe-backup-manager/tests/smoke-test.php
 *
 * 実際のサイトに対してバックアップを作成・検証し、最後にテストで作ったバックアップを削除する。
 * 架空データのローカル環境でのみ実行すること。
 *
 * @package SafeBackupManager
 */

// CLI 以外（Web 経由）からは実行させない。
if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
	exit;
}

// phpcs:disable -- テスト用スクリプトのため。

$sbm_results = array();

function sbm_check( $name, $ok, $detail = '' ) {
	global $sbm_results;
	$sbm_results[] = (bool) $ok;
	printf( "[%s] %s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail );
}

/**
 * 保存先を任意のパスに差し替えるテスト用ダブル。
 */
class SBM_Test_Storage extends SBM_Storage {
	public $dir;
	public function get_dir() {
		return $this->dir;
	}
}

$storage = new SBM_Storage();
$repo    = new SBM_Backup_Repository();
$creator = new SBM_Backup_Creator( $storage, $repo, new SBM_Database_Exporter() );
$created = array();

// --- T1: 正常系 ---------------------------------------------------------------
$start     = microtime( true );
$rec       = $creator->create();
$created[] = $rec['id'];
$zip_path  = $storage->path( $rec['filename'] );

sbm_check( 'T1 ZIP が作成される', is_file( $zip_path ), sprintf( '%s %s files=%d tables=%d %.1fs', $rec['filename'], size_format( $rec['size'] ), $rec['file_count'], $rec['table_count'], microtime( true ) - $start ) );
sbm_check( 'T1 ファイル名の形式', (bool) preg_match( '/^backup-\d{8}-\d{6}-[a-f0-9]{12}\.zip$/', $rec['filename'] ) );
sbm_check( 'T1 SHA-256 が一致', hash_file( 'sha256', $zip_path ) === $rec['sha256'] );
sbm_check( 'T1 履歴に登録される', null !== $repo->find( $rec['id'] ) );
sbm_check( 'T1 一時ファイルが残らない', 0 === count( (array) glob( $storage->get_dir() . '/*.part' ) ) );
foreach ( array( '.htaccess', 'web.config', 'index.php', 'index.html' ) as $f ) {
	sbm_check( "T1 保護ファイル $f", is_file( $storage->get_dir() . "/$f" ) );
}

$zip = new ZipArchive();
$zip->open( $zip_path, ZipArchive::CHECKCONS );
$names = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$names[] = $zip->getNameIndex( $i );
}
$stylesheet = 'wp-content/themes/' . get_stylesheet() . '/style.css';
sbm_check( 'T1 ディレクトリ構造を維持', in_array( $stylesheet, $names, true ), $stylesheet );
sbm_check( 'T1 バックアップ保存先は含まない', ! preg_grep( '#sbm-backups-#', $names ) );
sbm_check( 'T1 パス区切りは / のみ', ! array_filter( $names, static function ( $n ) { return false !== strpos( $n, chr( 92 ) ); } ) );

$manifest = json_decode( (string) $zip->getFromName( 'sbm-manifest.json' ), true );
sbm_check( 'T1 マニフェスト', is_array( $manifest ) && get_bloginfo( 'version' ) === $manifest['wp_version'] );

$sql = (string) $zip->getFromName( 'database/database.sql' );
$zip->close();
sbm_check( 'T1 SQL に wp_options の CREATE 文', false !== strpos( $sql, 'CREATE TABLE `' . $wpdb->options . '`' ) );
sbm_check( 'T1 SQL の % がプレースホルダ化されない', false === strpos( $sql, $wpdb->placeholder_escape() ) );
sbm_check( 'T1 実行中ロックの行は SQL に含まない', false === strpos( $sql, "'" . SBM_Backup_Creator::LOCK_OPTION . "'" ) );

// --- T2: 対象ディレクトリが存在しない ---------------------------------------
$filter = static function () {
	return array( 'wp-content' => WP_CONTENT_DIR . '/sbm-does-not-exist' );
};
add_filter( 'sbm_backup_sources', $filter );
try {
	$creator->create();
	sbm_check( 'T2 対象ディレクトリなし → エラー', false );
} catch ( SBM_Exception $e ) {
	sbm_check( 'T2 対象ディレクトリなし → エラー', true, '→ ' . $e->getMessage() );
}
remove_filter( 'sbm_backup_sources', $filter );
sbm_check( 'T2 失敗後にロックが解放される', false === get_option( SBM_Backup_Creator::LOCK_OPTION ) );

// --- T3: 保存先を作れない（ファイル作成失敗） -------------------------------
$blocker = get_temp_dir() . 'sbm-blocker-' . wp_generate_password( 6, false );
file_put_contents( $blocker, 'x' );
$bad      = new SBM_Test_Storage();
$bad->dir = wp_normalize_path( $blocker ) . '/sub'; // 通常ファイルの下にはディレクトリを作れない。
$before   = count( $repo->all() );
try {
	( new SBM_Backup_Creator( $bad, $repo, new SBM_Database_Exporter() ) )->create();
	sbm_check( 'T3 保存先作成失敗 → エラー', false );
} catch ( SBM_Exception $e ) {
	sbm_check( 'T3 保存先作成失敗 → エラー', true, '→ ' . $e->getMessage() );
}
sbm_check( 'T3 失敗時は履歴に残らない', count( $repo->all() ) === $before );
unlink( $blocker );

// --- T4/T5: 多重実行の防止と、異常終了したロックの回復 -----------------------
add_option( SBM_Backup_Creator::LOCK_OPTION, time(), '', false );
try {
	$creator->create();
	sbm_check( 'T4 実行中は二重に作成できない', false );
} catch ( SBM_Exception $e ) {
	sbm_check( 'T4 実行中は二重に作成できない', true, '→ ' . $e->getMessage() );
}
sbm_check( 'T4 他の処理のロックは消さない', false !== get_option( SBM_Backup_Creator::LOCK_OPTION ) );
delete_option( SBM_Backup_Creator::LOCK_OPTION );

add_option( SBM_Backup_Creator::LOCK_OPTION, time() - 3 * HOUR_IN_SECONDS, '', false );
$rec5      = $creator->create();
$created[] = $rec5['id'];
sbm_check( 'T5 古いロックは無効とみなして作成できる', is_file( $storage->path( $rec5['filename'] ) ) );

// --- T6: 存在しない・不正な指定 ------------------------------------------------
sbm_check( 'T6 存在しない ID は見つからない', null === $repo->find( str_repeat( '0', 32 ) ) );
sbm_check( 'T6 不正な ID は見つからない', null === $repo->find( '../../wp-config.php' ) );
try {
	$storage->path( '../wp-config.php' );
	sbm_check( 'T6 パストラバーサルを拒否', false );
} catch ( SBM_Exception $e ) {
	sbm_check( 'T6 パストラバーサルを拒否', true );
}

// --- 後片付け ------------------------------------------------------------------
foreach ( $created as $id ) {
	$r = $repo->find( $id );
	if ( $r ) {
		wp_delete_file( $storage->path( $r['filename'] ) );
		$repo->remove( $id );
	}
}

printf( "\n%d/%d passed\n", count( array_filter( $sbm_results ) ), count( $sbm_results ) );
