<?php
/**
 * 管理画面。
 *
 * @package SafeBackupManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理ページの表示と、作成・ダウンロード・削除リクエストの処理。
 *
 * 状態を変更する操作はすべて admin-post.php 経由で受け付け、
 * 「権限チェック → nonce 検証 → 処理 → リダイレクト（PRG パターン）」の順で処理する。
 */
class SBM_Admin {

	/**
	 * 操作に必要な権限。バックアップには DB の全データ（ユーザー情報等）が含まれるため管理者に限定する。
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * 管理ページのスラッグ。
	 */
	const PAGE_SLUG = 'safe-backup-manager';

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
	 * 作成処理。
	 *
	 * @var SBM_Backup_Creator
	 */
	private $creator;

	/**
	 * コンストラクタ。
	 *
	 * @param SBM_Storage           $storage    保存先。
	 * @param SBM_Backup_Repository $repository 履歴。
	 * @param SBM_Backup_Creator    $creator    作成処理。
	 */
	public function __construct( SBM_Storage $storage, SBM_Backup_Repository $repository, SBM_Backup_Creator $creator ) {
		$this->storage    = $storage;
		$this->repository = $repository;
		$this->creator    = $creator;
	}

	/**
	 * フックを登録する。
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_sbm_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_sbm_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_sbm_delete', array( $this, 'handle_delete' ) );
	}

	/**
	 * 「ツール」メニュー配下に管理ページを追加する。
	 */
	public function add_menu() {
		add_management_page(
			'Safe Backup Manager',
			'バックアップ',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * 管理ページを表示する。
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'このページにアクセスする権限がありません。', 'safe-backup-manager' ), 403 );
		}

		$records = $this->repository->all();
		?>
		<div class="wrap">
			<h1>Safe Backup Manager</h1>

			<?php $this->render_notice(); ?>
			<?php $this->render_storage_warning(); ?>

			<p><?php esc_html_e( 'wp-content ディレクトリ（テーマ・プラグイン・メディア）とデータベースを1つの ZIP ファイルにまとめて保存します。', 'safe-backup-manager' ); ?></p>
			<p class="description">
				<?php esc_html_e( '保存先:', 'safe-backup-manager' ); ?>
				<code><?php echo esc_html( $this->storage->get_dir() ); ?></code>
				<?php if ( $this->storage->is_inside_document_root() ) : ?>
					（<?php esc_html_e( 'ドキュメントルート内', 'safe-backup-manager' ); ?>）
				<?php else : ?>
					（<?php esc_html_e( 'ドキュメントルート外・Web から直接アクセス不可', 'safe-backup-manager' ); ?>）
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='<?php echo esc_js( __( '作成中…（このページを閉じずにお待ちください）', 'safe-backup-manager' ) ); ?>';">
				<input type="hidden" name="action" value="sbm_create">
				<?php wp_nonce_field( 'sbm_create' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'バックアップを作成', 'safe-backup-manager' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'バックアップ履歴', 'safe-backup-manager' ); ?></h2>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( '作成日時', 'safe-backup-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'ファイル名', 'safe-backup-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'ファイルサイズ', 'safe-backup-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( '内容', 'safe-backup-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( '操作', 'safe-backup-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $records ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'バックアップはまだありません。', 'safe-backup-manager' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $records as $record ) : ?>
					<?php $exists = $this->record_file_exists( $record ); ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $record['created_at'] ) ); ?></td>
						<td>
							<code><?php echo esc_html( $record['filename'] ); ?></code><br>
							<small title="<?php echo esc_attr( $record['sha256'] ); ?>">SHA-256: <?php echo esc_html( substr( $record['sha256'], 0, 16 ) ); ?>…</small>
						</td>
						<td><?php echo esc_html( size_format( (int) $record['size'], 1 ) ); ?></td>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: number of files, 2: number of tables */
									__( 'ファイル %1$s 件 / テーブル %2$s 件', 'safe-backup-manager' ),
									number_format_i18n( (int) $record['file_count'] ),
									number_format_i18n( (int) $record['table_count'] )
								)
							);
							?>
							<?php if ( ! empty( $record['skipped_count'] ) ) : ?>
								<br><span style="color:#b32d2e;">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: number of skipped files */
										__( '読み取れずスキップ: %s 件', 'safe-backup-manager' ),
										number_format_i18n( (int) $record['skipped_count'] )
									)
								);
								?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $exists ) : ?>
								<a class="button" href="<?php echo esc_url( $this->download_url( $record['id'] ) ); ?>"><?php esc_html_e( 'ダウンロード', 'safe-backup-manager' ); ?></a>
							<?php else : ?>
								<span style="color:#b32d2e;"><?php esc_html_e( 'ファイルが見つかりません', 'safe-backup-manager' ); ?></span>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
								onsubmit="return confirm('<?php echo esc_js( __( 'このバックアップを削除します。元に戻せません。よろしいですか？', 'safe-backup-manager' ) ); ?>');">
								<input type="hidden" name="action" value="sbm_delete">
								<input type="hidden" name="backup" value="<?php echo esc_attr( $record['id'] ); ?>">
								<?php wp_nonce_field( 'sbm_delete_' . $record['id'] ); ?>
								<button type="submit" class="button button-link-delete"><?php esc_html_e( '削除', 'safe-backup-manager' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * 「バックアップを作成」の処理。
	 */
	public function handle_create() {
		$this->authorize( 'sbm_create' );

		try {
			$record = $this->creator->create();
			$this->set_notice(
				'success',
				sprintf(
					'バックアップを作成しました: %1$s（%2$s）',
					$record['filename'],
					size_format( (int) $record['size'], 1 )
				)
			);
			if ( $record['skipped_count'] > 0 ) {
				$this->set_notice( 'warning', sprintf( '読み取り権限のないファイル %d 件をスキップしました。一覧は ZIP 内の sbm-manifest.json で確認できます。', $record['skipped_count'] ) );
			}
		} catch ( SBM_Exception $e ) {
			$this->set_notice( 'error', 'バックアップの作成に失敗しました: ' . $e->getMessage() );
		} catch ( Throwable $e ) {
			// 想定外のエラーはパス等の内部情報を含みうるため、画面には出さずログに残す。
			error_log( '[Safe Backup Manager] ' . $e ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$this->set_notice( 'error', 'バックアップの作成中に予期しないエラーが発生しました。詳細はサーバーのエラーログを確認してください。' );
		}

		$this->redirect_back();
	}

	/**
	 * ダウンロードの処理。
	 */
	public function handle_download() {
		$id = isset( $_GET['backup'] ) ? sanitize_key( wp_unslash( $_GET['backup'] ) ) : '';
		$this->authorize( 'sbm_download_' . $id );

		// ファイル名ではなく ID で指定させ、登録済みのファイルだけを返す（パストラバーサル対策）。
		$record = $this->repository->find( $id );
		if ( null === $record ) {
			$this->fail( '指定されたバックアップは存在しません。' );
		}

		try {
			$path = $this->storage->path( $record['filename'] );
		} catch ( SBM_Exception $e ) {
			$this->fail( $e->getMessage() );
		}

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			$this->fail( 'バックアップファイルが見つかりません。サーバー上から削除された可能性があります。' );
		}

		$this->send_file( $path, $record['filename'] );
	}

	/**
	 * 削除の処理。
	 */
	public function handle_delete() {
		$id = isset( $_POST['backup'] ) ? sanitize_key( wp_unslash( $_POST['backup'] ) ) : '';
		$this->authorize( 'sbm_delete_' . $id );

		$record = $this->repository->find( $id );
		if ( null === $record ) {
			$this->fail( '指定されたバックアップは存在しません。' );
		}

		try {
			$path = $this->storage->path( $record['filename'] );
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
				if ( file_exists( $path ) ) {
					throw new SBM_Exception( 'バックアップファイルを削除できませんでした。権限を確認してください。' );
				}
			}
			$this->repository->remove( $id );
			$this->set_notice( 'success', 'バックアップを削除しました: ' . $record['filename'] );
		} catch ( SBM_Exception $e ) {
			$this->set_notice( 'error', $e->getMessage() );
		}

		$this->redirect_back();
	}

	/**
	 * 権限と nonce を確認する。
	 *
	 * @param string $action nonce のアクション名。
	 */
	private function authorize( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'safe-backup-manager' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * ZIP ファイルを送信する。
	 *
	 * readfile() は一度にメモリへ載せないが、出力バッファが有効だとそこに溜まるため、
	 * バッファを破棄してから一定サイズずつ送る。
	 *
	 * @param string $path     ファイルパス。
	 * @param string $filename ダウンロード時のファイル名。
	 */
	private function send_file( $path, $filename ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:disable WordPress.WP.AlternativeFunctions
		$handle = fopen( $path, 'rb' );
		if ( false !== $handle ) {
			while ( ! feof( $handle ) ) {
				echo fread( $handle, 1024 * 1024 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- バイナリをそのまま送るため。
				flush();
			}
			fclose( $handle );
		}
		// phpcs:enable

		exit;
	}

	/**
	 * エラー通知を設定して管理ページに戻る。
	 *
	 * @param string $message メッセージ。
	 */
	private function fail( $message ) {
		$this->set_notice( 'error', $message );
		$this->redirect_back();
	}

	/**
	 * 管理ページへリダイレクトする。
	 */
	private function redirect_back() {
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * 次回の画面表示で出す通知を保存する。
	 *
	 * メッセージを URL に載せると改ざん・偽装表示に使われうるため、ユーザーごとの transient に保存する。
	 *
	 * @param string $type    success / warning / error。
	 * @param string $message メッセージ。
	 */
	private function set_notice( $type, $message ) {
		$key     = 'sbm_notice_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * 保存されている通知を表示して消す。
	 */
	private function render_notice() {
		$key     = 'sbm_notice_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );

		foreach ( $notices as $notice ) {
			wp_admin_notice(
				esc_html( $notice['message'] ),
				array(
					'type'        => in_array( $notice['type'], array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'info',
					'dismissible' => true,
				)
			);
		}
	}

	/**
	 * 保存先がドキュメントルート内かつ nginx の場合の注意を表示する。
	 *
	 * nginx は .htaccess を読まないため、URL を推測されると直接ダウンロードされうる。
	 */
	private function render_storage_warning() {
		global $is_nginx;

		if ( ! $is_nginx || ! $this->storage->is_inside_document_root() ) {
			return;
		}

		wp_admin_notice(
			esc_html__( 'このサーバーは nginx のため、保存先の .htaccess による直接アクセス禁止が効きません。保存先のディレクトリ名・ファイル名はランダム化していますが、wp-config.php で定数 SBM_BACKUP_DIR にドキュメントルート外のディレクトリを指定するか、nginx の設定で保存先へのアクセスを拒否してください（README 参照）。', 'safe-backup-manager' ),
			array( 'type' => 'warning' )
		);
	}

	/**
	 * 履歴のファイルが実在するか。
	 *
	 * @param array<string, mixed> $record 履歴。
	 * @return bool
	 */
	private function record_file_exists( array $record ) {
		try {
			return is_file( $this->storage->path( $record['filename'] ) );
		} catch ( SBM_Exception $e ) {
			return false;
		}
	}

	/**
	 * ダウンロード用 URL（nonce 付き）。
	 *
	 * @param string $id バックアップID。
	 * @return string
	 */
	private function download_url( $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'sbm_download',
					'backup' => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'sbm_download_' . $id
		);
	}
}
