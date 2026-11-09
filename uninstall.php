<?php
/**
 * アンインストール時の処理。
 *
 * 設定・履歴のオプションのみ削除する。
 * バックアップ ZIP は誤操作で失うと取り返しがつかないため、あえて削除しない（README 参照）。
 *
 * @package SafeBackupManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sbm_backups' );
delete_option( 'sbm_backup_lock' );
// sbm_storage_dir は残す。再インストール時に同じ保存先を使い、既存の ZIP を見失わないようにするため。
