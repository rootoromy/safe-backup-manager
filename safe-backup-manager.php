<?php
/**
 * Plugin Name: Safe Backup Manager
 * Description: WordPress管理画面からバックアップの作成・一覧表示・ダウンロードを行うプラグイン（試作）。
 * Version:     0.1.0
 * Author:      rootoromy
 * License:     GPL-2.0-or-later
 * Text Domain: safe-backup-manager
 * Requires PHP: 7.4
 * Requires at least: 6.4
 *
 * @package SafeBackupManager
 */

// WordPressを経由せずにこのファイルを直接開いた場合は、何もせずに終了する
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SBM_VERSION', '0.1.0' );
define( 'SBM_PLUGIN_FILE', __FILE__ );
define( 'SBM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once SBM_PLUGIN_DIR . 'includes/class-sbm-exception.php';
require_once SBM_PLUGIN_DIR . 'includes/class-sbm-storage.php';
require_once SBM_PLUGIN_DIR . 'includes/class-sbm-backup-repository.php';
require_once SBM_PLUGIN_DIR . 'includes/class-sbm-database-exporter.php';
require_once SBM_PLUGIN_DIR . 'includes/class-sbm-backup-creator.php';
require_once SBM_PLUGIN_DIR . 'includes/class-sbm-admin.php';

/**
 * 有効化時：保存先ディレクトリと保護ファイルを用意しておく。
 * 失敗しても有効化自体は止めない（バックアップ作成時に再試行し、エラーを表示する）。
 */
register_activation_hook(
	__FILE__,
	static function () {
		try {
			( new SBM_Storage() )->prepare();
		} catch ( SBM_Exception $e ) {
			error_log( '[Safe Backup Manager] ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
);

/**
 * 管理画面でのみ読み込む。フロント側の処理には一切フックしない（サイト表示への影響をゼロにするため）。
 */
if ( is_admin() ) {
	add_action(
		'plugins_loaded',
		static function () {
			$storage    = new SBM_Storage();
			$repository = new SBM_Backup_Repository();
			$creator    = new SBM_Backup_Creator( $storage, $repository, new SBM_Database_Exporter() );

			( new SBM_Admin( $storage, $repository, $creator ) )->register();
		}
	);
}
