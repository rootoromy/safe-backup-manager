<?php
/**
 * データベースのSQLダンプ出力。
 *
 * @package SafeBackupManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * $wpdb を使ってテーブル構造とデータを SQL ファイルに書き出す。
 *
 * mysqldump コマンドは共用レンタルサーバーで exec() が禁止されていることが多いため使わない。
 * メモリ使用量を抑えるため、行は一定件数ずつ取得してそのままファイルへ書き出す。
 */
class SBM_Database_Exporter {

	/**
	 * 1回のクエリで取得する行数。
	 */
	const ROWS_PER_QUERY = 500;

	/**
	 * 1つの INSERT 文にまとめる行数。
	 */
	const ROWS_PER_INSERT = 100;

	/**
	 * 対象テーブル（$wpdb->prefix で始まるもの）をダンプする。
	 *
	 * @param string $path 出力先ファイルパス。
	 * @throws SBM_Exception 出力に失敗した場合。
	 * @return string[] ダンプしたテーブル名。
	 */
	public function export( $path ) {
		global $wpdb;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'wb' );
		if ( false === $handle ) {
			throw new SBM_Exception( 'データベースのバックアップファイルを作成できませんでした。保存先の書き込み権限と空き容量を確認してください。' );
		}

		try {
			$tables = $this->get_tables();
			if ( empty( $tables ) ) {
				throw new SBM_Exception( 'バックアップ対象のデータベーステーブルが見つかりませんでした。' );
			}

			$this->write(
				$handle,
				"-- Safe Backup Manager SQL dump\n"
				. '-- Created: ' . gmdate( 'c' ) . "\n"
				. '-- Table prefix: ' . $wpdb->prefix . "\n\n"
				. "SET NAMES utf8mb4;\n"
				. "SET FOREIGN_KEY_CHECKS = 0;\n"
				. "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n"
			);

			foreach ( $tables as $table ) {
				$this->export_table( $handle, $table );
			}

			$this->write( $handle, "SET FOREIGN_KEY_CHECKS = 1;\n" );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return $tables;
	}

	/**
	 * このサイトのテーブル一覧を返す。
	 *
	 * 同じデータベースに別サイトのテーブルが同居している場合を考え、prefix で絞り込む。
	 *
	 * @return string[]
	 */
	private function get_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
	}

	/**
	 * 1テーブル分の CREATE 文と INSERT 文を書き出す。
	 *
	 * @param resource $handle ファイルハンドル。
	 * @param string   $table  テーブル名（SHOW TABLES の結果のみを渡す）。
	 * @throws SBM_Exception 取得に失敗した場合。
	 */
	private function export_table( $handle, $table ) {
		global $wpdb;

		$quoted = '`' . str_replace( '`', '``', $table ) . '`';

		// テーブル名はプレースホルダで渡せないため、SHOW TABLES の結果をバッククォートでエスケープして使う。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$create = $wpdb->get_row( "SHOW CREATE TABLE {$quoted}", ARRAY_N );
		if ( empty( $create[1] ) ) {
			throw new SBM_Exception( sprintf( 'テーブル %s の構造を取得できませんでした。', $table ) );
		}

		$this->write( $handle, "--\n-- Table: {$table}\n--\n\nDROP TABLE IF EXISTS {$quoted};\n{$create[1]};\n\n" );

		$where    = $this->build_where( $table );
		$order_by = $this->build_order_by( $quoted );

		$offset = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$quoted}{$where}{$order_by} LIMIT %d OFFSET %d", self::ROWS_PER_QUERY, $offset ), ARRAY_N );

			if ( '' !== $wpdb->last_error ) {
				throw new SBM_Exception( sprintf( 'テーブル %s のデータを取得できませんでした。', $table ) );
			}

			foreach ( array_chunk( $rows, self::ROWS_PER_INSERT ) as $chunk ) {
				$values = array();
				foreach ( $chunk as $row ) {
					$values[] = '(' . implode( ',', array_map( array( $this, 'quote' ), $row ) ) . ')';
				}
				$this->write( $handle, "INSERT INTO {$quoted} VALUES\n" . implode( ",\n", $values ) . ";\n" );
			}

			$offset += self::ROWS_PER_QUERY;
		} while ( count( $rows ) === self::ROWS_PER_QUERY );

		$this->write( $handle, "\n" );
	}

	/**
	 * 除外する行の条件を返す。
	 *
	 * 実行中ロック（sbm_backup_lock）と管理画面通知の一時データは、復元すると
	 * 「実行中のまま」の状態が戻ってしまい次のバックアップを妨げるため、ダンプに含めない。
	 *
	 * @param string $table テーブル名。
	 * @return string 先頭に空白を含む WHERE 句、または空文字。
	 */
	private function build_where( $table ) {
		global $wpdb;

		if ( $table !== $wpdb->options ) {
			return '';
		}

		return $wpdb->prepare(
			' WHERE option_name <> %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s',
			SBM_Backup_Creator::LOCK_OPTION,
			$wpdb->esc_like( '_transient_sbm_notice_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sbm_notice_' ) . '%'
		);
	}

	/**
	 * 主キー順に並べる ORDER BY 句を返す。
	 *
	 * ORDER BY なしの LIMIT/OFFSET は行の順序が保証されず、ページ間で行の重複・欠落が起こりうるため。
	 *
	 * @param string $quoted エスケープ済みテーブル名。
	 * @return string 先頭に空白を含む ORDER BY 句、または空文字（主キーがない場合）。
	 */
	private function build_order_by( $quoted ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$keys = $wpdb->get_results( "SHOW KEYS FROM {$quoted} WHERE Key_name = 'PRIMARY'", ARRAY_A );
		if ( empty( $keys ) ) {
			return '';
		}

		usort(
			$keys,
			static function ( $a, $b ) {
				return (int) $a['Seq_in_index'] - (int) $b['Seq_in_index'];
			}
		);

		$columns = array_map(
			static function ( $key ) {
				return '`' . str_replace( '`', '``', $key['Column_name'] ) . '`';
			},
			$keys
		);

		return ' ORDER BY ' . implode( ', ', $columns );
	}

	/**
	 * 値を SQL リテラルに変換する。
	 *
	 * esc_sql() は「%」をプレースホルダ用のハッシュ文字列に置き換えるため、ダンプ用途には使えない。
	 * 接続中の mysqli の文字コード設定に従ってエスケープする。
	 *
	 * @param string|null $value 値。
	 * @return string
	 */
	private function quote( $value ) {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}

		if ( $wpdb->dbh instanceof mysqli ) {
			return "'" . mysqli_real_escape_string( $wpdb->dbh, $value ) . "'";
		}

		// mysqli 以外のドライバ（drop-in 等）の場合のフォールバック。
		return "'" . addcslashes( $value, "\\\0\n\r'\"\x1a" ) . "'";
	}

	/**
	 * ファイルに書き込む。
	 *
	 * @param resource $handle ファイルハンドル。
	 * @param string   $data   書き込む文字列。
	 * @throws SBM_Exception 書き込みに失敗した場合（容量不足など）。
	 */
	private function write( $handle, $data ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( false === fwrite( $handle, $data ) ) {
			throw new SBM_Exception( 'データベースのバックアップファイルへの書き込みに失敗しました。サーバーの空き容量を確認してください。' );
		}
	}
}
