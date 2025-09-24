<?php

/**
 * The channel admin-specific functionality of the plugin.
 *
 * @since		1.0.0
 * @since		1.3.2	Refactored class from object to static methods.
 *
 * @package		Foyer
 * @subpackage	Foyer/admin
 * @author		Menno Luitjes <menno@mennoluitjes.nl>
 */
class Foyer_Admin_Channel {

	/**
	 * Adds a Slide Count column to the Channels admin table, just after the title column.
	 *
	 * @since	1.0.0
	 * @since	1.3.2	Changed method to static.
	 *
	 * @param 	array	$columns	The current columns.
	 * @return	array				The new columns.
	 */
    static function add_slides_count_column( $columns ) {
        $new_columns = array();

        foreach( $columns as $key => $title ) {
            $new_columns[$key] = $title;

            if ( 'title' == $key ) {
                // Add favorite star and slides count columns after the title column
                $new_columns['favorite'] = __( 'Favorite', 'foyer' );
                $new_columns['slides_count'] = __( 'Number of slides', 'foyer' );
            }
        }
        return $new_columns;
    }

	/**
	 * Adds a slide over AJAX and outputs the updated slides list HTML.
	 *
	 * @since	1.0.0
	 * @since	1.0.1	Validated & sanitized the user input.
	 * @since	1.3.2	Changed method to static.
	 *
	 * @return void
	 */
    static function add_slide_over_ajax() {

		check_ajax_referer( 'foyer_slides_editor_ajax_nonce', 'nonce' , true );

		$channel_id = intval( $_POST['channel_id'] );
		$add_slide_id = intval( $_POST['slide_id'] );

		if ( empty( $channel_id ) || empty( $add_slide_id ) ) {
			wp_die();
		}

		/* Check if the channel post exists */
		if ( is_null( get_post( $channel_id  ) ) ) {
			wp_die();
		}

		$channel = new Foyer_Channel( $channel_id );
		$slides = $channel->get_slides();

		$new_slides = array();
		foreach( $slides as $slide ) {
			$new_slides[] = $slide->ID;
		}

        $new_slides[] = $add_slide_id;

        update_post_meta( $channel_id, Foyer_Slide::post_type_name, $new_slides );
        // Initialize window entry (optional, left empty)
        $windows = get_post_meta( $channel_id, 'foyer_channel_slide_windows', true );
        if ( empty( $windows ) || ! is_array( $windows ) ) { $windows = array(); }
        if ( empty( $windows[ $add_slide_id ] ) ) {
            $windows[ $add_slide_id ] = array( 'start' => null, 'end' => null );
            update_post_meta( $channel_id, 'foyer_channel_slide_windows', $windows );
        }

		echo self::get_slides_list_html( get_post( $channel_id ) );
		wp_die();
	}

	/**
	 * Adds the slides editor meta box to the channel admin page.
	 *
	 * @since	1.0.0
	 * @since	1.3.2	Changed method to static.
	 */
	static function add_slides_editor_meta_box() {
		add_meta_box(
			'foyer_slides_editor',
			_x( 'Slides', 'slide cpt', 'foyer' ),
			array( __CLASS__, 'slides_editor_meta_box' ),
			Foyer_Channel::post_type_name,
			'normal',
			'high'
		);
	}

	/**
	 * Ensures the core Publish meta box stays at the top of the sidebar stack.
	 *
	 * @since 1.8.x
	 */
	static function prioritize_publish_meta_box() {
		remove_meta_box( 'submitdiv', Foyer_Channel::post_type_name, 'side' );
		add_meta_box( 'submitdiv', __( 'Publish' ), 'post_submit_meta_box', Foyer_Channel::post_type_name, 'side', 'high' );
	}

	/**
	 * Adds the slide preview settings meta box to the channel admin page.
	 *
	 * @since 1.8.x
	 */
	static function add_slide_preview_meta_box() {
		add_meta_box(
			'foyer_slide_preview',
			__( 'Slide preview', 'foyer' ),
			array( __CLASS__, 'slide_preview_meta_box' ),
			Foyer_Channel::post_type_name,
			'side',
			'high'
		);
	}

	/**
	 * Adds the settings meta box to the channel admin page.
	 *
	 * @since	1.0.0
	 * @since	1.3.2	Changed method to static.
	 */
	static function add_slides_settings_meta_box() {
		add_meta_box(
			'foyer_slides_settings',
			__( 'Slideshow settings' , 'foyer' ),
			array( __CLASS__, 'slides_settings_meta_box' ),
			Foyer_Channel::post_type_name,
			'normal',
			'high'
		);
	}

	/**
	 * Adds the channel settings meta box (sidebar) to the channel admin page.
	 *
	 * @since 1.8.1
	 */
	static function add_channel_settings_meta_box() {
		add_meta_box(
			'foyer_channel_settings',
			__( 'Channel settings', 'foyer' ),
			array( __CLASS__, 'channel_settings_meta_box' ),
			Foyer_Channel::post_type_name,
			'side',
			'high'
		);
	}

	/**
	 * Determines the current visibility status of a slide window.
	 *
	 * @since 1.8.x
	 *
	 * @param int|null $start_ts_utc Optional UTC start timestamp.
	 * @param int|null $end_ts_utc   Optional UTC end timestamp.
	 * @param int|null $now_utc      Optional current UTC timestamp for comparisons.
	 *
	 * @return string One of 'active', 'upcoming', or 'expired'.
	 */
	protected static function determine_slide_window_status( $start_ts_utc, $end_ts_utc, $now_utc = null ) {
		if ( null === $now_utc ) {
			$now_utc = current_time( 'timestamp', true );
		}

		$start_ts_utc = ( is_numeric( $start_ts_utc ) && intval( $start_ts_utc ) > 0 ) ? intval( $start_ts_utc ) : null;
		$end_ts_utc   = ( is_numeric( $end_ts_utc ) && intval( $end_ts_utc ) > 0 ) ? intval( $end_ts_utc ) : null;

		if ( $start_ts_utc && $start_ts_utc > $now_utc ) {
			return 'upcoming';
		}
		if ( $end_ts_utc && $end_ts_utc < $now_utc ) {
			return 'expired';
		}

		return 'active';
	}

	/**
	 * Outputs the content of the slide preview meta box.
	 *
	 * @since 1.8.x
	 *
	 * @param WP_Post $post Current channel post.
	 */
	static function slide_preview_meta_box( $post ) {

		// Nonce for consistency with other channel meta boxes.
		wp_nonce_field( Foyer_Channel::post_type_name, Foyer_Channel::post_type_name . '_nonce' );

		$saved_ratio = get_post_meta( $post->ID, 'foyer_channel_preview_ratio', true );
		if ( empty( $saved_ratio ) ) {
			$saved_ratio = '9x16';
		}

		?>
		<div class="foyer_slide_preview_box">
			<p style="margin:0 0 6px; color:#72777c;">
				<?php echo esc_html__( 'Preview ratio', 'foyer' ); ?>
			</p>
			<label style="display:flex; align-items:center; gap:6px; margin-bottom:6px;">
				<input type="radio" name="foyer_preview_ratio" value="9x16" <?php echo ( '9x16' === $saved_ratio ) ? 'checked="checked"' : ''; ?> />
				<span>9:16</span>
			</label>
			<label style="display:flex; align-items:center; gap:6px;">
				<input type="radio" name="foyer_preview_ratio" value="16x9" <?php echo ( '16x9' === $saved_ratio ) ? 'checked="checked"' : ''; ?> />
				<span>16:9</span>
			</label>
			<p style="margin:10px 0 0; color:#72777c; font-size:12px;">
				<?php echo esc_html__( 'Controls how slide previews are scaled inside the editor.', 'foyer' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Outputs the content of the channel settings (sidebar) meta box.
	 *
	 * @since 1.8.1
	 *
	 * @param WP_Post $post
	 */
	static function channel_settings_meta_box( $post ) {

		// Nonce
		wp_nonce_field( Foyer_Channel::post_type_name, Foyer_Channel::post_type_name . '_nonce' );

	        ?>
	        <div class="foyer_channel_settings_box">
	            <p style="margin:0 0 6px; color:#72777c;"><?php echo esc_html__( 'Favorite', 'foyer' ); ?></p>
	            <label style="display:flex; align-items:center; gap:6px; margin-bottom:10px;">
	                <input type="checkbox" id="foyer_channel_is_favorite" name="foyer_channel_is_favorite" value="1" <?php echo get_post_meta( $post->ID, 'foyer_channel_is_favorite', true ) ? 'checked="checked"' : ''; ?> />
	                <span><?php echo esc_html__( 'Mark this channel as favorite', 'foyer' ); ?></span>
	            </label>

	            <hr style="margin:12px 0;" />
	            <p style="margin:0 0 6px; color:#72777c; font-weight:600;"><?php echo esc_html__( 'Slideshow settings', 'foyer' ); ?></p>
	            <table class="foyer_meta_box_form form-table foyer_channel_settings_slides_form" style="margin:0;">
                <tbody>
                    <?php
                        echo self::get_set_duration_html( $post );
                        echo self::get_set_transition_html( $post );
                    ?>
                </tbody>
            </table>
        </div>
        <?php
		}

	/**
	 * Outputs the Slides Count column.
	 *
	 * @since	1.0.0
	 * @since	1.3.2	Changed method to static.
	 *
	 * @param 	string	$column		The current column that needs output.
	 * @param 	int 	$post_id 	The current display ID.
	 * @return	void
	 */
    static function do_slides_count_column( $column, $post_id ) {
        if ( 'slides_count' === $column ) {
            $channel = new Foyer_Channel( $post_id );
            echo intval( count( $channel->get_slides() ) );
            return;
        }
        if ( 'favorite' === $column ) {
            $is_fav = (bool) get_post_meta( $post_id, 'foyer_channel_is_favorite', true );
            $icon = $is_fav ? '★' : '☆';
            $title = $is_fav ? __( 'Unmark favorite', 'foyer' ) : __( 'Mark favorite', 'foyer' );
            $cls = $is_fav ? 'foyer-fav is-fav' : 'foyer-fav';
            echo '<a href="#" class="foyer-fav-toggle ' . esc_attr( $cls ) . '" data-postid="' . intval( $post_id ) . '" aria-label="' . esc_attr( $title ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $icon ) . '</a>';
            echo '<style>.column-favorite{width:80px}.foyer-fav{font-size:18px; text-decoration:none;}.foyer-fav.is-fav{color:#d98900}</style>';
            return;
        }
    }

    /**
     * Toggles favorite flag over AJAX.
     *
     * @since 1.8.0
     */
    static function toggle_favorite_over_ajax() {
        check_ajax_referer( 'foyer_channel_admin_ajax_nonce', 'nonce', true );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $set     = sanitize_text_field( $_POST['set'] ?? '' );
        if ( empty( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'foyer' ) ), 403 );
        }
        if ( $set === '1' ) {
            update_post_meta( $post_id, 'foyer_channel_is_favorite', '1' );
        } else {
            delete_post_meta( $post_id, 'foyer_channel_is_favorite' );
        }
        wp_send_json_success( array( 'post_id' => $post_id, 'is_favorite' => ( $set === '1' ) ) );
    }

    /**
     * In the Channels admin list, show favorites first by default.
     *
     * Keeps user-chosen sorting intact (only applies when no explicit orderby set).
     *
     * @since 1.8.0
     */
    static function prefer_favorites_in_admin_list( $query ) {
        // No-op: replaced by posts_clauses-based ordering to include non-favorites as well.
    }

    /**
     * Modify SQL ORDER BY to put favorites first without filtering out others.
     *
     * @since 1.8.0
     */
    static function order_favorites_first_clause( $clauses, $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) { return $clauses; }
        $post_type = $query->get( 'post_type' );
        if ( empty( $post_type ) ) { $post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : ''; }
        if ( Foyer_Channel::post_type_name !== $post_type ) { return $clauses; }

        global $wpdb;
        $meta_key = 'foyer_channel_is_favorite';
        $case = "CASE WHEN EXISTS (SELECT 1 FROM {$wpdb->postmeta} pmf WHERE pmf.post_id = {$wpdb->posts}.ID AND pmf.meta_key = '" . esc_sql( $meta_key ) . "' AND pmf.meta_value = '1') THEN 0 ELSE 1 END";

        if ( ! empty( $clauses['orderby'] ) ) {
            $clauses['orderby'] = $case . ' ASC, ' . $clauses['orderby'];
        } else {
            $clauses['orderby'] = $case . ' ASC';
        }

        return $clauses;
    }

	/**
	 * Gets the HTML to add a slide in the slides editor.
	 *
	 * @since	1.0.0
	 * @since	1.0.1			Escaped and sanitized the output.
	 * @since	1.1.0			Fix: List of slides was limited to 5 items.
	 * @since	1.3.2	Changed method to static.
	 *
	 * @return	string	$html	The HTML to add a slide in the slides editor.
	 */
	static function get_add_slide_html() {

		ob_start();

            ?>
                <div class="foyer_slides_editor_add">
                    <h4><?php echo esc_html__( 'Available slides', 'foyer' ); ?></h4>
                    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin:8px 0 12px;">
                        <label for="foyer_slides_table_search" style="margin-right:6px;">
                            <?php echo esc_html__( 'Search', 'foyer' ); ?>
                        </label>
                        <input type="search" id="foyer_slides_table_search" class="regular-text" placeholder="<?php echo esc_attr__( 'Search by title or author…', 'foyer' ); ?>" style="max-width:320px;" />
                        <label style="display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" id="foyer_slides_table_hide_in_channel" checked="checked" />
                            <?php echo esc_html__( 'Hide slides already in this channel', 'foyer' ); ?>
                        </label>
                        <label for="foyer_slides_table_per_page" style="margin-left:auto;">
                            <?php echo esc_html__( 'Rows per page', 'foyer' ); ?>
                        </label>
                        <select id="foyer_slides_table_per_page">
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <?php
                        // Slides currently in this channel (to disable/hide in table)
                        $current_channel = new Foyer_Channel( get_post() );
                        $current_slides = $current_channel->get_slides();
                        $in_channel_ids = array();
                        if ( ! empty( $current_slides ) ) {
                            foreach ( $current_slides as $s ) { $in_channel_ids[] = intval( $s->ID ); }
                        }

                        // Saved preview ratio for selector thumbnails (default: 9x16)
                        $selector_ratio = get_post_meta( $current_channel->ID, 'foyer_channel_preview_ratio', true );
                        if ( empty( $selector_ratio ) ) { $selector_ratio = '9x16'; }

                        // Allow customization of the slides list via filters.
                        $query_args = apply_filters( 'foyer/admin/channel/add_slide_query_args', array( 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
                        $slides = Foyer_Slides::get_posts( $query_args );
                        $slides = apply_filters( 'foyer/admin/channel/add_slide_posts', $slides );
                    ?>
                    <div class="foyer-available-slides-table-wrapper">
                        <table class="widefat fixed striped foyer-available-slides-table" id="foyer_available_slides_table">
                        <thead>
                            <tr>
                                <th class="foyer-available-slides-table__col-preview"><?php echo esc_html__( 'Preview', 'foyer' ); ?></th>
                                <th data-sort="title" class="foyer-sort-col foyer-available-slides-table__col-title"><span class="sort-label"><?php echo esc_html_x( 'Title', 'post title', 'foyer' ); ?></span> <span class="sort-ind"></span></th>
                                <th data-sort="author" class="foyer-sort-col foyer-available-slides-table__col-author"><span class="sort-label"><?php echo esc_html__( 'Author', 'foyer' ); ?></span> <span class="sort-ind"></span></th>
                                <th data-sort="date" class="foyer-sort-col foyer-available-slides-table__col-date"><span class="sort-label"><?php echo esc_html__( 'Date', 'foyer' ); ?></span> <span class="sort-ind"></span></th>
                                <th data-sort="format" class="foyer-sort-col foyer-available-slides-table__col-format"><span class="sort-label"><?php echo esc_html__( 'Format', 'foyer' ); ?></span> <span class="sort-ind"></span></th>
                                <th data-sort="background" class="foyer-sort-col foyer-available-slides-table__col-background"><span class="sort-label"><?php echo esc_html__( 'Background', 'foyer' ); ?></span> <span class="sort-ind"></span></th>
                                <th class="foyer-available-slides-table__col-action">&nbsp;</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $slides ) ) : ?>
                            <tr>
                                <td colspan="7"><?php echo esc_html__( 'No slides found.', 'foyer' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $slides as $slide ) :
                                $slide_obj = new Foyer_Slide( $slide );
                                $format = Foyer_Slides::get_slide_format_by_slug( $slide_obj->get_format() );
                                $background = Foyer_Slides::get_slide_background_by_slug( $slide_obj->get_background() );
                                $is_in_channel = in_array( intval( $slide->ID ), $in_channel_ids, true );
                                $author_name = get_the_author_meta( 'display_name', $slide->post_author );
                                $date_str = get_the_date( get_option( 'date_format' ), $slide ) . ' ' . get_the_time( get_option( 'time_format' ), $slide );
                            ?>
                            <tr data-slide-id="<?php echo intval( $slide->ID ); ?>" data-in-channel="<?php echo $is_in_channel ? '1' : '0'; ?>" data-title="<?php echo esc_attr( get_the_title( $slide->ID ) ); ?>" data-author="<?php echo esc_attr( $author_name ); ?>" data-date-ts="<?php echo esc_attr( get_post_time( 'U', true, $slide ) ); ?>" data-format="<?php echo esc_attr( isset( $format['title'] ) ? $format['title'] : '' ); ?>" data-background="<?php echo esc_attr( isset( $background['title'] ) ? $background['title'] : '' ); ?>">
                                <td class="foyer-available-slides-table__col-preview">
                                    <?php
                                        $preview_args = array(
                                            'ratio'        => $selector_ratio,
                                            'wrap'         => false,
                                            'show_overlay' => false,
                                        );
                                        if ( '16x9' === $selector_ratio ) {
                                            $preview_args['width'] = 220;
                                        }
                                        echo self::get_slide_preview_html( $slide->ID, $preview_args );
                                    ?>
                                </td>
                                <td class="foyer-available-slides-table__col-title"><?php echo esc_html( get_the_title( $slide->ID ) ); ?></td>
                                <td class="foyer-available-slides-table__col-author"><?php echo esc_html( $author_name ); ?></td>
                                <td class="foyer-available-slides-table__col-date"><?php echo esc_html( $date_str ); ?></td>
                                <td class="foyer-available-slides-table__col-format"><?php echo esc_html( isset( $format['title'] ) ? $format['title'] : '' ); ?></td>
                                <td class="foyer-available-slides-table__col-background"><?php echo esc_html( isset( $background['title'] ) ? $background['title'] : '' ); ?></td>
                                <td class="foyer-available-slides-table__col-action">
                                    <button type="button" class="button button-primary foyer_add_slide_btn" data-slide-id="<?php echo intval( $slide->ID ); ?>" <?php echo $is_in_channel ? 'disabled' : ''; ?>><?php echo $is_in_channel ? esc_html__( 'Added', 'foyer' ) : esc_html__( 'Add', 'foyer' ); ?></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                        <div id="foyer_slides_table_pager" class="foyer-available-slides-table__pager">
                            <button type="button" class="button" id="foyer_slides_table_prev">&laquo; <?php echo esc_html__( 'Prev', 'foyer' ); ?></button>
                            <span id="foyer_slides_table_page_info"></span>
                            <button type="button" class="button" id="foyer_slides_table_next"><?php echo esc_html__( 'Next', 'foyer' ); ?> &raquo;</button>
                        </div>
                    </div>
                    <script type="text/javascript">
                    (function($){
                        $(function(){
                            var $metaBox = $('.foyer_meta_box.foyer_slides_editor');
                            var channelId = $metaBox.data('channel-id');
                            var $table = $('#foyer_available_slides_table');
                            var $rows = $table.find('tbody > tr');
                            var $search = $('#foyer_slides_table_search');
                            var $hideInChannel = $('#foyer_slides_table_hide_in_channel');
                            var $perPage = $('#foyer_slides_table_per_page');
                            var $pager = $('#foyer_slides_table_pager');
                            var $prev = $('#foyer_slides_table_prev');
                            var $next = $('#foyer_slides_table_next');
                            var $info = $('#foyer_slides_table_page_info');
                            var currentPage = 1;
                            var sortKey = 'title';
                            var sortDir = 'asc';

                            function applyFilters(){
                                var q = ($search.val()||'').toLowerCase();
                                var hideUsed = $hideInChannel.is(':checked');
                                $rows.each(function(){
                                    var $tr = $(this);
                                    var inChannel = $tr.data('in-channel') == 1;
                                    var title = (String($tr.data('title')||'')).toLowerCase();
                                    var author = (String($tr.data('author')||'')).toLowerCase();
                                    var matches = (!q || title.indexOf(q) !== -1 || author.indexOf(q) !== -1);
                                    var visible = matches && !(hideUsed && inChannel);
                                    $tr.toggle(visible);
                                });
                            }

                            function paginate(){
                                var per = parseInt($perPage.val(), 10) || 10;
                                // Reset to the full filtered set before slicing to avoid shrinking pool
                                $rows.show();
                                applyFilters();
                                var visibleRows = $rows.filter(':visible');
                                var total = visibleRows.length;
                                var totalPages = Math.max(1, Math.ceil(total / per));
                                if(currentPage > totalPages) currentPage = totalPages;
                                var start = (currentPage - 1) * per;
                                var end = start + per;
                                visibleRows.hide().slice(start, end).show();
                                $info.text(currentPage + ' / ' + totalPages);
                                $prev.prop('disabled', currentPage <= 1);
                                $next.prop('disabled', currentPage >= totalPages);
                            }

                            function compareRows(a, b){
                                var $a = $(a), $b = $(b);
                                var dir = (sortDir === 'asc') ? 1 : -1;
                                if (sortKey === 'date') {
                                    var ta = parseInt($a.data('date-ts'), 10) || 0;
                                    var tb = parseInt($b.data('date-ts'), 10) || 0;
                                    if (ta === tb) return 0;
                                    return (ta < tb ? -1 : 1) * dir;
                                } else {
                                    var sa = String($a.data(sortKey) || '').toLowerCase();
                                    var sb = String($b.data(sortKey) || '').toLowerCase();
                                    if (sa === sb) return 0;
                                    return (sa < sb ? -1 : 1) * dir;
                                }
                                // Close the panel after save
                                var $panel = $slideBlock.find('.foyer_slides_editor_slides_slide_schedule');
                                $panel.stop(true, true).slideUp(120);
                            }

                            function updateSortIndicators(){
                                var arrows = { asc: '\u25B2', desc: '\u25BC' };
                                $table.find('thead th.foyer-sort-col .sort-ind').text('');
                                var $th = $table.find('thead th.foyer-sort-col[data-sort="'+sortKey+'"]');
                                $th.find('.sort-ind').text(arrows[sortDir] || '');
                            }

                            function sortRows(){
                                var $tbody = $table.find('tbody');
                                var visible = $rows.filter(':visible').get();
                                visible.sort(compareRows);
                                $tbody.append(visible);
                                $tbody.append($rows.filter(':hidden'));
                                updateSortIndicators();
                            }

                            function refresh(){
                                // First, show all rows to allow filtering logic to work on full set
                                $rows.show();
                                applyFilters();
                                sortRows();
                                paginate();
                            }

                            $search.on('input', function(){ currentPage = 1; refresh(); });
                            $hideInChannel.on('change', function(){ currentPage = 1; refresh(); });
                            $perPage.on('change', function(){ currentPage = 1; refresh(); });
                            $prev.on('click', function(){ if(currentPage>1){ currentPage--; paginate(); } });
                            $next.on('click', function(){ currentPage++; paginate(); });

                            // Sorting handlers
                            $table.find('thead').on('click', 'th.foyer-sort-col', function(){
                                var key = $(this).data('sort');
                                if (!key) return;
                                if (key === sortKey) {
                                    sortDir = (sortDir === 'asc') ? 'desc' : 'asc';
                                } else {
                                    sortKey = key;
                                    sortDir = 'asc';
                                }
                                currentPage = 1;
                                refresh();
                            });

                            refresh();

                            $(document).on('click', '.foyer_add_slide_btn', function(e){
                                e.preventDefault();
                                var $btn = $(this);
                                var slideId = parseInt($btn.data('slide-id'), 10);
                                if(!channelId || !slideId) return;
                                $btn.prop('disabled', true);
                                $.post(ajaxurl, {
                                    action: 'foyer_slides_editor_add_slide',
                                    channel_id: channelId,
                                    slide_id: slideId,
                                    nonce: (window.foyer_slides_editor_security ? foyer_slides_editor_security.nonce : '')
                                })
                                .done(function(html){
                                    // Replace slides list with refreshed HTML
                                    var $list = $('.foyer_slides_editor_slides');
                                    if($list.length && html){
                                        $list.replaceWith(html);
                                        if (window.foyerInitSlidesEditor) {
                                            window.foyerInitSlidesEditor();
                                        }
                                        var activeRatio = $('input[name="foyer_preview_ratio"]:checked').val() || null;
                                        $(document).trigger('foyer:slides-preview-refresh', [activeRatio]);
                                        // Mark row as in-channel and disable button
                                        var $row = $table.find('tr[data-slide-id="'+slideId+'"]');
                                        $row.attr('data-in-channel','1');
                                        $row.find('.foyer_add_slide_btn').prop('disabled', true).text('<?php echo esc_js( __( 'Added', 'foyer' ) ); ?>');
                                        refresh();
                                    } else {
                                        $btn.prop('disabled', false);
                                    }
                                })
                                .fail(function(){
                                    $btn.prop('disabled', false);
                                });
                            });
                        });
                    })(jQuery);
                    </script>
                </div>
                <?php

		$html = ob_get_clean();

		return $html;
	}

	/**
	 * Gets the HTML to set the slides duration in the slides settings meta box.
	 *
	 * @since	1.0.0
	 * @since	1.0.1	Escaped the output.
	 * @since	1.3.2	Changed method to static.
	 *
	 * @param	WP_Post	$post	The post object of the current display.
	 * @return	string	$html	The HTML to set the slides duration in the slides settings meta box.
	 */
	static function get_set_duration_html( $post ) {

		$duration_options = self::get_slides_duration_options();
		$default_duration = Foyer_Slides::get_default_slides_duration();

		$default_option_name = '(' . __( 'Default', 'foyer' );
		if ( ! empty( $duration_options[ $default_duration ] ) ) {
			$default_option_name .= ' [' . $duration_options[ $default_duration ] . ']';
		}
		$default_option_name .= ')';

		$channel = new Foyer_Channel( $post );
		$selected_duration = $channel->get_saved_slides_duration();

		ob_start();

		?>
			<tr>
				<th>
					<label for="foyer_slides_settings_duration">
						<?php echo esc_html__( 'Duration', 'foyer' ); ?>
					</label>
				</th>
				<td>
					<select id="foyer_slides_settings_duration" name="foyer_slides_settings_duration">
						<option value=""><?php echo esc_html( $default_option_name ); ?></option>
						<?php
							foreach ( $duration_options as $key => $name ) {
								$selected = '';
								if ( $selected_duration == $key ) {
									$selected = 'selected="selected"';
								}
								?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php echo $selected; ?>>
										<?php echo esc_html( $name ); ?>
									</option>
								<?php
							}
						?>
					</select>
				</td>
			</tr>
		<?php

		$html = ob_get_clean();

		return $html;
	}

	/**
	 * Gets the HTML to set the slides transition in the slides settings meta box.
	 *
	 * @since	1.0.0
	 * @since	1.0.1	Escaped the output.
	 * @since	1.3.2	Changed method to static.
	 *
	 * @param	WP_Post	$post	The post object of the current display.
	 * @return	string	$html	The HTML to set the slides transition in the slides settings meta box.
	 */
	static function get_set_transition_html( $post ) {

		$transition_options = self::get_slides_transition_options();
		$default_transition = Foyer_Slides::get_default_slides_transition();

		$default_option_name = '(' . __( 'Default', 'foyer' );
		if ( ! empty( $transition_options[ $default_transition ] ) ) {
			$default_option_name .= ' [' . $transition_options[ $default_transition ] . ']';
		}
		$default_option_name .= ')';

		$channel = new Foyer_Channel( $post );
		$selected_transition = $channel->get_saved_slides_transition();

		ob_start();

		?>
			<tr>
				<th>
					<label for="foyer_slides_settings_transition">
						<?php echo esc_html__( 'Transition', 'foyer' ); ?>
					</label>
				</th>
				<td>
					<select id="foyer_slides_settings_transition" name="foyer_slides_settings_transition">
						<option value=""><?php echo esc_html( $default_option_name ); ?></option>
						<?php
							foreach ( $transition_options as $key => $name ) {
								$selected = '';
								if ( $selected_transition == $key ) {
									$selected = 'selected="selected"';
								}
								?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php echo $selected; ?>>
										<?php echo esc_html( $name ); ?>
									</option>
								<?php
							}
						?>
					</select>
				</td>
			</tr>
		<?php

		$html = ob_get_clean();

		return $html;
	}

	/**
	 * Gets the slides duration options.
	 *
	 * @since	1.0.0
	 * @since	1.2.4	Added longer slide durations, up to 120 seconds.
	 * @since	1.3.2	Changed method to static.
	 *
	 * @return	array	The slides duration options.
	 */
	static function get_slides_duration_options() {

		for ( $sec = 2; $sec <= 20; $sec++ ) {
			$secs[] = $sec;
		}
		for ( $sec = 25; $sec <= 60; $sec += 5 ) {
			$secs[] = $sec;
		}
		for ( $sec = 90; $sec <= 120; $sec += 30 ) {
			$secs[] = $sec;
		}

		$slides_duration_options = array();
		foreach ( $secs as $sec ) {
			$slides_duration_options[ $sec ] = $sec . ' ' . _n( 'second', 'seconds', $sec, 'foyer' );
		}

		/**
		 * Filter available slides duration options.
		 *
		 * @since	1.0.0
		 * @param	array	$slides_duration_options	The currently available slides duration options.
		 */
		$slides_duration_options = apply_filters( 'foyer/slides/duration/options', $slides_duration_options );

		return $slides_duration_options;
	}

	/**
	 * Gets the HTML that lists all slides in the slides editor.
	 *
	 * @since	1.0.0
	 * @since	1.0.1	Escaped and sanitized the output.
	 * @since	1.3.2	Changed method to static.
	 * @since	1.5.0	Added a foyer-slide-is-stack class to stack slides.
	 *					Added an overlay for slides containing slide title, format and background, to be shown on hover.
	 * @since	1.5.1	Removed the translatable string 'x' to make translation easier.
	 * @since	1.7.4	Added a filter that allows diplaying of slide previews to be disabled.
	 *
	 * @param	WP_Post	$post
	 * @return	string	$html	The HTML that lists all slides in the slides editor.
	 */
        static function get_slides_list_html( $post ) {

            $channel = new Foyer_Channel( $post );
            $slides = $channel->get_slides();
            // Saved preview ratio for thumbnails (default: 9x16)
            $saved_ratio = get_post_meta( $channel->ID, 'foyer_channel_preview_ratio', true );
            if ( empty( $saved_ratio ) ) { $saved_ratio = '9x16'; }

            // Load existing per-slide windows (UTC timestamps)
            $slide_windows = get_post_meta( $channel->ID, 'foyer_channel_slide_windows', true );
            if ( empty( $slide_windows ) || ! is_array( $slide_windows ) ) {
                $slide_windows = array();
            }

		/**
		 * Filters whether to display slide previews.
		 *
		 * @since	1.7.4
		 *
		 * @param	bool	$display_slide_previews		Indicates whether to display slide previews on the channel
		 *												admin screen or not.
		 */
		$display_slide_previews = apply_filters( 'foyer/admin/channel/display_slide_previews', true );

		ob_start();

		?>
			<div class="foyer_slides_editor_slides<?php echo ( $saved_ratio === '16x9' ? ' ratio-16-9' : '' ); ?>">
				<?php

					if ( empty( $slides ) ) {
						?><p>
							<?php echo esc_html__( 'No slides in this channel yet.', 'foyer' ); ?>
						</p><?php
					}
						else {

							$now_utc = current_time( 'timestamp', true );
							$i = 0;
						foreach( $slides as $slide ) {

							$slide_url = get_permalink( $slide->ID );
							$slide_url = add_query_arg( 'foyer-preview', 1, $slide_url );
							$slide_format_data = Foyer_Slides::get_slide_format_by_slug( $slide->get_format() );
							$slide_background_data = Foyer_Slides::get_slide_background_by_slug( $slide->get_background() );

								?>
									<div class="foyer_slides_editor_slides_slide<?php
										if ( $slide->is_stack() ) { echo ' foyer-slide-is-stack'; }
									?>"
										data-slide-id="<?php echo intval( $slide->ID ); ?>"
										data-slide-key="<?php echo $i; ?>"
									>
										<div class="foyer_slides_editor_slides_slide_caption">
										<?php echo esc_html_x( 'Slide', 'slide cpt', 'foyer' ) . ' ' . ( $i + 1 ); ?>
                                    <button type="button" class="button-link foyer-slide-window-toggle" data-slide-id="<?php echo intval( $slide->ID ); ?>" title="<?php echo esc_attr__( 'Edit visibility time window', 'foyer' ); ?>" style="margin-left:8px;">
                                        <span class="dashicons dashicons-clock" aria-hidden="true"></span>
                                        <span class="screen-reader-text"><?php echo esc_html__( 'Edit visibility time window', 'foyer' ); ?></span>
                                    </button>
										<a href="#" class="foyer_slides_editor_slides_slide_remove" aria-label="<?php echo esc_attr__( 'Remove slide from channel', 'foyer' ); ?>" title="<?php echo esc_attr__( 'Remove slide from channel', 'foyer' ); ?>" style="margin-left:8px;">
											<span class="dashicons dashicons-trash" aria-hidden="true"></span>
											<span class="screen-reader-text"><?php echo esc_html__( 'Remove slide', 'foyer' ); ?></span>
										</a>
										</div>
										<?php
											// Reuse generic preview builder; fall back to overlay-only when previews are disabled
											if ( $display_slide_previews ) {
												echo self::get_slide_preview_html( $slide->ID, array(
												'ratio'        => $saved_ratio,
												'wrap'         => false,
												'show_overlay' => true,
											) );
										} else {
								?>
									<div class="foyer_slides_editor_slides_slide_iframe_container">
										<div class="foyer_slides_editor_slides_slide_iframe_container_overlay">
											<h4><?php echo esc_html( get_the_title( $slide->ID ) ); ?></h4>
											<dl>
												<dt><?php _e( 'Format', 'foyer'); ?></dt>
												<dd><?php echo esc_html( $slide_format_data['title'] ); ?></dd>
											</dl>
											<dl>
												<dt><?php _e( 'Background', 'foyer'); ?></dt>
												<dd><?php echo esc_html( $slide_background_data['title'] ); ?></dd>
											</dl>
										</div>
									</div>
										<?php
									}
									?>
									<?php
										$start_utc = null;
										$end_utc   = null;
                                    $scheduler_defaults = Foyer_Admin_Display::get_channel_scheduler_defaults();
                                    $picker_format = ! empty( $scheduler_defaults['picker_format'] ) ? $scheduler_defaults['picker_format'] : $scheduler_defaults['datetime_format'];
                                    $site_tz       = wp_timezone();
                                    if ( isset( $slide_windows[ $slide->ID ]['start'] ) && ! empty( $slide_windows[ $slide->ID ]['start'] ) ) {
                                        $raw = $slide_windows[ $slide->ID ]['start'];
                                        if ( is_numeric( $raw ) ) {
                                            $start_utc = intval( $raw );
                                        } else {
                                            $parsed = Foyer_Admin_Display::parse_schedule_input( $raw, $picker_format, $site_tz );
                                            if ( ! is_null( $parsed ) ) {
                                                $start_utc = intval( $parsed );
                                            }
                                        }
                                        if ( ! is_null( $start_utc ) ) {
                                            $slide_windows[ $slide->ID ]['start'] = $start_utc;
                                        }
                                    }
                                    if ( isset( $slide_windows[ $slide->ID ]['end'] ) && ! empty( $slide_windows[ $slide->ID ]['end'] ) ) {
                                        $raw = $slide_windows[ $slide->ID ]['end'];
                                        if ( is_numeric( $raw ) ) {
                                            $end_utc = intval( $raw );
                                        } else {
                                            $parsed = Foyer_Admin_Display::parse_schedule_input( $raw, $picker_format, $site_tz );
                                            if ( ! is_null( $parsed ) ) {
                                                $end_utc = intval( $parsed );
                                            }
                                        }
                                        if ( ! is_null( $end_utc ) ) {
                                            $slide_windows[ $slide->ID ]['end'] = $end_utc;
                                        }
                                    }

										// Compact summary badge text based on configured window.
										$summary_text = '';
										if ( $start_utc || $end_utc ) {
											$fmt_day_time    = 'd.m. H:i';
											$fmt_time        = 'H:i';
											$offset_seconds  = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
											if ( $start_utc && $end_utc ) {
												$sv = date_i18n( $fmt_day_time, $start_utc + $offset_seconds, true );
												if ( date_i18n( 'Ymd', $start_utc, true ) === date_i18n( 'Ymd', $end_utc, true ) ) {
													$ev_time = date_i18n( $fmt_time, $end_utc + $offset_seconds, true );
													$summary_text = $sv . '–' . $ev_time;
												} else {
													$ev = date_i18n( $fmt_day_time, $end_utc + $offset_seconds, true );
													$summary_text = $sv . '–' . $ev;
												}
											} elseif ( $start_utc ) {
												$summary_text = '≥ ' . date_i18n( $fmt_day_time, $start_utc + $offset_seconds, true );
											} elseif ( $end_utc ) {
												$summary_text = '≤ ' . date_i18n( $fmt_day_time, $end_utc + $offset_seconds, true );
											}
										}

										$summary_status = self::determine_slide_window_status( $start_utc, $end_utc, $now_utc );
										$summary_display = ( '' === $summary_text ) ? '∞' : $summary_text;
									?>
									<div class="foyer-slide-window-summary status-<?php echo esc_attr( $summary_status ); ?>">
										<span class="foyer-slide-window-badge"><?php echo esc_html( $summary_display ); ?></span>
									</div>
                                <?php
                                    $scheduler_defaults = Foyer_Admin_Display::get_channel_scheduler_defaults();
                                    $w = isset( $slide_windows[ $slide->ID ] ) ? $slide_windows[ $slide->ID ] : array();
                                    $start_val = '';
                                    $end_val = '';
                                    if ( ! empty( $w['start'] ) ) {
                                    $start_val = Foyer_Admin_Display::format_schedule_display( intval( $w['start'] ), $picker_format );
                                    }
                                    if ( ! empty( $w['end'] ) ) {
                                        $end_val = Foyer_Admin_Display::format_schedule_display( intval( $w['end'] ), $picker_format );
                                    }
                                ?>
                                <div class="foyer_slides_editor_slides_slide_schedule" style="padding:8px 12px 12px; background:#f8f8f8; border:1px solid #e2e2e2; margin-top:6px; display:none;">
                                    <h4 class="foyer-slide-window-heading"><?php echo esc_html__( 'Visibility time window', 'foyer' ); ?></h4>
                                    <div class="foyer-slide-window-field">
                                        <label class="foyer-slide-window-label" for="foyer_slide_window_start_<?php echo intval( $slide->ID ); ?>"><?php echo esc_html__( 'Visible from', 'foyer' ); ?></label>
                                        <input type="text" class="foyer-slide-window-start foyer-slide-window-input" id="foyer_slide_window_start_<?php echo intval( $slide->ID ); ?>" value="<?php echo esc_attr( $start_val ); ?>" />
                                    </div>
                                    <div class="foyer-slide-window-field">
                                        <label class="foyer-slide-window-label" for="foyer_slide_window_end_<?php echo intval( $slide->ID ); ?>"><?php echo esc_html__( 'Until', 'foyer' ); ?></label>
                                        <input type="text" class="foyer-slide-window-end foyer-slide-window-input" id="foyer_slide_window_end_<?php echo intval( $slide->ID ); ?>" value="<?php echo esc_attr( $end_val ); ?>" />
                                    </div>
                                    <div class="foyer-slide-window-actions">
                                        <button type="button" class="button-secondary foyer-slide-window-delete" data-slide-id="<?php echo intval( $slide->ID ); ?>">
                                            <?php echo esc_html__( 'Clear', 'foyer' ); ?>
                                        </button>
                                        <button type="button" class="button foyer-slide-window-save" data-slide-id="<?php echo intval( $slide->ID ); ?>">
                                            <?php echo esc_html__( 'Save', 'foyer' ); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
							<?php

							$i++;
						}
					}
				?>
                </div>
                <script type="text/javascript">
                (function($){
                    $(function(){
                        function updateSlideWindowSummary($slideBlock, summaryText, status){
                            var $summaryWrap = $slideBlock.find('.foyer-slide-window-summary');
                            var $preview = $slideBlock.find('.foyer_slides_editor_slides_slide_iframe_container').last();

                            function ensureSummaryWrap(){
                                if (!$summaryWrap.length) {
                                    $summaryWrap = $('<div/>', { 'class': 'foyer-slide-window-summary status-active' });
                                    if ($preview.length) {
                                        $summaryWrap.insertAfter($preview);
                                    } else {
                                        $summaryWrap.prependTo($slideBlock);
                                    }
                                }
                                return $summaryWrap;
                            }

                            function applyStatus($target, state){
                                if (!$target || !$target.length) {
                                    return;
                                }
                                var allowed = ['active', 'upcoming', 'expired'];
                                var normalized = (typeof state === 'string') ? state.toLowerCase() : '';
                                if ($.inArray(normalized, allowed) === -1) {
                                    normalized = 'active';
                                }
                                var classes = $.map(allowed, function(item){ return 'status-' + item; }).join(' ');
                                $target.removeClass(classes).addClass('status-' + normalized);
                            }

                            var $wrap = ensureSummaryWrap();
                            applyStatus($wrap, status);

                            var displayText = (summaryText && summaryText.length) ? summaryText : '∞';
                            $wrap.empty();
                            $('<span/>', { 'class': 'foyer-slide-window-badge', text: displayText }).appendTo($wrap);
                        }

                        // Preview ratio toggle: update UI and persist to DB
                        function applyRatio(val){
                            var $metaBox = $('.foyer_meta_box.foyer_slides_editor');
                            if (!$metaBox.length) {
                                return;
                            }
                            var $list = $metaBox.find('.foyer_slides_editor_slides');
                            if (!$list.length) {
                                return;
                            }

                            var isWide = (val === '16x9');
                            $list.toggleClass('ratio-16-9', isWide);

                            var frameW = isWide ? 1920 : 1080;
                            var frameH = isWide ? 1080 : 1920;
                            var defaultScale = 0.1;
                            var defaultWidth = frameW * defaultScale;

                            var metaWidth = $metaBox.innerWidth();
                            if (!metaWidth || metaWidth <= 0) {
                                metaWidth = $metaBox.closest('.postbox').innerWidth();
                            }

                            var horizontalPadding = 0;
                            var padLeft = parseFloat($list.css('padding-left'));
                            var padRight = parseFloat($list.css('padding-right'));
                            if (!isNaN(padLeft)) {
                                horizontalPadding += padLeft;
                            }
                            if (!isNaN(padRight)) {
                                horizontalPadding += padRight;
                            }

                            var $sampleSlide = $list.children('.foyer_slides_editor_slides_slide').first();
                            var marginLeft = 0;
                            var marginRight = 0;
                            if ($sampleSlide.length) {
                                var tmpLeft = parseFloat($sampleSlide.css('margin-left'));
                                var tmpRight = parseFloat($sampleSlide.css('margin-right'));
                                if (!isNaN(tmpLeft)) {
                                    marginLeft = tmpLeft;
                                }
                                if (!isNaN(tmpRight)) {
                                    marginRight = tmpRight;
                                }
                            }

                            var availableWidth = metaWidth - horizontalPadding - marginLeft - marginRight;
                            availableWidth = Math.max(80, availableWidth);
                            var targetWidth = Math.min(defaultWidth, availableWidth);
                            var scale = targetWidth / frameW;
                            if (!scale || scale <= 0) {
                                scale = defaultScale;
                                targetWidth = defaultWidth;
                            }
                            var targetHeight = Math.round(frameH * scale);

                            $list.find('.foyer_slides_editor_slides_slide .foyer_slides_editor_slides_slide_iframe_container')
                                 .css({ width: Math.round(targetWidth) + 'px', height: targetHeight + 'px' });
                            $list.find('.foyer_slides_editor_slides_slide .foyer_slides_editor_slides_slide_iframe_container iframe')
                                 .css({
                                     width: frameW + 'px',
                                     height: frameH + 'px',
                                     transform: 'scale(' + scale + ')',
                                     'transform-origin': 'top left',
                                     left: '0px',
                                     top: '0px'
                                 });
                            $list.find('.foyer-slide-window-badge').css('max-width', Math.round(targetWidth) + 'px');
                        }

                        // Initialize once from current selection
                        var initVal = $('input[name="foyer_preview_ratio"]:checked').val() || '9x16';
                        var currentRatioVal = initVal;
                        applyRatio(currentRatioVal);

                        $(document).on('change', 'input[name="foyer_preview_ratio"]', function(){
                            var val = $(this).val();
                            currentRatioVal = val;
                            applyRatio(currentRatioVal);
                            var $metaBox = $('.foyer_meta_box.foyer_slides_editor');
                            var channelId = $metaBox.data('channel-id');
                            if(!channelId) return;
                            $.post(ajaxurl, {
                                action: 'foyer_channel_set_preview_ratio',
                                channel_id: channelId,
                                ratio: val,
                                nonce: (window.foyer_slides_editor_security ? foyer_slides_editor_security.nonce : '')
                            });
                        });

                        var resizeTimer = null;
                        $(window).on('resize.foyerSlidesEditor', function(){
                            if (resizeTimer) {
                                clearTimeout(resizeTimer);
                            }
                            resizeTimer = setTimeout(function(){
                                applyRatio(currentRatioVal);
                            }, 120);
                        });

                        $(document).on('foyer:slides-preview-refresh', function(event, requestedRatio){
                            if (requestedRatio) {
                                currentRatioVal = requestedRatio;
                            }
                            applyRatio(currentRatioVal);
                        });

                        window.foyerSlidesEditorRefreshPreviews = function(nextRatio){
                            if (nextRatio) {
                                currentRatioVal = nextRatio;
                            }
                            applyRatio(currentRatioVal);
                        };

                        // Init datetimepickers for per-slide windows (lazy on first open)
                        function initPickers($scope){
                            if (!window.foyer_channel_scheduler_defaults) return;
                            $scope.find('.foyer-slide-window-start, .foyer-slide-window-end').each(function(){
                                var $input = $(this);
                                if ($input.data('picker-initialized')) return;
                                $input.foyer_datetimepicker({
                                    format: foyer_channel_scheduler_defaults.picker_format || foyer_channel_scheduler_defaults.datetime_format,
                                    dayOfWeekStart: foyer_channel_scheduler_defaults.start_of_week,
                                    step: 15,
                                    validateOnBlur: false
                                });
                                $input.data('picker-initialized', true);
                            });
                        }

                        // Prevent outside click from immediately closing when interacting inside
                        $(document).on('click', '.foyer_slides_editor_slides_slide_schedule', function(e){
                            e.stopPropagation();
                        });

                        // Toggle schedule panel
                        $(document).on('click', '.foyer-slide-window-toggle', function(e){
                            e.preventDefault();
                            e.stopPropagation();
                            if (e.stopImmediatePropagation) e.stopImmediatePropagation();
                            var $btn = $(this);
                            var $slideBlock = $btn.closest('.foyer_slides_editor_slides_slide');
                            var $panel = $slideBlock.find('.foyer_slides_editor_slides_slide_schedule');
                            $panel.stop(true, true).slideToggle(120, function(){
                                if ($panel.is(':visible')) initPickers($panel);
                            });
                            return false;
                        });

                        // Close any open schedule when clicking outside
                        $(document).on('click.foyerSlidesScheduleDismiss', function(e){
                            var $target = $(e.target);
                            if ($target.closest('.foyer_slides_editor_slides_slide_schedule').length || $target.closest('.foyer-slide-window-toggle').length) {
                                return;
                            }
                            var $openPanels = $('.foyer_slides_editor_slides_slide_schedule:visible');
                            if ($openPanels.length) {
                                $openPanels.stop(true, true).slideUp(120);
                            }
                        });

                        // Save per-slide window
                            $(document).on('click', '.foyer-slide-window-save', function(e){
                                e.preventDefault();
                                var $btn = $(this);
                                var $wrap = $btn.closest('.foyer_slides_editor');
                                var channelId = $wrap.data('channel-id');
                                var slideId = parseInt($btn.data('slide-id'), 10);
                                var $slideBlock = $btn.closest('.foyer_slides_editor_slides_slide');
                                var $panel = $slideBlock.find('.foyer_slides_editor_slides_slide_schedule');
                                var start = $slideBlock.find('.foyer-slide-window-start').val();
                                var end = $slideBlock.find('.foyer-slide-window-end').val();
                                if(!channelId || !slideId) return;
                                $btn.prop('disabled', true);
                                $.post(ajaxurl, {
                                    action: 'foyer_channel_set_slide_window',
                                    channel_id: channelId,
                                    slide_id: slideId,
                                    start: start,
                                    end: end,
                                    nonce: (window.foyer_slides_editor_security ? foyer_slides_editor_security.nonce : '')
                                }).done(function(resp){
                                $btn.addClass('button-primary');
                                setTimeout(function(){ $btn.removeClass('button-primary'); }, 600);
                                var txt = '';

                                if (start && end) {
                                    txt = start + ' – ' + end;
                                } else if (start) {
                                    txt = '≥ ' + start;
                                } else if (end) {
                                    txt = '≤ ' + end;
                                }

                                var status = 'active';
                                var displaySummary = txt;
                                var displayStatus = status;

                                if (resp && resp.success && resp.data) {
                                    if (typeof resp.data.summary !== 'undefined') {
                                        displaySummary = resp.data.summary || '';
                                    }
                                    if (typeof resp.data.status !== 'undefined' && resp.data.status) {
                                        displayStatus = resp.data.status;
                                    }
                                }

                                updateSlideWindowSummary($slideBlock, displaySummary, displayStatus);

                                if (resp && resp.success) {
                                    $panel.stop(true, true).slideUp(120);
                                }
                                }).always(function(){
                                    $btn.prop('disabled', false);
                                });
                            });

                        $(document).on('click', '.foyer-slide-window-delete', function(e){
                            e.preventDefault();
                            var $btn = $(this);
                            var $wrap = $btn.closest('.foyer_slides_editor');
                            var channelId = $wrap.data('channel-id');
                            var slideId = parseInt($btn.data('slide-id'), 10);
                            var $slideBlock = $btn.closest('.foyer_slides_editor_slides_slide');
                            var $panel = $slideBlock.find('.foyer_slides_editor_slides_slide_schedule');
                            if(!channelId || !slideId) return;

                            $slideBlock.find('.foyer-slide-window-start').val('');
                            $slideBlock.find('.foyer-slide-window-end').val('');

                            $btn.prop('disabled', true);
                            $.post(ajaxurl, {
                                action: 'foyer_channel_set_slide_window',
                                channel_id: channelId,
                                slide_id: slideId,
                                start: '',
                                end: '',
                                nonce: (window.foyer_slides_editor_security ? foyer_slides_editor_security.nonce : '')
                            }).done(function(resp){
                                var displaySummary = '';
                                var displayStatus = 'active';

                                if (resp && resp.success && resp.data) {
                                    if (typeof resp.data.summary !== 'undefined') {
                                        displaySummary = resp.data.summary || '';
                                    }
                                    if (typeof resp.data.status !== 'undefined' && resp.data.status) {
                                        displayStatus = resp.data.status;
                                    }
                                }

                                updateSlideWindowSummary($slideBlock, displaySummary, displayStatus);

                                if (resp && resp.success) {
                                    $panel.stop(true, true).slideUp(120);
                                }
                            }).always(function(){
                                $btn.prop('disabled', false);
                            });
                        });
                    });
                })(jQuery);
                </script>
                <?php

		$html = ob_get_clean();

		return $html;
	}

	/**
	 * Gets the slides transition options.
	 *
	 * @since	1.0.0
	 * @since	1.2.4	Added a ‘No transition’ option.
	 * @since	1.3.2	Changed method to static.
	 *
	 * @return	array	The slides transition options.
	 */
	static function get_slides_transition_options() {

		$slides_transition_options = array(
			'fade' => __( 'Fade', 'foyer' ),
			'slide' => __( 'Slide', 'foyer' ),
			'none' => __( 'No transition', 'foyer' ),
		);

		/**
		 * Filter available slides transition options.
		 *
		 * @since	1.0.0
		 * @param	array	$slides_transition_options	The currently available slides transition options.
		 */
		$slides_transition_options = apply_filters( 'foyer/slides/transition/options', $slides_transition_options );

		return $slides_transition_options;
	}

	/**
	 * Localizes the JavaScript for the channel admin area.
	 *
	 * @since	1.0.0
	 * @since	1.0.1	Escaped the output.
	 * @since	1.2.6	Changed handle of script to {plugin_name}-admin.
	 * @since	1.3.2	Changed method to static.
	 */
    static function localize_scripts() {

        $defaults = array( 'confirm_remove_message' => esc_html__( 'Are you sure you want to remove this slide from the channel?', 'foyer' ) );
        wp_localize_script( Foyer::get_plugin_name() . '-admin', 'foyer_slides_editor_defaults', $defaults );

        $security = array( 'nonce' => wp_create_nonce( 'foyer_slides_editor_ajax_nonce' ) );
        wp_localize_script( Foyer::get_plugin_name() . '-admin', 'foyer_slides_editor_security', $security );

        // Nonce for channel list actions (e.g., toggle favorite)
        $chan_sec = array( 'nonce' => wp_create_nonce( 'foyer_channel_admin_ajax_nonce' ) );
        wp_localize_script( Foyer::get_plugin_name() . '-admin', 'foyer_channels_list_security', $chan_sec );

        // Lightweight inline script: favorites toggle
        $inline_js = <<<'JS'
(function($){$(function(){
$(document).on('click','.foyer-fav-toggle',function(e){
    e.preventDefault();
    var $a=$(this);
    var id=$a.data('postid');
    if(!id) return;
    var willSet=$a.hasClass('is-fav')? '0':'1';
    $a.addClass('is-busy');
    $.post(ajaxurl,{
        action:'foyer_channel_toggle_favorite',
        nonce:(window.foyer_channels_list_security?foyer_channels_list_security.nonce:''),
        post_id:id,
        set:willSet
    }).done(function(resp){
        if(resp&&resp.success){
            if(willSet==='1'){ $a.addClass('is-fav').text('★'); }
            else { $a.removeClass('is-fav').text('☆'); }
        }
    }).always(function(){ $a.removeClass('is-busy'); });
});
});})(jQuery);
JS;
        if ( function_exists( 'wp_add_inline_script' ) ) {
            wp_add_inline_script( Foyer::get_plugin_name() . '-admin', $inline_js, 'after' );
        }
    }

	/**
	 * Removes the sample permalink from the Channel edit screen.
	 *
	 * @since	1.0.0
	 * @since	1.3.2	Changed method to static.
	 *
	 * @param 	string	$sample_permalink
	 * @return 	string
	 */
	static function remove_sample_permalink( $sample_permalink ) {

		$screen = get_current_screen();

		// Bail if not on Channel edit screen.
		if ( empty( $screen ) || Foyer_Channel::post_type_name != $screen->post_type ) {
			return $sample_permalink;
		}

		return '';
	}

	/**
	 * Removes a slide over AJAX and outputs the updated slides list HTML.
	 *
	 * @since	1.0.0
	 * @since	1.0.1	Validated & sanitized the user input.
	 * @since	1.2.4	You can now remove the first slide (slide_key 0) of a channel. Fixes #1.
	 * @since	1.3.2	Changed method to static.
	 *
	 * @return	void
	 */
    static function remove_slide_over_ajax() {

		check_ajax_referer( 'foyer_slides_editor_ajax_nonce', 'nonce' , true );

		$channel_id = intval( $_POST['channel_id'] );
		$remove_slide_key = intval( $_POST['slide_key'] );

		if ( empty( $channel_id ) ) {
			wp_die();
		}

		/* Check if this post exists */
		if ( is_null( get_post( $channel_id  ) ) ) {
			wp_die();
		}

		$channel = new Foyer_Channel( $channel_id );
		$slides = $channel->get_slides();

		/* Check if the channel has slides */
		if ( empty( $slides ) ) {
			wp_die();
		}

		$new_slides = array();
		foreach( $slides as $slide ) {
			$new_slides[] = $slide->ID;
		}

		if ( ! isset( $new_slides[$remove_slide_key] ) ) {
			wp_die();
		}

        $removed_id = $new_slides[$remove_slide_key];
        unset( $new_slides[$remove_slide_key] );
        update_post_meta( $channel_id, Foyer_Slide::post_type_name, $new_slides );
        // Remove any window entry for this slide
        $windows = get_post_meta( $channel_id, 'foyer_channel_slide_windows', true );
        if ( ! empty( $windows ) && is_array( $windows ) && isset( $windows[ $removed_id ] ) ) {
            unset( $windows[ $removed_id ] );
            update_post_meta( $channel_id, 'foyer_channel_slide_windows', $windows );
        }

		echo self::get_slides_list_html( get_post( $channel_id ) );
		wp_die();
	}

	/**
	 * Reorders slides over AJAX and outputs the updated slides list HTML.
	 *
	 * @since	1.0.0
	 * @since	1.0.1	Validated & sanitized the user input.
	 * @since	1.3.2	Changed method to static.
	 *
	 * @return void
	 */
    static function reorder_slides_over_ajax() {

		check_ajax_referer( 'foyer_slides_editor_ajax_nonce', 'nonce' , true );

		$channel_id = intval( $_POST['channel_id'] );
		$slide_ids = array_map( 'intval', $_POST['slide_ids'] );

		if ( empty( $channel_id ) || empty( $slide_ids ) ) {
			wp_die();
		}

		/* Check if this post exists */
		if ( is_null( get_post( $channel_id  ) ) ) {
			wp_die();
		}

        $new_slides = array();
        foreach( $slide_ids as $slide_id ) {
            $new_slides[] = $slide_id;
        }

        update_post_meta( $channel_id, Foyer_Slide::post_type_name, $new_slides );

        echo self::get_slides_list_html( get_post( $channel_id ) );
        wp_die();
    }

    /**
     * Saves the channel preview ratio over AJAX.
     *
     * @since 1.8.1
     */
    static function set_preview_ratio_over_ajax() {
        check_ajax_referer( 'foyer_slides_editor_ajax_nonce', 'nonce' , true );
        $channel_id = isset( $_POST['channel_id'] ) ? intval( $_POST['channel_id'] ) : 0;
        $ratio = isset( $_POST['ratio'] ) ? sanitize_text_field( wp_unslash( $_POST['ratio'] ) ) : '';
        if ( empty( $channel_id ) || ! in_array( $ratio, array( '9x16', '16x9' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'foyer' ) ), 400 );
        }
        if ( ! current_user_can( 'edit_post', $channel_id ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'foyer' ) ), 403 );
        }
        update_post_meta( $channel_id, 'foyer_channel_preview_ratio', $ratio );
        wp_send_json_success( array( 'ok' => true, 'ratio' => $ratio ) );
    }

    /**
     * Sets per-slide schedule window (start/end) over AJAX.
     *
     * Expects local datetime strings in WP timezone; converts to UTC timestamps.
     *
     * @since 1.7.6
     * @return void
     */
    static function set_slide_window_over_ajax() {

        check_ajax_referer( 'foyer_slides_editor_ajax_nonce', 'nonce' , true );

        $channel_id = intval( $_POST['channel_id'] );
        $slide_id   = intval( $_POST['slide_id'] );
        $start_in   = isset( $_POST['start'] ) ? wp_unslash( $_POST['start'] ) : '';
        $end_in     = isset( $_POST['end'] ) ? wp_unslash( $_POST['end'] ) : '';

        if ( empty( $channel_id ) || empty( $slide_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Required parameters are missing.', 'foyer' ) ), 400 );
        }

        // Parse using site timezone, convert to UTC
        $defaults = Foyer_Admin_Display::get_channel_scheduler_defaults();
        $fmt = isset( $defaults['picker_format'] ) ? $defaults['picker_format'] : 'Y-m-d H:i';
        $tz = wp_timezone();
        $start_ts_utc = null;
        $end_ts_utc   = null;

        if ( ! empty( $start_in ) ) {
            try {
                $dt = date_create_from_format( $fmt, $start_in, $tz );
                if ( $dt instanceof DateTime ) {
                    $dt->setTimezone( new DateTimeZone( 'UTC' ) );
                    $start_ts_utc = $dt->getTimestamp();
                }
            } catch ( Exception $e ) {}
        }
        if ( ! empty( $end_in ) ) {
            try {
                $dt = date_create_from_format( $fmt, $end_in, $tz );
                if ( $dt instanceof DateTime ) {
                    $dt->setTimezone( new DateTimeZone( 'UTC' ) );
                    $end_ts_utc = $dt->getTimestamp();
                }
            } catch ( Exception $e ) {}
        }

        // Validate order if both set
        if ( ! is_null( $start_ts_utc ) && ! is_null( $end_ts_utc ) && $end_ts_utc < $start_ts_utc ) {
            wp_send_json_error( array( 'message' => __( 'The end time must be after the start time.', 'foyer' ) ), 400 );
        }

        $windows = get_post_meta( $channel_id, 'foyer_channel_slide_windows', true );
        if ( empty( $windows ) || ! is_array( $windows ) ) { $windows = array(); }
        $windows[ $slide_id ] = array(
            'start' => $start_ts_utc,
            'end'   => $end_ts_utc,
        );
        update_post_meta( $channel_id, 'foyer_channel_slide_windows', $windows );
        // Build compact summary string for UI badge
        $summary = '';
        $fmt_day_time = 'd.m. H:i';
        $fmt_time = 'H:i';
        if ( $start_ts_utc && $end_ts_utc ) {
            $same_day = ( gmdate( 'Ymd', $start_ts_utc ) === gmdate( 'Ymd', $end_ts_utc ) );
            $sv = date_i18n( $fmt_day_time, $start_ts_utc + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS, true );
            if ( $same_day ) {
                $ev = date_i18n( $fmt_time, $end_ts_utc + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS, true );
                $summary = $sv . '–' . $ev;
            } else {
                $ev = date_i18n( $fmt_day_time, $end_ts_utc + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS, true );
                $summary = $sv . '–' . $ev;
            }
        } elseif ( $start_ts_utc ) {
            $summary = '≥ ' . date_i18n( $fmt_day_time, $start_ts_utc + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS, true );
        } elseif ( $end_ts_utc ) {
            $summary = '≤ ' . date_i18n( $fmt_day_time, $end_ts_utc + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS, true );
        }

        $status = self::determine_slide_window_status( $start_ts_utc, $end_ts_utc );

        if ( '' === $summary ) {
            $summary = '∞';
        }

        wp_send_json_success( array( 'ok' => true, 'summary' => $summary, 'status' => $status ) );
    }

	/**
	 * Saves all custom fields for a channel.
	 *
	 * Triggered when a channel is submitted from the channel admin form.
	 *
	 * @since 	1.0.0
	 * @since	1.0.1	Validated & sanitized the user input.
	 * @since	1.3.2	Changed method to static.
	 *
	 * @param 	int		$post_id	The channel id.
	 * @return void
	 */
    static function save_channel( $post_id ) {

		/*
		 * We need to verify this came from our screen and with proper authorization,
		 * because save_post can be triggered at other times.
		 */

		/* Check if our nonce is set */
		if ( ! isset( $_POST[Foyer_Channel::post_type_name.'_nonce'] ) ) {
			return $post_id;
		}

		$nonce = $_POST[Foyer_Channel::post_type_name.'_nonce'];

		/* Verify that the nonce is valid */
		if ( ! wp_verify_nonce( $nonce, Foyer_Channel::post_type_name ) ) {
			return $post_id;
		}

		/* If this is an autosave, our form has not been submitted, so we don't want to do anything */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		/* Check the user's permissions */
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

        // Save preview ratio if present (independent of slides settings)
        if ( isset( $_POST['foyer_preview_ratio'] ) ) {
            $ratio = sanitize_text_field( wp_unslash( $_POST['foyer_preview_ratio'] ) );
            if ( in_array( $ratio, array( '9x16', '16x9' ), true ) ) {
                update_post_meta( $post_id, 'foyer_channel_preview_ratio', $ratio );
            }
        }

        /* Check if slides settings are included (empty or not) in form */
        if (
            ! isset( $_POST['foyer_slides_settings_duration'] ) ||
            ! isset( $_POST['foyer_slides_settings_transition'] )
        ) {
            // Slides settings not present; still allow saving of other sidebar fields above
            return $post_id;
        }

		$foyer_slides_settings_duration = intval( $_POST['foyer_slides_settings_duration'] );
		if ( empty( $foyer_slides_settings_duration ) ) {
			$foyer_slides_settings_duration = '';
		}

		$foyer_slides_settings_transition = sanitize_title( $_POST['foyer_slides_settings_transition'] );
		if ( empty( $foyer_slides_settings_transition ) ) {
			$foyer_slides_settings_transition = '';
		}

		update_post_meta( $post_id, Foyer_Channel::post_type_name . '_slides_duration' , $foyer_slides_settings_duration );
		update_post_meta( $post_id, Foyer_Channel::post_type_name . '_slides_transition' , $foyer_slides_settings_transition );

		// Save favorite flag (checkbox)
		$fav = isset( $_POST['foyer_channel_is_favorite'] ) ? '1' : '';
		if ( ! empty( $fav ) ) {
			update_post_meta( $post_id, 'foyer_channel_is_favorite', '1' );
		} else {
			delete_post_meta( $post_id, 'foyer_channel_is_favorite' );
		}
	}

	/**
	 * Outputs the content of the slides editor meta box.
	 *
	 * @since	1.0.0
	 * @since	1.0.1	Sanitized the output.
	 * @since	1.3.2	Changed method to static.
	 *
	 * @param	WP_Post		$post	The post object of the current channel.
	 */
	static function slides_editor_meta_box( $post ) {

		wp_nonce_field( Foyer_Channel::post_type_name, Foyer_Channel::post_type_name.'_nonce' );

		ob_start();

		?>
			<div class="foyer_meta_box foyer_slides_editor" data-channel-id="<?php echo intval( $post->ID ); ?>">

			<?php /* Preview ratio radios live in the Slide preview meta box */ ?>

				<?php
					echo self::get_slides_list_html( $post );
					echo self::get_add_slide_html();
				?>

			</div>
		<?php

		$html = ob_get_clean();

		echo $html;
	}

	/**
	 * Outputs the content of the slides settings meta box.
	 *
	 * @since	1.0.0
	 * @since	1.3.2	Changed method to static.
	 *
	 * @param	WP_Post		$post	The post object of the current channel.
	 */
	static function slides_settings_meta_box( $post ) {

		wp_nonce_field( Foyer_Channel::post_type_name, Foyer_Channel::post_type_name.'_nonce' );

		ob_start();

		?>
			<table class="foyer_meta_box_form form-table foyer_channel_settings_slides_form">
				<tbody>
					<?php


						echo self::get_set_duration_html( $post );
						echo self::get_set_transition_html( $post );

					?>
				</tbody>
			</table>
		<?php

		$html = ob_get_clean();

		echo $html;
	}

	/**
	 * Gets the HTML to set the favorite flag in the slides settings meta box.
	 *
	 * @since 1.8.0
	 *
	 * @param WP_Post $post The post object of the current channel.
	 * @return string $html  The HTML to set the favorite flag in the slides settings meta box.
	 */
	static function get_set_favorite_html( $post ) {
		$checked = get_post_meta( $post->ID, 'foyer_channel_is_favorite', true ) ? 'checked="checked"' : '';

		ob_start();
		?>
			<tr>
				<th>
					<label for="foyer_channel_is_favorite">
						<?php echo esc_html__( 'Favorite', 'foyer' ); ?>
					</label>
				</th>
				<td>
					<label style="display:flex; align-items:center; gap:6px;">
						<input type="checkbox" id="foyer_channel_is_favorite" name="foyer_channel_is_favorite" value="1" <?php echo $checked; ?> />
						<span><?php echo esc_html__( 'Mark this channel as favorite', 'foyer' ); ?></span>
					</label>
				</td>
			</tr>
		<?php

		$html = ob_get_clean();
		return $html;
	}

	/**
	 * Returns HTML for a compact slide preview iframe.
	 *
	 * Generates the same mini preview used in the Channel editor: an iframe
	 * pointing to the slide permalink with the `foyer-preview=1` query arg,
	 * scaled down to thumbnail size. Useful for reusing slide previews elsewhere
	 * in admin UIs.
	 *
	 * Args:
	 * - ratio (string): '9x16' (default) or '16x9'.
	 * - show_overlay (bool): include hover overlay with title/format/background. Default true.
	 * - wrap (bool): wrap in a div with the standard classes used in the editor. Default true.
	 *
	 * @since 1.8.1
	 *
	 * @param int   $slide_id  The Slide post ID.
	 * @param array $args      Optional args.
	 * @return string          HTML markup for the preview.
	 */
    static function get_slide_preview_html( $slide_id, $args = array() ) {
		$slide_id = intval( $slide_id );
		if ( empty( $slide_id ) || is_null( get_post( $slide_id ) ) ) {
			return '';
		}

        $defaults = array(
            'ratio'              => '9x16',   // '9x16' or '16x9'
            'show_overlay'       => true,
            'wrap'               => true,
            // Optional sizing controls (all optional):
            // - width / height: pixel size of the container. If both are set, the slide is fully contained
            //   (letterboxed if aspect differs). If only one is set, the other is derived from ratio.
            // - scale: direct scaling factor of the full-size iframe (overrides width/height if set).
            'width'              => null,
            'height'             => null,
            'scale'              => null,
            // Optional extra class for the outer wrapper (when wrap is true)
            'class'              => '',
            // Optional extra inline CSS for the container div
            'container_style'    => '',
            // If true, output small inline CSS so the overlay is hidden by default and shown on hover,
            // useful when used outside the Channel editor styles.
            // If not provided, it defaults to the value of 'wrap'.
            'inline_overlay_css' => null,
        );
        $args = wp_parse_args( $args, $defaults );

        if ( is_null( $args['inline_overlay_css'] ) ) {
            $args['inline_overlay_css'] = (bool) $args['wrap'];
        }

		$ratio = in_array( $args['ratio'], array( '9x16', '16x9' ), true ) ? $args['ratio'] : '9x16';
		$is_wide = ( '16x9' === $ratio );

		// Full iframe dimensions
		$frame_w = $is_wide ? 1920 : 1080;
		$frame_h = $is_wide ? 1080 : 1920;

		// Resolve desired scale/size
		$width  = is_numeric( $args['width'] )  ? max( 1, intval( $args['width'] ) )   : null;
		$height = is_numeric( $args['height'] ) ? max( 1, intval( $args['height'] ) )  : null;
		$scale  = ( is_numeric( $args['scale'] ) && $args['scale'] > 0 ) ? floatval( $args['scale'] ) : null;

		if ( ! $scale ) {
			$scale_x = $width  ? ( $width  / $frame_w ) : null;
			$scale_y = $height ? ( $height / $frame_h ) : null;
			if ( $scale_x && $scale_y ) {
				// Contain: ensure whole slide is visible inside container
				$scale = min( $scale_x, $scale_y );
			} elseif ( $scale_x ) {
				$scale = $scale_x;
			} elseif ( $scale_y ) {
				$scale = $scale_y;
			} else {
				$scale = 0.1; // default existing behavior
			}
		}

		$scaled_w = $frame_w * $scale;
		$scaled_h = $frame_h * $scale;

		$cont_w = $width  ? $width  : intval( round( $scaled_w ) );
		$cont_h = $height ? $height : intval( round( $scaled_h ) );

		// Center the scaled iframe when container aspect differs (letterbox)
		$offset_left = max( 0, ( $cont_w - $scaled_w ) / 2 );
		$offset_top  = max( 0, ( $cont_h - $scaled_h ) / 2 );
		$left_unscaled = $scale > 0 ? ( $offset_left / $scale ) : 0;
		$top_unscaled  = $scale > 0 ? ( $offset_top  / $scale ) : 0;

		$slide_url = get_permalink( $slide_id );
		if ( empty( $slide_url ) ) { return ''; }
		$slide_url = add_query_arg( 'foyer-preview', 1, $slide_url );

		$slide_format_data = null;
		$slide_background_data = null;
		if ( $args['show_overlay'] ) {
			$slide = new Foyer_Slide( $slide_id );
			$slide_format_data = Foyer_Slides::get_slide_format_by_slug( $slide->get_format() );
			$slide_background_data = Foyer_Slides::get_slide_background_by_slug( $slide->get_background() );
		}

        ob_start();
        // Emit inline hover CSS once per request when requested.
        if ( $args['show_overlay'] && $args['inline_overlay_css'] ) {
            static $foyer_preview_overlay_css_emitted = false;
            if ( ! $foyer_preview_overlay_css_emitted ) {
                $foyer_preview_overlay_css_emitted = true;
                ?>
<style type="text/css">
.foyer-preview-card .foyer_slides_editor_slides_slide_iframe_container_overlay{display:none;}
.foyer-preview-card .foyer_slides_editor_slides_slide_iframe_container:hover .foyer_slides_editor_slides_slide_iframe_container_overlay{display:block;}
</style>
                <?php
            }
        }
		?>
            <?php if ( $args['wrap'] ) { ?>
            <div class="foyer_slides_editor_slides_slide foyer-preview-card <?php echo esc_attr( $args['class'] ); ?>" data-slide-id="<?php echo intval( $slide_id ); ?>">
			<?php } ?>
				<div class="foyer_slides_editor_slides_slide_iframe_container" style="width: <?php echo intval( $cont_w ); ?>px; height: <?php echo intval( $cont_h ); ?>px; position: relative; border:1px solid #ccc; background:#e0e0e0; overflow:hidden; <?php echo esc_attr( $args['container_style'] ); ?>">
					<?php if ( $args['show_overlay'] ) { ?>
					<div class="foyer_slides_editor_slides_slide_iframe_container_overlay" style="position:absolute; top:0; bottom:0; left:0; right:0; z-index:10; background:#e0e0e0; padding:0.5em; overflow:hidden;">
						<h4><?php echo esc_html( get_the_title( $slide_id ) ); ?></h4>
						<?php if ( ! empty( $slide_format_data ) ) { ?>
							<dl>
								<dt><?php _e( 'Format', 'foyer'); ?></dt>
								<dd><?php echo esc_html( $slide_format_data['title'] ); ?></dd>
							</dl>
						<?php } ?>
						<?php if ( ! empty( $slide_background_data ) ) { ?>
							<dl>
								<dt><?php _e( 'Background', 'foyer'); ?></dt>
								<dd><?php echo esc_html( $slide_background_data['title'] ); ?></dd>
							</dl>
						<?php } ?>
					</div>
					<?php } ?>
					<iframe src="<?php echo esc_url( $slide_url ); ?>"
							width="<?php echo intval( $frame_w ); ?>" height="<?php echo intval( $frame_h ); ?>"
							style="display:block; position:absolute; left: <?php echo intval( round( $left_unscaled ) ); ?>px; top: <?php echo intval( round( $top_unscaled ) ); ?>px; transform:scale(<?php echo esc_attr( rtrim( rtrim( sprintf('%.6F',$scale), '0'), '.' ) ); ?>); transform-origin:top left; pointer-events:none;"></iframe>
				</div>
			<?php if ( $args['wrap'] ) { ?>
			</div>
			<?php } ?>
		<?php
		return ob_get_clean();
	}
}
