<?php
/*
Plugin Name: CF Page Permalink
Description: Custom page permalinks using rewrite rules for performance
Version: 0.1.0
Author: Crowd Favorite
License: GPL2

Modified from the original http://wordpress.org/extend/plugins/permalink-editor/
Some original code is copyright Fubra Limited and used under the GPL

*/

class cf_page_permalink
{

	/**
	 * The plugin directory name as it appears in the plugins folder.
	 * @var string
	 */
	var $dir_name = 'cf-page-permalink';

	/**
	 * Generic tag name used for prefixing settings / inputs.
	 * @var string
	 */
	var $tag = 'cf_page_permalink';

	/**
	 * Local store of the current permalink structures.
	 * @var array|false
	 */
	var $structures = false;

	/**
	 * Filters applied to individual post or page permalinks.
	 * @var array
	 */
	var $individual_permalink_filters = array(
		'page_link' => 'page_link',
		'post_link' => 'page_link',
		'author_link' => 'trailingslash',
	);

	/**
	 * Add actions and filters to ensure the urls are correctly re-written.
	 */
	function __construct()
	{
		add_action( 'init', array( &$this, 'init' ), 999 );
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		add_filter('cfpp_rewrite_rules', array($this, 'cfpp_rewrite_rules'));
		add_filter('rewrite_rules_array', array($this, 'cfpp_rewrite_rules_array'), 999);
	}

	/**
	 * Modify the page permastruct and set the new custom structure if rewriting
	 * is enabled.
	 */
	function init()
	{
		if ( $this->rewrite_enabled() ) {
			// Add the filters for custom permalink output...
			$this->add_filters( $this->individual_permalink_filters );
			// Add filters...
			add_filter( 'user_trailingslashit', array( &$this, 'trailingslash' ), 10 );
			$this->add_permastruct();
		}
	}

	/**
	 * Add any admin only hooks and filters if the current user is capable of
	 * modifying permalinks...
	 */
	function admin_init()
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $this->rewrite_enabled() ) {
			add_action( 'save_post', array( &$this, 'save_post' ) );
			add_filter( 'get_sample_permalink_html', array( &$this, 'get_sample_permalink_html' ), 10, 4 );
			if ($this->do_edit_screen()) {
				add_action( 'admin_enqueue_scripts', array(&$this, 'admin_enqueue_scripts'), 10, 1 );
				add_action( 'add_meta_boxes', array( &$this, 'add_meta_boxes' ) );
			}
		}
	}


	/**
	 * Fetch a single post based on the custom permalink value stored as custom
	 * meta data.
	 *
	 * @param string $permalink
	 */
	function get_post_by_custom_permalink( $permalink, $exclude = false, $suffix = '' )
	{
		$post = false;
		if ( $front = $this->front() ) {
			$permalink = str_replace( $front, '', $permalink );
		}
		// Fetch all the public post types to lookup against...
		if ( $post_types = get_post_types( array( 'public' => true ), 'names' ) ) {
			if ( $posts = get_posts( array(
				'post_type' => $post_types,
				'meta_key' => '_' . $this->tag . $suffix,
				'meta_value' => $permalink,
				'posts_per_page' => 1,
				'exclude' => $exclude
			) ) ) {
				$post = array_shift( $posts );
			} else if ( substr( $permalink, 0, 1 ) == '/' ) {
				$post = $this->get_post_by_custom_permalink(
					ltrim( $permalink, '/' ), $exclude, $suffix
				);
			} else if ( substr( $permalink, -1 ) != '/' ) {
				$post = $this->get_post_by_custom_permalink(
					trailingslashit( $permalink ), $exclude, $suffix
				);
			}
		}
		return $post;
	}

	/**
	 * Adds JavaScript file to the edit page for handling the permalink editing.
	 *
	 * @param string $hook
	 */
	function admin_enqueue_scripts( $hook )
	{
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
			$dirname = plugin_basename( pathinfo( __FILE__, PATHINFO_DIRNAME ) );
			wp_enqueue_script(
				$dirname,
				WP_PLUGIN_URL . '/' . $dirname . '/admin.js'
			);
		}
	}

	/**
	 * Generates the custom permalink for an individual post, page or custom
	 * post type.
	 *
	 * @param string $permalink
	 * @param integer|object $page
	 */
	function page_link( $permalink, $page )
	{
		if ( is_object( $page ) ) {
			$page_obj = $page;
			$page = $page->ID;
		}
		else {
			$page_obj = get_post($page);
		}
		if (!in_array($page_obj->post_type, $this->post_types())) {
			return $permalink;
		}

		if ( ( 'page' == get_option( 'show_on_front' ) )
			&& ( $page == get_option( 'page_on_front' ) )
		) {
			$permalink = home_url( '/' );
		} else if ( $custom = get_post_meta( $page, '_' . $this->tag, true ) ) {
			$permalink = trailingslashit( get_bloginfo( 'url' ) ) . $this->front( ) . ltrim( $custom, '/' );
		}
		return apply_filters( 'cfpp_page_link', $permalink );
	}

	/**
	 * Adds meta boxes to the page and post editing page allowing an individual
	 * permalink and an alias to be specified.
	 */
	function add_meta_boxes()
	{
		$post_types = $this->post_types();

		foreach ( $post_types AS $post_type ) {
			add_meta_box(
				'custompermalinkdiv',
				'Custom Permalink',
	            array( &$this, 'permalink_meta_box'),
	            $post_type,
	            'normal',
	            'low'
			);
		}
	}

	/**
	 * Meta box for editing a custom permalink per post or page.
	 *
	 * @param object $post
	 * @param mixed $metabox
	 */
	function permalink_meta_box( $post, $metabox )
	{
		$value = get_post_meta( $post->ID, '_' . $this->tag, true );
		wp_nonce_field( plugin_basename( __FILE__ ), $this->tag . '_nonce' );
		?>
		<label for="<?php echo $this->tag; ?>">
			<span id="edit-custom-permalink">
				<?php echo trailingslashit( get_option( 'home' ) ); ?>
		 		<input type="text"
		 			id="<?php echo $this->tag; ?>"
		 			name="<?php echo $this->tag; ?>"
		 			value="<?php esc_html_e( $this->reformat_permalink( $value, '' ) ); ?>"
		 			size="40"
		 		/>
	 		</span>
 		</label>
 		<?php
	}

	/**
	 * Output the permalink editing form with the option to fully customise the
	 * slug alongside the default editing option.
	 *
	 * NOTE: A custom filter is applied here, allowing you to modify the
	 * permalink structure via external plugins by adding a filter:
	 * add_filter( 'cfpp_get_custom_permalink_sample', 'callback_function', 1, 2 );
	 *
	 * @param string $html
	 * @param int|object $id
	 * @param string $new_title
	 * @param string $new_slug
	 */
	function get_sample_permalink_html( $html, $id, $new_title, $new_slug )
	{
		$post = &get_post( $id );
		if (!in_array($post->post_type, $this->post_types())) {
			return $html;
		}
		// Get the current original...
		list( $permalink, $post_name ) = get_sample_permalink( $post->ID, $new_title, $new_slug );
		// Define the home url...
		$home_url = home_url( '/' );
		// Fetch the default permalink...
		$this->remove_filters( $this->individual_permalink_filters );
		list( $default, ) = get_sample_permalink( $post->ID );
		// Build the default permalink and replace any tokens...
		$default_permalink = apply_filters(
			'cfpp_get_custom_permalink_sample',
			$this->build_permalink( $default, $post_name ),
			$post
		);
		$this->add_filters( $this->individual_permalink_filters );
		// Set the permalink to the new one...
		if ( isset( $_REQUEST['custom_slug'] ) ) {
			$custom_slug = $this->reformat_permalink( $_REQUEST['custom_slug'], '' );
			if ( ! empty( $custom_slug ) && ! $this->permalinks_match( $custom_slug, $default_permalink ) ) {
				$post_name = $this->unique_custom_permalink( $post->ID, $custom_slug );
				$permalink = $home_url . $post_name;
			} else {
				$permalink = $default;
			}
		} else if ( $new_slug ) {
			$permalink = $default;
		} else if ( $custom = get_post_meta( $id, '_' . $this->tag, true ) ) {
			$post_name = ltrim( $custom, '/' );
			$permalink = $home_url . $post_name;
		}
		// By default we will display the permalink as it is...
		$view_link = $permalink;
		// Fetch the post type label and set the edit title...
		if ( 'publish' == $post->post_status ) {
			$post_type = get_post_type_object( $post->post_type );
			$view_post = $post_type->labels->view_item;
			$title = __( 'Click to edit this part of the permalink' );
		} else {
			$title = __( 'Temporary permalink. Click to edit this part.' );
		}
		// Run the permalink through our custom filter...
		$permalink = apply_filters( 'cfpp_get_custom_permalink_sample', remove_accents( $permalink ), $post );
		// Highlight the post name in the permalink...
		$post_name_html = '<span id="editable-post-name" title="' . $title . '">' . $post_name . '</span>';
		ob_start();
		?>
		<strong><?php _e( 'Permalink:' ); ?></strong>
		<?php
		if ( false === strpos( $permalink, '%postname%' ) && false === strpos( $permalink, '%pagename%' ) ) {
			$display_link = str_replace( $permalink, $post_name_html, $view_link );
		?>
			<?php echo $home_url; ?><span id="sample-permalink"><?php echo $display_link; ?></span>
		<?php
		} else {
			$view_link = $home_url . $this->build_permalink( $permalink, $post_name );
			$display_link = $this->build_permalink( $permalink, $post_name_html );
		?>
			<?php echo $home_url; ?><span id="sample-permalink"><?php echo $display_link; ?></span>
			&lrm;
			<span id="edit-slug-buttons">
				<a href="#post_name"
					class="edit-slug button hide-if-no-js"
					onclick="editOriginalPermalink(<?php echo $id; ?>); return false;"><?php _e( 'Edit' )?></a>
			</span>
		<?php
		}
		?>
			<span id="customise-permalink-buttons">
				<a href="#"
					class="customise-permalink button hide-if-no-js"
					onclick="editCustomPermalink(<?php echo $id; ?>); return false;"><?php _e( 'Customize' )?></a>
			</span>
			<span id="editable-post-name-full"><?php echo $post_name; ?></span>
		<?php
		// If the post is publicly viewable, display permalink view options...
		if ( isset( $view_post ) ) {
		?>
			<span id="view-post-btn">
				<a href="<?php echo $view_link; ?>"
					class="button"
					target="_blank"><?php echo $view_post; ?></a>
			</span>
			<?php if ( $new_title && ( $shortlink = wp_get_shortlink( $post->ID, $post->post_type ) ) ) { ?>
    		<input id="shortlink" type="hidden" value="<?php esc_attr_e( $shortlink ); ?>" />
    		<a href="#"
    			class="button hide-if-nojs"
    			onclick="prompt( 'URL:', jQuery( '#shortlink' ).val() ); return false;"><?php _e( 'Get Shortlink' ); ?></a>
			<?php } ?>
		<?php
		}
		$return = ob_get_contents(); ob_end_clean();
		return $return;
	}

	/**
	 * Check that a permialink is unique and if not append a suffix, for
	 * exmaple "/post.html" becomes "/post.html2".
	 *
	 * @param object $post
	 * @param string $permalink
	 */
	function unique_custom_permalink( $post_id, $permalink )
	{
		$slug = $unique = '/' . str_replace( home_url( '/' ), '', $permalink );
		if ( $this->get_post_by_custom_permalink( $slug, $post_id ) ) {
			$suffix = 2;
			do {
				$slug = $unique . $suffix;
				$check = $this->get_post_by_custom_permalink( $slug, $post_id );
				$suffix++;
			} while ( $check );
		}
		return ltrim( $slug, '/' );
	}

	/**
	 * Update the custom permalink and alias values when a post is updated.
	 *
	 * @param int $post_id
	 */
	function save_post( $post_id )
	{
		if ( ! isset( $_POST[$this->tag . '_nonce'] ) ||
			! wp_verify_nonce( $_POST[$this->tag . '_nonce'], plugin_basename( __FILE__ ) )
		) {
			return $post_id;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}
		if (!isset($_POST['post_type']) || !in_array($_POST['post_type'], $this->post_types())) {
			return $post_id;
		}
		$fields = array(
			$this->tag,
		);
		foreach ( $fields AS $field ) {
			if ( isset( $_POST[$field] ) ) {
				$value = $this->reformat_permalink( $_POST[$field], '' );
				$key = '_' . $field;
				if ( empty( $value ) ) {
					delete_post_meta( $post_id, $key, $value );
				} else if ( ! update_post_meta( $post_id, $key, $value ) ) {
					add_post_meta( $post_id, $key, $value, true );
				}
			}
		}
		flush_rewrite_rules( false );
		return $fields;
	}

	/**
	 * Cleans the permalink and removes any unwanted characters.
	 *
	 * @param string $permalink
	 * @param string $prefix
	 */
	function reformat_permalink( $permalink, $prefix = '/' )
	{
		if ( empty( $permalink ) )
			return null;
		// Basic sanitize functionality...
		$permalink = apply_filters( 'sanitize_text_field', $permalink );
		// Replace hashes and white space...
		$permalink = str_replace( array( '#', ' ' ), array( '', '-' ), $permalink );
		// Remove multiple slashes...
		$permalink = preg_replace(
			array( '#\-+#', '#/+#', '#\.+#' ),
			array( '-', '/', '.' ),
			remove_accents( $permalink )
		);
		// Return formatted permalink with prefix...
		return $prefix . ltrim( $permalink, '/' );
	}

	function add_permastruct() {
		global $wp_rewrite;
		$wp_rewrite->add_permastruct('cfpp', '%cfpp%');
	}

	function cfpp_rewrite_rules($rewrite = array()) {
		global $wp_rewrite;

		$rewrite = array();

		$args = array(
			'post_type' => $this->post_types(),
			'meta_key' => '_' . $this->tag,
			'posts_per_page' => -1
		);
		$posts = get_posts($args);
		foreach ($posts as $post) {
			if ( $custom = get_post_meta( $post->ID, '_' . $this->tag, true ) ) {
				$custom = trailingslashit($custom);
				$rewrite[$custom . '?$'] = 'index.php?' . ('page' === $post->post_type ? 'page_id' : 'p') . '=' . $post->ID;
			}
		}

		$this->structures = $rewrite;

		return array();
	}


	function cfpp_rewrite_rules_array($rules) {
		if (empty($this->structures)) {
			$this->cfpp_rewrite_rules();
		}
		if (!empty($this->structures)) {
			foreach (array_keys(array_intersect_key($this->structures, $rules)) as $key) {
				unset($rules[$key]);
			}
			return array_merge($this->structures, $rules);
		}

		return $rules;
	}

	/**
	 * Replaces permalink tokens and home url to provide a page path, for
	 * example: "2011/01/26/about/"
	 *
	 * @param string $permalink
	 * @param string $post_name
	 */
	function build_permalink( $permalink, $post_name )
	{
		return str_replace(
			array( trailingslashit( home_url() ), '%pagename%', '%postname%' ),
			array( '', $post_name, $post_name ),
			$permalink
		);
	}

	/**
	 * Quick test to see if two permalinks appear to be the same.
	 *
	 * @param string $a
	 * @param string $b
	 */
	function permalinks_match( $a, $b )
	{
		return ( trailingslashit( trim( $a ) ) == trailingslashit( trim( $b ) ) );
	}

	/**
	 * Return the url prefix including the base path and index file if we are
	 * using index permalinks.
	 *
	 * @param string $after
	 * @return string|null
	 */
	function front( $after = '/' )
	{
		global $wp_rewrite;
		if ( $wp_rewrite->using_index_permalinks() ) {
			return 'index.php' . $after;
		}
		return null;
	}

	/**
	 * Remove the trailing slash from page permalinks that have an extension,
	 * such as /page/%pagename%.html.
	 *
	 * @param string $request
	 */
	function trailingslash( $request )
	{
		if ( pathinfo( $request, PATHINFO_EXTENSION ) ) {
			return untrailingslashit( $request );
		}
		return trailingslashit( $request );
	}

	/**
	 * Very simple check to see whether or not we are using a custom permalink
	 * structure.
	 */
	function rewrite_enabled()
	{
		global $wp_rewrite;
		if ( $wp_rewrite->using_permalinks() ) {
			return true;
		}
		return false;
	}

	/**
	 * Quick method of adding multiple filters in a single call.
	 *
	 * @param array $filters
	 */
	function add_filters( $filters )
	{
		foreach ( $filters AS $filter => $callback ) {
			add_filter( $filter, array( &$this, $callback ), 10, 2 );
		}
	}

	/**
	 * As we can quickly add filters, this does the opposite and removes them.
	 *
	 * @param array $filters
	 */
	function remove_filters( $filters )
	{
		foreach ( $filters AS $filter => $callback ) {
			remove_filter( $filter, array( &$this, $callback ), 10, 2 );
		}
	}

	private function post_types() {
		$post_types = array('page');
		$post_types = apply_filters('cfpp-enabled-post-types', $post_types);
		return $post_types;
	}


	private function do_edit_screen() {
		$post_types = $this->post_types();

		$ret = true;
		if (!empty($post_types)) {
			$post_type = $this->get_post_type();
			$ret = in_array($post_type, $post_types);
		}
		return $ret;
	}


	/**
		* determine which type we're working on
		*/
	private function get_post_type() {
		global $pagenow;

		if (in_array($pagenow, array('post-new.php'))) {
			if (!empty($_GET['post_type'])) {
				// custom post type or wordpress 3.0 pages
				$type = esc_attr($_GET['post_type']);
			}
			else {
				$type = 'post';
			}
		}
		elseif (in_array( $pagenow, array('page-new.php'))) {
			// pre 3.0 new pages
			$type = 'page';
		}
		else {
			// post/page-edit
			if (isset($_GET['post']))
				$post_id = (int) $_GET['post'];
			elseif (isset($_POST['post_ID'])) {
				$post_id = (int) $_POST['post_ID'];
			}
			else {
				$post_id = 0;
			}

			$type = false;
			if ($post_id > 0) {
				$post = get_post_to_edit($post_id);

				if ($post && !empty($post->post_type) && !in_array($post->post_type, array('attachment', 'revision'))) {
					$type = $post->post_type;
				}
			}
		}
		return apply_filters('cfpp-admin-edit-post-type', $type);
	}
}

$cf_page_permalink = new cf_page_permalink();
