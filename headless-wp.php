<?php
/**
 * Plugin Name:       Headless WordPress Manager
 * Plugin URI:        https://github.com/Die-PARTEI-in-Europa/headless-wp-plugin
 * Description:       Turns WordPress into a headless CMS: disables the front end, points every "view" link at your decoupled front end, and exposes a signed preview endpoint. Configurable, no hard-coded URLs. Pairs with the parteieuropa/wordpress-api PHP SDK.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            parteieuropa.eu
 * Author URI:        https://parteieuropa.eu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       headless-wp-plugin
 * Domain Path:       /languages
 *
 * @package Headless_WP_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'HEADLESS_WP_VERSION' ) ) {
	define( 'HEADLESS_WP_VERSION', '1.0.0' );
}

if ( ! class_exists( 'Headless_WP_Manager' ) ) :

	/**
	 * Core plugin class.
	 */
	class Headless_WP_Manager {

		/**
		 * Option key holding all plugin settings.
		 *
		 * @var string
		 */
		const OPTION = 'headless_wp_settings';

		/**
		 * Legacy single-value option (frontend URL) from versions < 1.0.0.
		 *
		 * @var string
		 */
		const LEGACY_URL_OPTION = 'headless_wp_frontend_url';

		/**
		 * Settings group slug.
		 *
		 * @var string
		 */
		const GROUP = 'headless_wp_group';

		/**
		 * Cached, merged settings.
		 *
		 * @var array|null
		 */
		private $settings = null;

		/**
		 * Register hooks based on the enabled features.
		 */
		public function __construct() {
			add_action( 'init', array( $this, 'load_textdomain' ) );

			// Settings screen is always available.
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );

			$s = $this->settings();

			if ( $s['disable_frontend'] ) {
				add_action( 'template_redirect', array( $this, 'disable_frontend' ) );
			}

			if ( $s['admin_columns'] ) {
				add_action( 'admin_init', array( $this, 'setup_post_type_hooks' ) );
			}

			if ( $s['metabox'] ) {
				add_action( 'add_meta_boxes', array( $this, 'add_frontend_url_metabox' ) );
			}

			if ( $s['rewrite_links'] ) {
				add_filter( 'post_link', array( $this, 'change_post_link' ), 10, 2 );
				add_filter( 'page_link', array( $this, 'change_post_link' ), 10, 2 );
				add_filter( 'post_type_link', array( $this, 'change_post_link' ), 10, 2 );
				add_filter( 'preview_post_link', array( $this, 'change_preview_link' ), 10, 2 );
				add_action( 'admin_bar_menu', array( $this, 'modify_admin_bar_view_link' ), 999 );
			}

			if ( $s['preview_endpoint'] ) {
				add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
			}

			if ( $s['no_rest_cache'] ) {
				add_filter( 'rest_post_dispatch', array( $this, 'prevent_pages_rest_cache' ), 10, 3 );
			}
		}

		/**
		 * Load translations.
		 */
		public function load_textdomain() {
			load_plugin_textdomain( 'headless-wp-plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}

		/**
		 * Default settings.
		 *
		 * @return array
		 */
		public function defaults() {
			return array(
				'frontend_url'     => '',
				'post_prefix'      => '',
				'preview_path'     => 'preview',
				'raw_post_types'   => array(),
				'disable_frontend' => 1,
				'rewrite_links'    => 1,
				'preview_endpoint' => 1,
				'admin_columns'    => 1,
				'metabox'          => 1,
				'no_rest_cache'    => 1,
			);
		}

		/**
		 * Merged settings (stored values on top of defaults), with a one-time
		 * migration of the legacy frontend-URL option.
		 *
		 * @return array
		 */
		public function settings() {
			if ( null !== $this->settings ) {
				return $this->settings;
			}

			$stored = get_option( self::OPTION, null );

			if ( null === $stored ) {
				$legacy = get_option( self::LEGACY_URL_OPTION, '' );
				$stored = array();
				if ( $legacy ) {
					$stored['frontend_url'] = $legacy;
				}
				update_option( self::OPTION, $stored );
			}

			$this->settings = wp_parse_args( (array) $stored, $this->defaults() );
			return $this->settings;
		}

		/**
		 * Get a single setting value.
		 *
		 * @param string $key Setting key.
		 * @return mixed
		 */
		private function get( $key ) {
			$s = $this->settings();
			return isset( $s[ $key ] ) ? $s[ $key ] : null;
		}

		/* --------------------------------------------------------------- */
		/* Front end                                                       */
		/* --------------------------------------------------------------- */

		/**
		 * Disable the WordPress front end (headless mode).
		 */
		public function disable_frontend() {
			// Let the REST API through.
			if ( false !== strpos( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', '/wp-json/' ) ) {
				return;
			}

			// Let the admin through.
			if ( is_admin() ) {
				return;
			}

			// Logged-in preview → redirect to the decoupled front end with a token.
			if ( is_user_logged_in() && isset( $_GET['preview'], $_GET['p'] ) ) {
				$post_id = intval( $_GET['p'] );
				$token   = $this->make_preview_token( $post_id, get_current_user_id() );
				wp_safe_redirect( $this->preview_url( $token ) );
				exit;
			}

			$url = $this->get_frontend_url();
			wp_die(
				wp_kses_post(
					sprintf(
						'<h1>%1$s</h1><p>%2$s <a href="%3$s">%4$s</a></p>',
						esc_html__( 'Front end disabled', 'headless-wp-plugin' ),
						esc_html__( 'This WordPress site runs in headless mode. The front end is available at', 'headless-wp-plugin' ),
						esc_url( $url ),
						esc_html( $url )
					)
				),
				esc_html__( 'Front end disabled', 'headless-wp-plugin' ),
				array( 'response' => 404 )
			);
		}

		/**
		 * Attach the "Frontend URL" column to every public post type list.
		 */
		public function setup_post_type_hooks() {
			foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
				add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_frontend_url_column' ) );
				add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'show_frontend_url_column' ), 10, 2 );
			}
		}

		/* --------------------------------------------------------------- */
		/* Settings screen                                                 */
		/* --------------------------------------------------------------- */

		/**
		 * Register the options page.
		 */
		public function add_settings_page() {
			add_options_page(
				__( 'Headless WordPress', 'headless-wp-plugin' ),
				__( 'Headless WP', 'headless-wp-plugin' ),
				'manage_options',
				'headless-wp-settings',
				array( $this, 'render_settings_page' )
			);
		}

		/**
		 * Register the settings option and its sanitizer.
		 */
		public function register_settings() {
			register_setting(
				self::GROUP,
				self::OPTION,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_settings' ),
					'default'           => $this->defaults(),
				)
			);
		}

		/**
		 * Sanitize the settings array coming from the form.
		 *
		 * @param mixed $input Raw input.
		 * @return array
		 */
		public function sanitize_settings( $input ) {
			$input = is_array( $input ) ? $input : array();
			$out   = array();

			$out['frontend_url'] = isset( $input['frontend_url'] ) ? esc_url_raw( trim( $input['frontend_url'] ) ) : '';
			$out['post_prefix']  = isset( $input['post_prefix'] ) ? trim( sanitize_text_field( $input['post_prefix'] ), '/ ' ) : '';
			$out['preview_path'] = isset( $input['preview_path'] ) ? trim( sanitize_text_field( $input['preview_path'] ), '/ ' ) : 'preview';

			$raw   = isset( $input['raw_post_types'] ) ? (array) $input['raw_post_types'] : array();
			$valid = get_post_types( array(), 'names' );
			$out['raw_post_types'] = array_values( array_intersect( array_map( 'sanitize_key', $raw ), $valid ) );

			if ( '' === $out['preview_path'] ) {
				$out['preview_path'] = 'preview';
			}

			foreach ( array( 'disable_frontend', 'rewrite_links', 'preview_endpoint', 'admin_columns', 'metabox', 'no_rest_cache' ) as $flag ) {
				$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
			}

			return $out;
		}

		/**
		 * Render a single checkbox row.
		 *
		 * @param array  $s     Current settings.
		 * @param string $key   Setting key.
		 * @param string $label Field label.
		 * @param string $help  Description.
		 */
		private function checkbox_row( $s, $key, $label, $help ) {
			printf(
				'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label></td></tr>',
				esc_html( $label ),
				esc_attr( self::OPTION ),
				esc_attr( $key ),
				checked( ! empty( $s[ $key ] ), true, false ),
				esc_html( $help )
			);
		}

		/**
		 * Render the settings screen.
		 */
		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$s = $this->settings();
			$o = self::OPTION;
			?>
			<div class="wrap">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<form action="options.php" method="post">
					<?php settings_fields( self::GROUP ); ?>

					<h2><?php echo esc_html__( 'URLs', 'headless-wp-plugin' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="hw_frontend_url"><?php echo esc_html__( 'Front-end URL', 'headless-wp-plugin' ); ?></label></th>
							<td>
								<input type="url" id="hw_frontend_url" name="<?php echo esc_attr( $o ); ?>[frontend_url]" value="<?php echo esc_attr( $s['frontend_url'] ); ?>" class="regular-text" placeholder="https://example.com">
								<p class="description"><?php echo esc_html__( 'Base URL of your decoupled front end, without a trailing slash.', 'headless-wp-plugin' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hw_post_prefix"><?php echo esc_html__( 'Post path prefix', 'headless-wp-plugin' ); ?></label></th>
							<td>
								<input type="text" id="hw_post_prefix" name="<?php echo esc_attr( $o ); ?>[post_prefix]" value="<?php echo esc_attr( $s['post_prefix'] ); ?>" class="regular-text" placeholder="e.g. blog">
								<p class="description"><?php echo esc_html__( 'Optional path segment prepended to single posts on the front end (e.g. "blog" → /blog/my-post). Leave empty for /my-post.', 'headless-wp-plugin' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hw_preview_path"><?php echo esc_html__( 'Preview path', 'headless-wp-plugin' ); ?></label></th>
							<td>
								<input type="text" id="hw_preview_path" name="<?php echo esc_attr( $o ); ?>[preview_path]" value="<?php echo esc_attr( $s['preview_path'] ); ?>" class="regular-text" placeholder="preview">
								<p class="description">
									<?php echo esc_html__( 'Front-end route that renders a preview. The resulting preview URL is:', 'headless-wp-plugin' ); ?>
									<code><?php echo esc_html( $this->preview_url( '<token>' ) ); ?></code>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hw_raw_post_types"><?php echo esc_html__( 'Raw HTML post types', 'headless-wp-plugin' ); ?></label></th>
							<td>
								<?php $selected_raw = (array) $s['raw_post_types']; ?>
								<select id="hw_raw_post_types" name="<?php echo esc_attr( $o ); ?>[raw_post_types][]" multiple size="5" style="min-width:280px;">
									<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : ?>
										<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( in_array( $pt->name, $selected_raw, true ) ); ?>>
											<?php echo esc_html( $pt->labels->singular_name . ' (' . $pt->name . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php echo esc_html__( 'Select post types whose preview should return raw stored HTML instead of running the block renderer and wpautop. Hold Ctrl/Cmd to select multiple.', 'headless-wp-plugin' ); ?></p>
							</td>
						</tr>
					</table>

					<h2><?php echo esc_html__( 'Features', 'headless-wp-plugin' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						$this->checkbox_row( $s, 'disable_frontend', __( 'Disable front end', 'headless-wp-plugin' ), __( 'Block all theme front-end requests and run in headless mode.', 'headless-wp-plugin' ) );
						$this->checkbox_row( $s, 'rewrite_links', __( 'Rewrite view links', 'headless-wp-plugin' ), __( 'Point "View", permalink and preview links in the admin at the front end.', 'headless-wp-plugin' ) );
						$this->checkbox_row( $s, 'preview_endpoint', __( 'Preview REST endpoint', 'headless-wp-plugin' ), __( 'Expose GET /wp-json/headless/v1/preview for token-based previews.', 'headless-wp-plugin' ) );
						$this->checkbox_row( $s, 'admin_columns', __( 'Front-end URL column', 'headless-wp-plugin' ), __( 'Add a Frontend URL column to post-type list tables.', 'headless-wp-plugin' ) );
						$this->checkbox_row( $s, 'metabox', __( 'Front-end URL meta box', 'headless-wp-plugin' ), __( 'Show the front-end URL in a meta box in the editor.', 'headless-wp-plugin' ) );
						$this->checkbox_row( $s, 'no_rest_cache', __( 'Disable page REST cache', 'headless-wp-plugin' ), __( 'Send no-cache headers for /wp/v2/pages so stale block output is never served.', 'headless-wp-plugin' ) );
						?>
					</table>

					<?php submit_button(); ?>
				</form>

				<hr>
				<h2><?php echo esc_html__( 'REST API endpoints', 'headless-wp-plugin' ); ?></h2>
				<ul>
					<li><strong>Posts:</strong> <code><?php echo esc_url( rest_url( 'wp/v2/posts' ) ); ?></code></li>
					<li><strong>Pages:</strong> <code><?php echo esc_url( rest_url( 'wp/v2/pages' ) ); ?></code></li>
					<li><strong>Media:</strong> <code><?php echo esc_url( rest_url( 'wp/v2/media' ) ); ?></code></li>
					<li><strong>Preview:</strong> <code><?php echo esc_url( rest_url( 'headless/v1/preview' ) ); ?></code></li>
				</ul>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to the PHP SDK on GitHub. */
						esc_html__( 'Tip: consume this API from PHP with the %s SDK.', 'headless-wp-plugin' ),
						'<a href="https://github.com/Die-PARTEI-in-Europa/wordpress-api" target="_blank" rel="noopener">parteieuropa/wordpress-api</a>'
					);
					?>
				</p>
			</div>
			<?php
		}

		/* --------------------------------------------------------------- */
		/* URL helpers                                                     */
		/* --------------------------------------------------------------- */

		/**
		 * Resolve the front-end URL for a post (or the base URL).
		 *
		 * @param int|null $post_id Optional post ID.
		 * @return string
		 */
		private function get_frontend_url( $post_id = null ) {
			$base_url = $this->get( 'frontend_url' );
			if ( ! $base_url ) {
				$base_url = home_url();
			}

			if ( $post_id ) {
				$post = get_post( $post_id );
				if ( $post ) {
					$slug = $post->post_name;

					if ( 'post' === $post->post_type ) {
						$prefix = $this->get( 'post_prefix' );
						$path   = $prefix ? trailingslashit( $prefix ) . $slug : $slug;
						return trailingslashit( $base_url ) . $path;
					} elseif ( 'page' === $post->post_type ) {
						return trailingslashit( $base_url ) . $slug;
					}

					$pto = get_post_type_object( $post->post_type );
					if ( $pto && $pto->has_archive ) {
						return trailingslashit( $base_url ) . $post->post_type . '/' . $slug;
					}
					return trailingslashit( $base_url ) . $slug;
				}
			}

			return $base_url;
		}

		/**
		 * Build the full preview URL for a token.
		 *
		 * @param string $token Preview token (or a placeholder).
		 * @return string
		 */
		private function preview_url( $token ) {
			return trailingslashit( $this->get_frontend_url() ) . $this->get( 'preview_path' ) . '?preview_token=' . $token;
		}

		/**
		 * Add the Frontend URL column.
		 *
		 * @param array $columns Existing columns.
		 * @return array
		 */
		public function add_frontend_url_column( $columns ) {
			$new = array();
			foreach ( $columns as $key => $value ) {
				$new[ $key ] = $value;
				if ( 'title' === $key ) {
					$new['frontend_url'] = __( 'Frontend URL', 'headless-wp-plugin' );
				}
			}
			return $new;
		}

		/**
		 * Render the Frontend URL column.
		 *
		 * @param string $column  Column key.
		 * @param int    $post_id Post ID.
		 */
		public function show_frontend_url_column( $column, $post_id ) {
			if ( 'frontend_url' !== $column ) {
				return;
			}
			$url = $this->get_frontend_url( $post_id );
			printf(
				'<a href="%1$s" target="_blank" rel="noopener" style="color:#2271b1;text-decoration:none;"><span class="dashicons dashicons-external" style="vertical-align:middle;"></span> %2$s</a>',
				esc_url( $url ),
				esc_html( $url )
			);
		}

		/**
		 * Register the front-end URL meta box on public post types.
		 */
		public function add_frontend_url_metabox() {
			foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
				add_meta_box(
					'headless_wp_frontend_url',
					__( 'Frontend URL', 'headless-wp-plugin' ),
					array( $this, 'render_frontend_url_metabox' ),
					$post_type,
					'side',
					'high'
				);
			}
		}

		/**
		 * Render the meta box.
		 *
		 * @param WP_Post $post Current post.
		 */
		public function render_frontend_url_metabox( $post ) {
			$url = $this->get_frontend_url( $post->ID );
			?>
			<div style="padding:10px 0;">
				<p style="margin:0 0 10px;"><?php echo esc_html__( 'This entry is available on the front end at:', 'headless-wp-plugin' ); ?></p>
				<div style="background:#f0f0f1;padding:12px;border-radius:4px;margin-bottom:10px;">
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" style="color:#2271b1;text-decoration:none;font-weight:500;word-break:break-all;">
						<span class="dashicons dashicons-external" style="vertical-align:middle;"></span>
						<?php echo esc_html( $url ); ?>
					</a>
				</div>
			</div>
			<?php
		}

		/**
		 * Rewrite admin permalinks to the front end.
		 *
		 * @param string      $url  Original URL.
		 * @param WP_Post|int $post Post object or ID.
		 * @return string
		 */
		public function change_post_link( $url, $post ) {
			if ( is_admin() ) {
				$post_id = is_object( $post ) ? $post->ID : $post;
				return $this->get_frontend_url( $post_id );
			}
			return $url;
		}

		/* --------------------------------------------------------------- */
		/* Preview                                                         */
		/* --------------------------------------------------------------- */

		/**
		 * Deterministic preview token for a post+user; refreshes the transient.
		 *
		 * @param int $post_id Post ID.
		 * @param int $user_id User ID.
		 * @return string
		 */
		private function make_preview_token( $post_id, $user_id ) {
			$token = substr( hash_hmac( 'sha256', $post_id . ':' . $user_id, wp_salt( 'auth' ) ), 0, 32 );
			set_transient(
				'headless_preview_' . $token,
				array(
					'post_id' => (int) $post_id,
					'user_id' => (int) $user_id,
				),
				2 * HOUR_IN_SECONDS
			);
			return $token;
		}

		/**
		 * Point the WordPress preview button at the front end.
		 *
		 * @param string      $url  Original preview URL.
		 * @param WP_Post|int $post Post object or ID.
		 * @return string
		 */
		public function change_preview_link( $url, $post ) {
			$post_id = is_object( $post ) ? $post->ID : $post;
			$token   = $this->make_preview_token( $post_id, get_current_user_id() );
			return $this->preview_url( $token );
		}

		/**
		 * Register the preview REST route.
		 */
		public function register_rest_routes() {
			register_rest_route(
				'headless/v1',
				'/preview',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_preview' ),
					'permission_callback' => '__return_true',
				)
			);
		}

		/**
		 * REST handler: return preview content for a token.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function rest_preview( WP_REST_Request $request ) {
			$token = sanitize_text_field( $request->get_param( 'token' ) );
			if ( ! $token || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
				return new WP_Error( 'invalid_token', 'Invalid token format', array( 'status' => 400 ) );
			}

			$data = get_transient( 'headless_preview_' . $token );
			if ( ! $data ) {
				return new WP_Error( 'expired', 'Preview token invalid or expired', array( 'status' => 403 ) );
			}

			$post_id   = (int) $data['post_id'];
			$user_id   = (int) $data['user_id'];
			$base_post = get_post( $post_id );

			if ( ! $base_post ) {
				return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
			}

			// Autosave holds the unsaved Gutenberg changes; prefer it.
			$autosave = wp_get_post_autosave( $post_id, $user_id );
			if ( ! $autosave ) {
				$autosave = wp_get_post_autosave( $post_id );
			}
			$source = $autosave ? $autosave : $base_post;

			$raw_types = (array) $this->get( 'raw_post_types' );

			if ( in_array( $base_post->post_type, $raw_types, true ) ) {
				// Show the stored raw HTML unchanged (no wpautop).
				$content = $base_post->post_content;
			} else {
				$content = do_blocks( $source->post_content );
				$content = apply_filters( 'the_content', $content );
			}

			return rest_ensure_response(
				array(
					'id'            => $post_id,
					'title'         => array( 'rendered' => wp_strip_all_tags( $source->post_title ) ),
					'content'       => array( 'rendered' => $content ),
					'slug'          => $base_post->post_name,
					'type'          => $base_post->post_type,
					'status'        => $base_post->post_status,
					'excerpt'       => array( 'rendered' => '' ),
					'is_preview'    => true,
					'is_front_page' => ( 'page' === $base_post->post_type && intval( get_option( 'page_on_front' ) ) === $post_id ),
				)
			);
		}

		/**
		 * Point the admin bar "View" link at the front end.
		 *
		 * @param WP_Admin_Bar $wp_admin_bar Admin bar.
		 */
		public function modify_admin_bar_view_link( $wp_admin_bar ) {
			if ( ! is_admin() ) {
				return;
			}
			$screen = get_current_screen();
			if ( $screen && $screen->post_type ) {
				$pto = get_post_type_object( $screen->post_type );
				if ( $pto && $pto->public ) {
					global $post;
					if ( $post ) {
						$node = $wp_admin_bar->get_node( 'view' );
						if ( $node ) {
							$node->href = $this->get_frontend_url( $post->ID );
							$wp_admin_bar->add_node( $node );
						}
					}
				}
			}
		}

		/**
		 * Prevent REST caching of pages (avoids stale block output).
		 *
		 * @param WP_REST_Response $response Response.
		 * @param WP_REST_Server   $server   Server.
		 * @param WP_REST_Request  $request  Request.
		 * @return WP_REST_Response
		 */
		public function prevent_pages_rest_cache( $response, $server, $request ) {
			if ( false !== strpos( $request->get_route(), '/wp/v2/pages' ) ) {
				$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
				$response->header( 'Pragma', 'no-cache' );
			}
			return $response;
		}
	}

	new Headless_WP_Manager();

endif;
