<?php
/**
 * 利用者に表示してよいエラーを表す例外。
 *
 * @package SafeBackupManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * メッセージをそのまま管理画面に表示してよい例外。
 *
 * これ以外の Throwable はサーバーのパスなど内部情報を含む可能性があるため、
 * 画面には汎用メッセージのみを出し、詳細は error_log に記録する。
 */
class SBM_Exception extends RuntimeException {
}
