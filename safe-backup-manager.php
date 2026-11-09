<?php
/**
 * Plugin Name: Safe Backup Manager
 * Description: WordPress管理画面からバックアップの作成・一覧表示・ダウンロードを行うプラグイン（試作）。
 * Version:     0.1.0
 * Author:      rootoromy
 * License:     GPL-2.0-or-later
 * Text Domain: safe-backup-manager
 * Requires PHP: 7.4
 */

// WordPressを経由せずにこのファイルを直接開いた場合は、何もせずに終了する
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
