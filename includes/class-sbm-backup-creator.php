<?php
/**
 * バックアップZIPの作成。
 *
 * @package SafeBackupManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp-content とデータベースを1つの ZIP にまとめる。
 *
 * ZIP 内の構成:
 *   wp-content/...          wp-content のディレクトリ構造をそのまま保持
 *   database/database.sql   データベースのダンプ
 *   sbm-manifest.json       作成日時・WordPress バージョン等の復旧用メタ情報
 */
class SBM_Backup_Creator {

	/**
	 * 多重実行を防ぐロック用オプション名。
	 */
	const LOCK_OPTION = 'sbm_backup_lock';

	/**
	 * ロックが異常終了で残った場合に無効とみなすまでの秒数。
	 */
	const LOCK_TIMEOUT = 2 * HOUR_IN_SECONDS;

	/**
	 * 空き容量チェックの余裕分（推定サイズに対する倍率）。
	 * ZIP 本体＋作成中の SQL ファイル分を見込んで多めに取る。
	 */
	const DISK_SPACE_MARGIN = 1.2;

	/**
	 * 圧縮しても小さくならない拡張子（CPU 負荷軽減のため無圧縮で格納する）。
	 */
	const STORE_ONLY_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'mov', 'mp3', 'zip', 'gz', 'woff', 'woff2', 'pdf' );

	/**
	 * 保存先。
	 *
	 * @var SBM_Storage
	 */
	private $storage;

	/**
	 * 履歴。
	 *
	 * @var SBM_Backup_Repository
	 */
	private $repository;

	/**
	 * DB エクスポーター。
	 *
	 * @var SBM_Database_Exporter
	 */
	private $exporter;

	/**
	 * コンストラクタ。
	 *
	 * @param SBM_Storage           $storage    保存先。
	 * @param SBM_Backup_Repository $repository 履歴。
	 * @param SBM_Database_Exporter $exporter   DB エクスポーター。
	 */
	public function __construct( SBM_Storage $storage, SBM_Backup_Repository $repository, SBM_Database_Exporter $exporter ) {
		$this->storage    = $storage;
		$this->repository = $repository;
		$this->exporter   = $exporter;
	}

	/**
	 * バックアップを作成する。
	 *
	 * @throws SBM_Exception 作成に失敗した場合（途中のファイルは削除済み）。
	 * @return array<string, mixed> 作成したバックアップの履歴レコード。
	 */
	public function create() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new SBM_Exception( 'このサーバーでは PHP の zip 拡張が有効になっていないため、バックアップを作成できません。サーバー管理者に確認してください。' );
		}

		$this->acquire_lock();

		$part_path = '';
		$sql_path  = '';

		try {
			$this->extend_limits();

			$dir     = $this->storage->prepare();
			$sources = $this->get_sources();
			$files   = $this->collect_files( $sources );

			$this->check_disk_space( $dir, $files['total_size'] );

			$id        = bin2hex( random_bytes( 16 ) );
			$filename  = sprintf( 'backup-%s-%s.zip', wp_date( 'Ymd-His' ), substr( $id, 0, 12 ) );
			$zip_path  = $this->storage->path( $filename );
			$part_path = $zip_path . '.part';
			$sql_path  = $this->storage->path( 'database-' . $id . '.sql.part' );

			$tables = $this->exporter->export( $sql_path );

			$manifest = array(
				'plugin'         => 'safe-backup-manager',
				'plugin_version' => SBM_VERSION,
				'created_at'     => gmdate( 'c' ),
				'site_url'       => site_url(),
				'home_url'       => home_url(),
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
				'table_prefix'   => $GLOBALS['wpdb']->prefix,
				'tables'         => $tables,
				'file_count'     => count( $files['entries'] ),
				'skipped_files'  => $files['skipped'],
				'excluded'       => $this->get_excluded_paths(),
			);

			$this->build_zip( $part_path, $files['entries'], $sql_path, $manifest );
			$this->verify_zip( $part_path, count( $files['entries'] ) + 2 );

			if ( ! rename( $part_path, $zip_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				throw new SBM_Exception( 'バックアップファイルの保存に失敗しました。' );
			}
			$part_path = '';

			$record = array(
				'id'            => $id,
				'filename'      => $filename,
				'created_at'    => time(),
				'size'          => (int) filesize( $zip_path ),
				'sha256'        => hash_file( 'sha256', $zip_path ),
				'file_count'    => count( $files['entries'] ),
				'table_count'   => count( $tables ),
				'skipped_count' => count( $files['skipped'] ),
				'wp_version'    => get_bloginfo( 'version' ),
				'created_by'    => get_current_user_id(),
			);
			$this->repository->add( $record );

			return $record;
		} finally {
			// 失敗時に中途半端なファイルを残さない（成功時は $part_path を空にしてある）。
			foreach ( array( $part_path, $sql_path ) as $tmp ) {
				if ( '' !== $tmp && file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
			}
			$this->release_lock();
		}
	}

	/**
	 * バックアップ対象ディレクトリ（ZIP 内パス => 実パス）を返す。
	 *
	 * 将来の対象追加（例: ABSPATH 全体）に備え、フィルターで変更できるようにしている。
	 *
	 * @throws SBM_Exception 対象ディレクトリが存在しない・読めない場合。
	 * @return array<string, string>
	 */
	private function get_sources() {
		/**
		 * バックアップ対象ディレクトリを変更する。
		 *
		 * @param array<string, string> $sources ZIP 内のルート名 => 実ディレクトリの絶対パス。
		 */
		$sources = apply_filters( 'sbm_backup_sources', array( 'wp-content' => WP_CONTENT_DIR ) );

		if ( empty( $sources ) || ! is_array( $sources ) ) {
			throw new SBM_Exception( 'バックアップ対象のディレクトリが設定されていません。' );
		}

		foreach ( $sources as $name => $path ) {
			if ( ! is_dir( $path ) ) {
				throw new SBM_Exception( sprintf( 'バックアップ対象のディレクトリ「%s」が存在しません。', $name ) );
			}
			if ( ! is_readable( $path ) ) {
				throw new SBM_Exception( sprintf( 'バックアップ対象のディレクトリ「%s」を読み取れません。権限を確認してください。', $name ) );
			}
		}

		return $sources;
	}

	/**
	 * バックアップから除外するパス（正規化済み絶対パス）。
	 *
	 * @return string[]
	 */
	private function get_excluded_paths() {
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );

		$excluded = array(
			$this->storage->get_dir(),       // 自分自身のバックアップ（再帰的に肥大化するのを防ぐ）。
			$content . '/cache',             // キャッシュは再生成できる。
			$content . '/upgrade',           // 更新処理の一時領域。
			$content . '/upgrade-temp-backup',
		);

		/**
		 * バックアップから除外するパスを変更する。
		 *
		 * @param string[] $excluded 絶対パスの配列。
		 */
		$excluded = apply_filters( 'sbm_backup_excluded_paths', $excluded );

		return array_map(
			static function ( $path ) {
				return untrailingslashit( wp_normalize_path( $path ) );
			},
			$excluded
		);
	}

	/**
	 * 対象ファイルを列挙する。
	 *
	 * @param array<string, string> $sources ZIP 内のルート名 => 実パス。
	 * @return array{entries: array<string, string>, skipped: string[], total_size: int}
	 */
	private function collect_files( array $sources ) {
		$excluded   = $this->get_excluded_paths();
		$entries    = array();
		$skipped    = array();
		$total_size = 0;

		foreach ( $sources as $root_name => $root_path ) {
			$root_path = untrailingslashit( wp_normalize_path( $root_path ) );

			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $root_path, FilesystemIterator::SKIP_DOTS ),
					static function ( SplFileInfo $file ) use ( $excluded ) {
						// シンボリックリンクはたどらない（ループやサイト外ファイルの混入を防ぐ）。
						if ( $file->isLink() ) {
							return false;
						}
						return ! in_array( untrailingslashit( wp_normalize_path( $file->getPathname() ) ), $excluded, true );
					}
				),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD // 読めないサブディレクトリがあっても全体は止めない。
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$path     = wp_normalize_path( $file->getPathname() );
				$relative = $root_name . substr( $path, strlen( $root_path ) );

				if ( ! $file->isReadable() ) {
					$skipped[] = $relative;
					continue;
				}

				$entries[ $relative ] = $path;
				$total_size          += (int) $file->getSize();
			}
		}

		return array(
			'entries'    => $entries,
			'skipped'    => $skipped,
			'total_size' => $total_size,
		);
	}

	/**
	 * 空き容量を確認する。
	 *
	 * @param string $dir        保存先。
	 * @param int    $total_size 対象ファイルの合計サイズ。
	 * @throws SBM_Exception 空き容量が足りない場合。
	 */
	private function check_disk_space( $dir, $total_size ) {
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 一部サーバーで無効化されており警告が出るため。

		// 取得できない環境ではチェックを省略する（書き込み失敗時のエラー処理で対応）。
		if ( false === $free ) {
			return;
		}

		$required = (int) ( $total_size * self::DISK_SPACE_MARGIN );
		if ( $free < $required ) {
			throw new SBM_Exception(
				sprintf(
					'サーバーの空き容量が不足しています（必要な目安: %1$s / 空き: %2$s）。不要なバックアップを削除してから再度お試しください。',
					size_format( $required ),
					size_format( $free )
				)
			);
		}
	}

	/**
	 * ZIP を作成する。
	 *
	 * @param string                $zip_path 出力先（.part）。
	 * @param array<string, string> $entries  ZIP 内パス => 実パス。
	 * @param string                $sql_path SQL ダンプ。
	 * @param array<string, mixed>  $manifest マニフェスト。
	 * @throws SBM_Exception 作成に失敗した場合。
	 */
	private function build_zip( $zip_path, array $entries, $sql_path, array $manifest ) {
		$zip    = new ZipArchive();
		$result = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $result ) {
			throw new SBM_Exception( sprintf( 'ZIP ファイルを作成できませんでした（エラーコード: %d）。保存先の書き込み権限を確認してください。', (int) $result ) );
		}

		$can_set_compression = method_exists( $zip, 'setCompressionName' );

		foreach ( $entries as $local_name => $path ) {
			if ( ! $zip->addFile( $path, $local_name ) ) {
				$zip->close();
				throw new SBM_Exception( 'ZIP へのファイル追加に失敗しました。' );
			}

			$ext = strtolower( pathinfo( $local_name, PATHINFO_EXTENSION ) );
			if ( $can_set_compression && in_array( $ext, self::STORE_ONLY_EXTENSIONS, true ) ) {
				$zip->setCompressionName( $local_name, ZipArchive::CM_STORE );
			}
		}

		$zip->addFile( $sql_path, 'database/database.sql' );
		$zip->addFromString( 'sbm-manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		// 実際の圧縮・書き込みは close() のタイミングで行われる。
		if ( ! $zip->close() ) {
			throw new SBM_Exception( 'ZIP ファイルの書き込みに失敗しました。サーバーの空き容量を確認してください。' );
		}
	}

	/**
	 * 作成した ZIP を開き直して整合性を確認する。
	 *
	 * @param string $zip_path ZIP ファイル。
	 * @param int    $expected 期待するエントリ数。
	 * @throws SBM_Exception 検証に失敗した場合。
	 */
	private function verify_zip( $zip_path, $expected ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CHECKCONS ) ) {
			throw new SBM_Exception( '作成した ZIP ファイルの検証に失敗しました（ファイルが破損している可能性があります）。' );
		}

		$count  = $zip->numFiles;
		$has_db = false !== $zip->locateName( 'database/database.sql' );
		$zip->close();

		if ( $count !== $expected || ! $has_db ) {
			throw new SBM_Exception( '作成した ZIP ファイルの内容が想定と一致しません。' );
		}
	}

	/**
	 * 実行時間・メモリの制限を可能な範囲で緩める。
	 */
	private function extend_limits() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 無効化されたサーバーでの警告を抑止。
		}
		ignore_user_abort( true ); // ブラウザを閉じても途中で止まらないようにする。
		wp_raise_memory_limit( 'admin' );
	}

	/**
	 * 多重実行防止のロックを取得する。
	 *
	 * add_option() は既に存在する場合 false を返すため、簡易的な排他制御として使える。
	 *
	 * @throws SBM_Exception 他のバックアップが実行中の場合。
	 */
	private function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION );
		if ( $locked_at > 0 && ( time() - $locked_at ) < self::LOCK_TIMEOUT ) {
			throw new SBM_Exception( '別のバックアップ処理が実行中です。完了してから再度お試しください。' );
		}

		// タイムアウトしたロックは異常終了の残骸とみなして取り直す。
		delete_option( self::LOCK_OPTION );
		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			throw new SBM_Exception( '別のバックアップ処理が実行中です。完了してから再度お試しください。' );
		}
	}

	/**
	 * ロックを解放する。
	 */
	private function release_lock() {
		delete_option( self::LOCK_OPTION );
	}
}
