<?php
declare(strict_types=1);

namespace CB\ContentMigrator\Admin;

use CB\ContentMigrator\Migration\JobStore;
use CB\ContentMigrator\Migration\PlanStore;
use CB\ContentMigrator\Migration\PostAnalyzer;
use CB\ContentMigrator\Migration\TaxonomyAnalyzer;

defined( 'ABSPATH' ) || exit;

final class Page {
	public const SLUG = 'cb-content-migrator';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register' ] );
	}

	public static function register(): void {
		add_management_page(
			__( 'Core Blueprint Content Migrator', 'core-blueprint-content-migrator' ),
			__( 'Content Migrator', 'core-blueprint-content-migrator' ),
			'manage_options',
			self::SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$job  = JobStore::load_active();
		$plan = PlanStore::load();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Core Blueprint Content Migrator', 'core-blueprint-content-migrator' ); ?></h1>
			<p><?php esc_html_e( 'Copy WordPress content safely, verify it, then roll back or finalize. Analysis is read-only and source content stays intact until you explicitly choose otherwise.', 'core-blueprint-content-migrator' ); ?></p>
			<?php self::notice(); ?>
			<?php
			if ( is_array( $job ) ) {
				self::job( $job );
			} elseif ( is_array( $plan ) ) {
				self::plan( $plan );
			} else {
				self::start();
			}
			?>
		</div>
		<?php
	}

	private static function start(): void {
		$post_types = PostAnalyzer::post_types();
		$source_tax = TaxonomyAnalyzer::taxonomies( false );
		$target_tax = TaxonomyAnalyzer::taxonomies( true );
		?>
		<hr><h2><?php esc_html_e( 'New migration', 'core-blueprint-content-migrator' ); ?></h2>
		<h3><?php esc_html_e( 'Posts / post types', 'core-blueprint-content-migrator' ); ?></h3>
		<p><?php esc_html_e( 'Use this when content already exists in one post type and should be copied into another post type, including selected taxonomies and meta.', 'core-blueprint-content-migrator' ); ?></p>
		<?php self::analysis_form( 'post', $post_types, $post_types ); ?>
		<hr><h3><?php esc_html_e( 'Taxonomies', 'core-blueprint-content-migrator' ); ?></h3>
		<p><?php esc_html_e( 'Copies terms and hierarchy. Existing target terms with the same slug are reused without overwriting their data. Relationships can be remapped when both taxonomies support the same post types.', 'core-blueprint-content-migrator' ); ?></p>
		<?php self::analysis_form( 'taxonomy', $source_tax, $target_tax ); ?>
		<?php
	}

	/** @param array<string,object> $sources @param array<string,object> $targets */
	private static function analysis_form( string $mode, array $sources, array $targets ): void {
		$is_tax = 'taxonomy' === $mode;
		$source_name = $is_tax ? 'source_taxonomy' : 'source_type';
		$target_name = $is_tax ? 'target_taxonomy' : 'target_type';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cb_content_migrator_analyze">
			<input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>">
			<?php wp_nonce_field( 'cb_content_migrator_analyze', 'cb_content_migrator_nonce' ); ?>
			<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row"><?php esc_html_e( 'Source', 'core-blueprint-content-migrator' ); ?></th><td><select name="<?php echo esc_attr( $source_name ); ?>" required><?php self::object_options( $sources ); ?></select></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Target', 'core-blueprint-content-migrator' ); ?></th><td><select name="<?php echo esc_attr( $target_name ); ?>" required><?php self::object_options( $targets ); ?></select></td></tr>
			</tbody></table>
			<?php submit_button( $is_tax ? __( 'Analyze taxonomy migration', 'core-blueprint-content-migrator' ) : __( 'Analyze post migration', 'core-blueprint-content-migrator' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/** @param array<string,mixed> $plan */
	private static function plan( array $plan ): void {
		$mode = sanitize_key( (string) ( $plan['mode'] ?? 'post' ) );
		?>
		<hr><h2><?php esc_html_e( 'Review migration plan', 'core-blueprint-content-migrator' ); ?></h2>
		<p><strong><?php echo esc_html( (string) $plan['source_label'] ); ?></strong> &rarr; <strong><?php echo esc_html( (string) $plan['target_label'] ); ?></strong></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cb_content_migrator_create_job">
			<?php wp_nonce_field( 'cb_content_migrator_create_job', 'cb_content_migrator_nonce' ); ?>
			<?php if ( 'taxonomy' === $mode ) : ?>
				<?php self::taxonomy_plan( $plan ); ?>
			<?php else : ?>
				<?php self::post_plan( $plan ); ?>
			<?php endif; ?>
			<?php self::batch_field(); ?>
			<?php submit_button( __( 'Create safe migration job', 'core-blueprint-content-migrator' ) ); ?>
		</form>
		<?php self::action_form( 'clear_plan', __( 'Start over', 'core-blueprint-content-migrator' ), 'secondary' ); ?>
		<?php
	}

	/** @param array<string,mixed> $plan */
	private static function post_plan( array $plan ): void {
		?>
		<p><?php printf( esc_html__( '%d source posts found. Copies are created first; the source is not moved or deleted during testing.', 'core-blueprint-content-migrator' ), (int) $plan['total'] ); ?></p>
		<?php self::post_source_selection( $plan ); ?>
		<h3><?php esc_html_e( 'Taxonomy mapping', 'core-blueprint-content-migrator' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Choose only mappings you understand. Missing target terms may be created and are included in rollback. Machine-owned target taxonomies are excluded.', 'core-blueprint-content-migrator' ); ?></p>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Source', 'core-blueprint-content-migrator' ); ?></th><th><?php esc_html_e( 'Target', 'core-blueprint-content-migrator' ); ?></th></tr></thead><tbody>
		<?php foreach ( (array) $plan['source_taxonomies'] as $source => $info ) : ?>
			<tr><td><?php echo esc_html( (string) $info['label'] ); ?> <code><?php echo esc_html( (string) $source ); ?></code></td><td><select name="tax_map[<?php echo esc_attr( (string) $source ); ?>]"><option value=""><?php esc_html_e( 'Skip', 'core-blueprint-content-migrator' ); ?></option><?php foreach ( (array) $plan['target_taxonomies'] as $target => $target_info ) : ?><option value="<?php echo esc_attr( (string) $target ); ?>" <?php selected( (string) $source, (string) $target ); ?>><?php echo esc_html( (string) $target_info['label'] ); ?> (<?php echo esc_html( (string) $target ); ?>)</option><?php endforeach; ?></select></td></tr>
		<?php endforeach; ?>
		<?php if ( empty( $plan['source_taxonomies'] ) ) : ?><tr><td colspan="2"><?php esc_html_e( 'No source taxonomies found.', 'core-blueprint-content-migrator' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php self::meta_map( (array) $plan['source_meta_keys'], (array) $plan['target_meta_keys'], 'meta_map', __( 'Post meta mapping', 'core-blueprint-content-migrator' ) ); ?>
		<h3><?php esc_html_e( 'Copy options', 'core-blueprint-content-migrator' ); ?></h3>
		<p><label><input type="checkbox" name="copy_featured_image" value="1" checked> <?php esc_html_e( 'Reuse the same featured-image attachment when supported by the target.', 'core-blueprint-content-migrator' ); ?></label></p>
		<?php
	}


	/** @param array<string,mixed> $plan */
	private static function post_source_selection( array $plan ): void {
		$source_type = sanitize_key( (string) ( $plan['source_type'] ?? '' ) );
		$source_ids = array_values( array_unique( array_map( 'intval', (array) ( $plan['source_ids'] ?? [] ) ) ) );
		?>
		<h3><?php esc_html_e( 'Select source posts', 'core-blueprint-content-migrator' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Choose which analyzed posts to include in this migration. All available posts are selected by default.', 'core-blueprint-content-migrator' ); ?></p>
		<table class="wp-list-table widefat fixed striped table-view-list posts">
			<thead><tr>
				<td id="cb" class="manage-column column-cb check-column"><input id="cb-select-all-1" type="checkbox" checked><label for="cb-select-all-1"><span class="screen-reader-text"><?php esc_html_e( 'Select all', 'core-blueprint-content-migrator' ); ?></span></label></td>
				<th scope="col"><?php esc_html_e( 'Title', 'core-blueprint-content-migrator' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'core-blueprint-content-migrator' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Author', 'core-blueprint-content-migrator' ); ?></th>
				<th scope="col">ID</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $source_ids as $source_id ) : ?>
				<?php
				$post = get_post( $source_id );
				$available = $post instanceof \WP_Post && $source_type === $post->post_type;
				$title = $available ? trim( wp_strip_all_tags( (string) $post->post_title ) ) : '';
				$title = '' !== $title ? $title : __( '(no title)', 'core-blueprint-content-migrator' );
				$status_object = $available ? get_post_status_object( (string) $post->post_status ) : null;
				$status = $available
					? ( $status_object instanceof \stdClass ? (string) $status_object->label : (string) $post->post_status )
					: __( 'Unavailable', 'core-blueprint-content-migrator' );
				$author = $available ? (string) get_the_author_meta( 'display_name', (int) $post->post_author ) : '—';
				?>
				<tr>
					<th scope="row" class="check-column"><input id="cb-select-<?php echo esc_attr( (string) $source_id ); ?>" type="checkbox" name="source_ids[]" value="<?php echo esc_attr( (string) $source_id ); ?>" checked <?php disabled( ! $available ); ?>></th>
					<td><label for="cb-select-<?php echo esc_attr( (string) $source_id ); ?>"><strong><?php echo esc_html( $title ); ?></strong></label></td>
					<td><?php echo esc_html( $status ); ?></td>
					<td><?php echo esc_html( $author ); ?></td>
					<td><?php echo esc_html( (string) $source_id ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** @param array<string,mixed> $plan */
	private static function taxonomy_plan( array $plan ): void {
		?>
		<p><?php printf( esc_html__( '%d source terms found.', 'core-blueprint-content-migrator' ), count( (array) $plan['source_ids'] ) ); ?></p>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Conflict rule: an existing target term with the same slug is reused and never overwritten. Only newly created terms receive mapped term meta and rollback markers.', 'core-blueprint-content-migrator' ); ?></p></div>
		<?php if ( ! empty( $plan['source_hierarchical'] ) && empty( $plan['target_hierarchical'] ) ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'The target taxonomy is flat, so source parent/child relationships cannot be preserved.', 'core-blueprint-content-migrator' ); ?></p></div><?php endif; ?>
		<?php self::meta_map( (array) $plan['source_meta_keys'], (array) $plan['target_meta_keys'], 'term_meta_map', __( 'Term meta mapping', 'core-blueprint-content-migrator' ) ); ?>
		<h3><?php esc_html_e( 'Relationships', 'core-blueprint-content-migrator' ); ?></h3>
		<?php if ( ! empty( $plan['relationships_supported'] ) && (int) $plan['relationship_total'] > 0 ) : ?>
			<p><label><input type="checkbox" name="copy_relationships" value="1" checked> <?php printf( esc_html__( 'Remap relationships for %d affected posts.', 'core-blueprint-content-migrator' ), (int) $plan['relationship_total'] ); ?></label></p>
			<p class="description"><?php echo esc_html( implode( ', ', (array) $plan['shared_object_types'] ) ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'No safely remappable shared post relationships were found. Terms can still be migrated.', 'core-blueprint-content-migrator' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/** @param string[] $sources @param string[] $targets */
	private static function meta_map( array $sources, array $targets, string $field, string $title ): void {
		?><h3><?php echo esc_html( $title ); ?></h3><p class="description"><?php esc_html_e( 'Leave a target key empty to skip it. Registered target keys are shown as suggestions, but another valid key may be entered explicitly.', 'core-blueprint-content-migrator' ); ?></p>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Source key', 'core-blueprint-content-migrator' ); ?></th><th><?php esc_html_e( 'Target key', 'core-blueprint-content-migrator' ); ?></th></tr></thead><tbody>
		<?php foreach ( $sources as $source ) : ?><tr><td><code><?php echo esc_html( (string) $source ); ?></code></td><td><input type="text" name="<?php echo esc_attr( $field ); ?>[<?php echo esc_attr( (string) $source ); ?>]" list="cb_cm_<?php echo esc_attr( $field ); ?>_keys" class="regular-text" value="<?php echo in_array( $source, $targets, true ) ? esc_attr( (string) $source ) : ''; ?>"></td></tr><?php endforeach; ?>
		<?php if ( empty( $sources ) ) : ?><tr><td colspan="2"><?php esc_html_e( 'No source meta keys found.', 'core-blueprint-content-migrator' ); ?></td></tr><?php endif; ?>
		</tbody></table><datalist id="cb_cm_<?php echo esc_attr( $field ); ?>_keys"><?php foreach ( $targets as $target ) : ?><option value="<?php echo esc_attr( (string) $target ); ?>"><?php endforeach; ?></datalist><?php
	}

	private static function batch_field(): void {
		?><h3><?php esc_html_e( 'Batch size', 'core-blueprint-content-migrator' ); ?></h3><p><input type="number" name="batch_size" min="10" max="200" value="50" class="small-text"> <span class="description"><?php esc_html_e( 'Items per request. Use a lower value on slower shared hosting.', 'core-blueprint-content-migrator' ); ?></span></p><?php
	}

	/** @param array<string,mixed> $job */
	private static function job( array $job ): void {
		$mode   = sanitize_key( (string) ( $job['mode'] ?? 'post' ) );
		$status = sanitize_key( (string) ( $job['status'] ?? 'unknown' ) );
		$owner  = (int) ( $job['owner_user_id'] ?? 0 );
		$is_owner = $owner > 0 && $owner === get_current_user_id();
		?>
		<hr><h2><?php esc_html_e( 'Active migration', 'core-blueprint-content-migrator' ); ?></h2>
		<p><strong><?php echo esc_html( ucfirst( $mode ) ); ?></strong> · <?php echo esc_html( (string) $job['source_label'] ); ?> &rarr; <?php echo esc_html( (string) $job['target_label'] ); ?></p>
		<p><?php printf( esc_html__( 'Status: %1$s · processed %2$d/%3$d', 'core-blueprint-content-migrator' ), esc_html( $status ), (int) ( $job['cursor'] ?? 0 ), (int) ( $job['total'] ?? 0 ) ); ?></p>
		<?php if ( ! $is_owner ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This migration is owned by another administrator. Take ownership explicitly before continuing, verifying, finalizing or rolling it back.', 'core-blueprint-content-migrator' ); ?></p></div>
			<?php self::takeover_form( $job ); ?>
			<?php return; ?>
		<?php endif; ?>
		<?php if ( ! empty( $job['errors'] ) ) : ?><div class="notice notice-warning inline"><p><?php printf( esc_html__( '%d issues were recorded. Do not finalize before verification passes.', 'core-blueprint-content-migrator' ), count( (array) $job['errors'] ) ); ?></p></div><?php endif; ?>
		<?php if ( in_array( $status, [ 'ready', 'copying', 'copying_terms', 'copying_relationships' ], true ) ) : ?>
			<p><?php esc_html_e( 'The source is still untouched. Continue until copying is complete.', 'core-blueprint-content-migrator' ); ?></p><?php self::action_form( 'run_batch', 'ready' === $status ? __( 'Start migration', 'core-blueprint-content-migrator' ) : __( 'Run next batch', 'core-blueprint-content-migrator' ), 'primary', $job ); ?>
		<?php elseif ( in_array( $status, [ 'copied', 'verification_failed' ], true ) ) : ?>
			<?php self::verification( $job ); self::action_form( 'verify', __( 'Verify migrated content', 'core-blueprint-content-migrator' ), 'primary', $job ); ?>
		<?php elseif ( in_array( $status, [ 'verified', 'finalization_failed' ], true ) ) : ?>
			<?php self::verification( $job ); ?>
			<?php if ( 'finalization_failed' === $status ) : ?>
				<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Finalization did not complete. The migration remains active and its rollback markers are still preserved.', 'core-blueprint-content-migrator' ); ?></strong></p></div>
				<?php if ( is_array( $job['finalization'] ?? null ) && ! empty( $job['finalization']['issues'] ) ) : ?><ul><?php foreach ( array_slice( (array) $job['finalization']['issues'], 0, 20 ) as $issue ) : ?><li><?php echo esc_html( (string) $issue ); ?></li><?php endforeach; ?></ul><?php endif; ?>
			<?php endif; ?>
			<h3><?php esc_html_e( 'Finalize', 'core-blueprint-content-migrator' ); ?></h3>
			<?php if ( 'taxonomy' === $mode ) : ?><p><?php esc_html_e( 'WordPress terms have no Trash. RC1 therefore always keeps the source taxonomy when finalizing.', 'core-blueprint-content-migrator' ); ?></p><?php self::finalize_form( $job, false, __( 'Finalize & keep source taxonomy', 'core-blueprint-content-migrator' ) ); ?>
			<?php else : ?><p><?php esc_html_e( 'For a first test, keep the source. Moving source posts to Trash is available only after verification passes.', 'core-blueprint-content-migrator' ); ?></p><?php self::finalize_form( $job, false, __( 'Finalize & keep source', 'core-blueprint-content-migrator' ) ); self::finalize_form( $job, true, __( 'Finalize & move source to Trash', 'core-blueprint-content-migrator' ) ); ?><?php endif; ?>
		<?php elseif ( 'rollback_failed' === $status ) : ?><div class="notice notice-error inline"><p><?php esc_html_e( 'Rollback could not safely remove every tracked item. Review the stored migration state before changing content manually.', 'core-blueprint-content-migrator' ); ?></p></div><?php endif; ?>
		<?php if ( self::has_rollback_targets( $job ) ) : ?><hr><h3><?php esc_html_e( 'Roll back', 'core-blueprint-content-migrator' ); ?></h3><p><?php esc_html_e( 'Rollback permanently removes only job-owned target posts and safe-to-remove target terms and relationships. Source content remains untouched.', 'core-blueprint-content-migrator' ); ?></p><?php self::rollback_form( $job ); ?><?php endif; ?>
		<?php
	}

	/** @param array<string,mixed> $job */
	private static function verification( array $job ): void {
		$result = $job['verification'] ?? null;
		if ( ! is_array( $result ) ) {
			return;
		}
		$passed = ! empty( $result['passed'] );
		?><div class="notice <?php echo $passed ? 'notice-success' : 'notice-warning'; ?> inline"><p><strong><?php echo esc_html( $passed ? __( 'Verification passed.', 'core-blueprint-content-migrator' ) : __( 'Verification found differences.', 'core-blueprint-content-migrator' ) ); ?></strong></p></div><?php
		if ( ! $passed ) {
			echo '<ul>';
			foreach ( array_slice( (array) ( $result['issues'] ?? [] ), 0, 20 ) as $issue ) {
				echo '<li>' . esc_html( (string) $issue ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/** @param array<string,mixed> $job */
	private static function has_rollback_targets( array $job ): bool {
		if ( 'taxonomy' === sanitize_key( (string) ( $job['mode'] ?? 'post' ) ) ) {
			return ! empty( $job['created_target_ids'] ) || ! empty( $job['added_relationships'] );
		}
		return ! empty( $job['target_map'] ) || ! empty( $job['created_taxonomy_terms'] );
	}

	/** @param array<string,mixed>|null $job */
	private static function action_form( string $action, string $label, string $class, ?array $job = null ): void {
		?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 8px 8px 0"><input type="hidden" name="action" value="cb_content_migrator_<?php echo esc_attr( $action ); ?>"><?php if ( is_array( $job ) ) : ?><input type="hidden" name="job_id" value="<?php echo esc_attr( (string) ( $job['id'] ?? '' ) ); ?>"><?php endif; ?><?php wp_nonce_field( 'cb_content_migrator_' . $action, 'cb_content_migrator_nonce' ); ?><?php submit_button( $label, $class, 'submit', false ); ?></form><?php
	}

	/** @param array<string,mixed> $job */
	private static function rollback_form( array $job ): void {
		?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px 0"><input type="hidden" name="action" value="cb_content_migrator_rollback"><input type="hidden" name="job_id" value="<?php echo esc_attr( (string) ( $job['id'] ?? '' ) ); ?>"><?php wp_nonce_field( 'cb_content_migrator_rollback', 'cb_content_migrator_nonce' ); ?><p><label><input type="checkbox" name="confirm_rollback" value="1" required> <?php esc_html_e( 'I understand that rollback permanently deletes targets owned by this migration job.', 'core-blueprint-content-migrator' ); ?></label></p><?php submit_button( __( 'Roll back migration', 'core-blueprint-content-migrator' ), 'delete', 'submit', false ); ?></form><?php
	}

	/** @param array<string,mixed> $job */
	private static function finalize_form( array $job, bool $trash, string $label ): void {
		?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 8px 8px 0"><input type="hidden" name="action" value="cb_content_migrator_finalize"><input type="hidden" name="job_id" value="<?php echo esc_attr( (string) ( $job['id'] ?? '' ) ); ?>"><input type="hidden" name="trash_source" value="<?php echo $trash ? '1' : '0'; ?>"><?php wp_nonce_field( 'cb_content_migrator_finalize', 'cb_content_migrator_nonce' ); ?><?php if ( $trash ) : ?><p><label><input type="checkbox" name="confirm_trash_source" value="1" required> <?php esc_html_e( 'I understand that the verified source posts will be moved to WordPress Trash.', 'core-blueprint-content-migrator' ); ?></label></p><?php endif; ?><?php submit_button( $label, $trash ? 'secondary' : 'primary', 'submit', false ); ?></form><?php
	}

	/** @param array<string,mixed> $job */
	private static function takeover_form( array $job ): void {
		?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="cb_content_migrator_takeover"><input type="hidden" name="job_id" value="<?php echo esc_attr( (string) ( $job['id'] ?? '' ) ); ?>"><?php wp_nonce_field( 'cb_content_migrator_takeover', 'cb_content_migrator_nonce' ); ?><p><label><input type="checkbox" name="confirm_takeover" value="1" required> <?php esc_html_e( 'I understand that I am taking responsibility for this active migration.', 'core-blueprint-content-migrator' ); ?></label></p><?php submit_button( __( 'Take over migration', 'core-blueprint-content-migrator' ), 'secondary', 'submit', false ); ?></form><?php
	}

	/** @param array<string,object> $objects */
	private static function object_options( array $objects ): void {
		echo '<option value="">' . esc_html__( 'Select…', 'core-blueprint-content-migrator' ) . '</option>';
		foreach ( $objects as $name => $object ) {
			echo '<option value="' . esc_attr( (string) $name ) . '">' . esc_html( (string) $object->labels->name ) . ' (' . esc_html( (string) $name ) . ')</option>';
		}
	}

	private static function notice(): void {
		$state = isset( $_GET['cb_cm_state'] ) && ! is_array( $_GET['cb_cm_state'] ) ? sanitize_key( (string) wp_unslash( $_GET['cb_cm_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect feedback only.
		$message = isset( $_GET['cb_cm_message'] ) && ! is_array( $_GET['cb_cm_message'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['cb_cm_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect feedback only.
		if ( '' === $state ) {
			return;
		}
		$labels = [
			'plan_ready' => __( 'Analysis complete. Review the plan below.', 'core-blueprint-content-migrator' ),
			'job_created' => __( 'Migration job created. The source has not been changed.', 'core-blueprint-content-migrator' ),
			'job_taken_over' => __( 'Migration ownership transferred to your account.', 'core-blueprint-content-migrator' ),
			'batch_complete' => __( 'Batch complete.', 'core-blueprint-content-migrator' ),
			'verified' => __( 'Verification passed.', 'core-blueprint-content-migrator' ),
			'verification_failed' => __( 'Verification found differences.', 'core-blueprint-content-migrator' ),
			'rolled_back' => __( 'Migration rolled back.', 'core-blueprint-content-migrator' ),
			'rollback_failed' => __( 'Rollback needs attention.', 'core-blueprint-content-migrator' ),
			'finalized' => __( 'Migration finalized.', 'core-blueprint-content-migrator' ),
			'finalization_failed' => __( 'Finalization needs attention. The migration remains active.', 'core-blueprint-content-migrator' ),
			'plan_cleared' => __( 'Migration plan cleared.', 'core-blueprint-content-migrator' ),
			'error' => __( 'Migration action failed.', 'core-blueprint-content-migrator' ),
		];
		$text = (string) ( $labels[ $state ] ?? __( 'Migration state updated.', 'core-blueprint-content-migrator' ) );
		if ( '' !== $message ) {
			$text .= ' ' . $message;
		}
		$success = [ 'plan_ready', 'job_created', 'batch_complete', 'verified', 'rolled_back', 'finalized', 'plan_cleared' ];
		$class = in_array( $state, $success, true ) ? 'notice-success' : ( 'error' === $state || str_contains( $state, 'failed' ) ? 'notice-error' : 'notice-warning' );
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
