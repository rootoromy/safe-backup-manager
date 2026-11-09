<?php
/**
 * バックアップ保存先ディレクトリの管理。
 *
 * @package SafeBackupManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 保存先の決定・作成・直接アクセス対策を担当する。
 *
 * 保存先の優先順位:
 * 1. wp-config.php の定数 SBM_BACKUP_DIR（明示指定）
 * 2. WordPress 設置ディレクトリの1つ上（＝ドキュメントルート外。Web から直接アクセスできない）
 * 3. 2 に書き込めない場合のみ uploads 配下
 * 2・3 ともディレクトリ名は sbm-backups-<ランダム20文字> とし、URL を推測されにくくする。
 */
class SBM_Storage {

	/**
	 * 決定した保存先の絶対パスを保存するオプション名。
	 */
	const DIR_OPTION = 'sbm_storage_dir';

	/**
	 * 保存先ディレクトリの絶対パス（末尾スラッシュなし）を返す。
	 *
	 * @return string
	 */
	public function get_dir() {
		if ( defined( 'SBM_BACKUP_DIR' ) && '' !== SBM_BACKUP_DIR ) {
			return untrailingslashit( wp_normalize_path( SBM_BACKUP_DIR ) );
		}

		// 一度決めた保存先は固定する（毎回変わると既存のバックアップを見失うため）。
		$stored = get_option( self::DIR_OPTION );
		if ( is_string( $stored ) && preg_match( '#/sbm-backups-[A-Za-z0-9]{20}$#', $stored ) && is_dir( $stored ) ) {
			return $stored;
		}

		$dir = $this->choose_dir();
		update_option( self::DIR_OPTION, $dir, false );

		return $dir;
	}

	/**
	 * 新しい保存先を決める。
	 *
	 * @return string
	 */
	private function choose_dir() {
		$name = 'sbm-backups-' . wp_generate_password( 20, false, false );

		$uploads    = wp_upload_dir( null, false );
		$candidates = array(
			dirname( untrailingslashit( wp_normalize_path( ABSPATH ) ) ),
			untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ),
		);

		foreach ( $candidates as $parent ) {
			// open_basedir の制限外だと警告が出るため抑止する（その場合は次の候補へ）。
			if ( @is_dir( $parent ) && wp_is_writable( $parent ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $parent . '/' . $name;
			}
		}

		// どこにも書き込めない場合も値は返し、prepare() で分かりやすいエラーにする。
		return end( $candidates ) . '/' . $name;
	}

	/**
	 * 保存先がドキュメントルート（ABSPATH）配下にあるかどうか。
	 *
	 * @return bool
	 */
	public function is_inside_document_root() {
		$root = trailingslashit( wp_normalize_path( ABSPATH ) );

		return 0 === strpos( trailingslashit( $this->get_dir() ), $root );
	}

	/**
	 * 保存先ディレクトリを作成し、直接アクセス対策のファイルを配置する。
	 *
	 * @throws SBM_Exception 作成・書き込みに失敗した場合。
	 * @return string 保存先ディレクトリ。
	 */
	public function prepare() {
		$dir = $this->get_dir();

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new SBM_Exception( 'バックアップの保存先ディレクトリを作成できませんでした。ディレクトリの書き込み権限を確認してください。' );
		}

		if ( ! wp_is_writable( $dir ) ) {
			throw new SBM_Exception( 'バックアップの保存先ディレクトリに書き込めません。ディレクトリの書き込み権限を確認してください。' );
		}

		$this->write_protection_files( $dir );

		return $dir;
	}

	/**
	 * Webサーバーごとの直接アクセス禁止ファイルを配置する。
	 *
	 * - .htaccess  : Apache 用
	 * - web.config : IIS 用
	 * - index.php / index.html : ディレクトリ一覧の表示防止
	 * nginx はこれらを読まないため、ランダムなディレクトリ名＋ファイル名と、
	 * README に記載のサーバー設定（または SBM_BACKUP_DIR でドキュメントルート外に置く）で対応する。
	 *
	 * @param string $dir 保存先ディレクトリ。
	 * @throws SBM_Exception 書き込みに失敗した場合。
	 */
	private function write_protection_files( $dir ) {
		$files = array(
			'.htaccess'  => "# Safe Backup Manager: deny direct access\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		);

		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( file_exists( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- ZipArchive がローカルパスを前提とするため、保存先は直接ファイルシステムで扱う。
			if ( false === file_put_contents( $path, $content ) ) {
				throw new SBM_Exception( 'バックアップ保存先の保護ファイルを作成できませんでした。ディレクトリの書き込み権限を確認してください。' );
			}
		}
	}

	/**
	 * 保存先内のファイルの絶対パスを返す。
	 *
	 * ファイル名は内部で生成した値か、リポジトリに登録済みの値のみを受け付ける。
	 * 念のためパス区切りや「..」を含む名前は拒否する。
	 *
	 * @param string $filename ファイル名。
	 * @throws SBM_Exception 不正なファイル名の場合。
	 * @return string
	 */
	public function path( $filename ) {
		if ( '' === $filename || basename( $filename ) !== $filename || false !== strpos( $filename, '..' ) ) {
			throw new SBM_Exception( '不正なファイル名です。' );
		}

		return $this->get_dir() . '/' . $filename;
	}
}
