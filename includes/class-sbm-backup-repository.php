<?php
/**
 * バックアップ履歴の永続化。
 *
 * @package SafeBackupManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * バックアップ履歴を wp_options に保存・取得する。
 *
 * 履歴件数は多くても数十件の想定なので、専用テーブルは作らずオプション1件に配列で保存する。
 * autoload は無効にし、フロント側のページ表示で毎回読み込まれないようにする。
 */
class SBM_Backup_Repository {

	/**
	 * 履歴を保存するオプション名。
	 */
	const OPTION = 'sbm_backups';

	/**
	 * 全履歴を作成日時の新しい順で返す。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		$records = get_option( self::OPTION, array() );
		if ( ! is_array( $records ) ) {
			return array();
		}

		$records = array_values( $records );
		usort(
			$records,
			static function ( $a, $b ) {
				return (int) $b['created_at'] - (int) $a['created_at'];
			}
		);

		return $records;
	}

	/**
	 * ID から履歴を1件取得する。
	 *
	 * @param string $id バックアップID。
	 * @return array<string, mixed>|null
	 */
	public function find( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return null;
		}

		$records = get_option( self::OPTION, array() );

		return ( is_array( $records ) && isset( $records[ $id ] ) ) ? $records[ $id ] : null;
	}

	/**
	 * 履歴を追加する。
	 *
	 * @param array<string, mixed> $record 履歴（id キー必須）。
	 */
	public function add( array $record ) {
		$records = get_option( self::OPTION, array() );
		if ( ! is_array( $records ) ) {
			$records = array();
		}

		$records[ $record['id'] ] = $record;
		update_option( self::OPTION, $records, false );
	}

	/**
	 * 履歴を削除する。
	 *
	 * @param string $id バックアップID。
	 */
	public function remove( $id ) {
		$records = get_option( self::OPTION, array() );
		if ( is_array( $records ) && isset( $records[ $id ] ) ) {
			unset( $records[ $id ] );
			update_option( self::OPTION, $records, false );
		}
	}
}
