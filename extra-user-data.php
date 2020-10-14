<?php
/*
Plugin Name: Extra User Data
Version: 0.13
Description: Adds extra (sortable) columns to the Users screen, showing the number of comments, the various custom post types authored, their registration date (and IP address) and last login date (and IP address), and their total number of logins. Links the comment and CPT totals directly to the matching editing page for that user's comments or posts.
Author: Keith Drakard
Author URI: https://drakard.com/
*/

class ExtraUserDataPlugin {

	private $ip_lookup_uri = 'https://tools.keycdn.com/geo?host=';

	public function __construct() {
		load_plugin_textdomain('ExtraUserData', false, plugin_dir_path(__FILE__).'/languages');

		// update our user data
		add_action('user_register', array($this, 'register_ip'));
		add_action('wp_login', array($this, 'last_login'));

		if (is_admin()) {
			// display our columns in the index
			add_filter('manage_users_columns', array($this, 'manage_users_columns'));
			add_filter('manage_users_custom_column', array($this, 'manage_users_custom_column'), 10, 3);
			add_filter('manage_users_sortable_columns', array($this, 'manage_users_custom_sortable_columns'));
			add_filter('pre_user_query', array($this, 'manage_users_orderby'));

			// our css
			add_action('admin_footer', array($this, 'resize_users_columns'));

			// not strictly necessary, but just to show you're viewing a specific user's stuff
			global $pagenow; if (
				(isset($_REQUEST['author']) AND 'edit.php' == $pagenow) OR 
				(isset($_REQUEST['user_id']) AND 'edit-comments.php' == $pagenow)
			) {
				add_action('admin_head', array($this, 'change_admin_page_title_start'));
				add_action('admin_footer', array($this, 'change_admin_page_title_end'));
			}
		}
	}



	/***********************************************************************************************************************************************/

	public function register_ip($user_id) {
		update_user_meta($user_id, 'registration_ip', $_SERVER['REMOTE_ADDR']);
	}

	public function last_login($login) {
		$user = get_user_by('login', $login);
		$count = get_user_meta($user->ID, 'login_count', true) OR 0; $count++;

		update_user_meta($user->ID, 'last_login', current_time('mysql'));
		update_user_meta($user->ID, 'last_login_ip', $_SERVER['REMOTE_ADDR']);
		update_user_meta($user->ID, 'login_count', $count);
	}


	/***********************************************************************************************************************************************/
	
	public function manage_users_columns($columns) {
		$new = array(
			'comments'			=> '<span class="dashicons dashicons-admin-comments" title="'.__('Comments', 'ExtraUserData').'"></span><span class="screen-reader-text">'.__('Comments', 'ExtraUserData').'</span>',
			'custom_posts'		=> __('All Assets', 'ExtraUserData'),
			'last_login'		=> __('Last Login', 'ExtraUserData'),
			'registered_date'	=> __('Registered', 'ExtraUserData'),
			'total_logins'		=> '<span title="'.__('Total Logins', 'ExtraUserData').'">'.__('Logins', 'ExtraUserData').'</span>',
			'user_id'			=> __('ID', 'ExtraUserData'),
		);
		if (is_array($columns)) {
			$columns = array_merge($columns, $new);
		} else {
			$columns = $new;
		}
		return $columns;
	}


	public function manage_users_custom_column($output, $column_name, $user_id) {
		switch ($column_name) {
			case 'comments':
				$counts = $this->get_author_comment_counts($user_id);
				$output = array();
				foreach ($counts as $type => $total) {
					if (0 < $total) {
						$class = $type; if ('0' == $type) $class = 'pending'; elseif ('1' == $type) $class = 'approved';
						$text = sprintf('%d %s %s', $total, $class, _n('comment', 'comments', $total, 'ExtraUserData'));
						$output[]= '<span class="post-com-count post-com-count-'.$class.'" title="'.$text.'">'
								 . '<span class="comment-count-'.$class.'" aria-hidden="true">'.$total.'</span>'
								 . '<span class="screen-reader-text">'.$text.'</span>'
								 . '</span>';
					}
				}
				$output = implode('', $output); $output = ('' != $output) ? '<a href="'.admin_url().'edit-comments.php?user_id='.$user_id.'">'.$output.'</a>' : '';
				break;

			case 'custom_posts':
				$counts = $this->get_author_post_type_counts($user_id);
				$output = array();
				if (isset($counts[$user_id]) AND is_array($counts[$user_id]))
					foreach ($counts[$user_id] as $count) {
						$link = admin_url().'edit.php?post_type='.$count['type'].'&author='.$user_id;
						$output[] = "<li><a href={$link}>{$count['label']}: <b>{$count['count']}</b></a></li>";
					}
				$output = implode("\n", $output);
				if (empty($output)) $output = '<li>'.__('None', 'ExtraUserData').'</li>';
				$output = "<ul style='margin:0;'>\n{$output}\n</ul>";
				break;
			
			case 'last_login':
				$user = get_userdata($user_id); $output = '-'; $ip = '';
				if ($user->last_login) {
					if (isset($user->last_login_ip)) {
						$ip = sprintf('<br><a href="%s%s" class="ip-highlight">IP: %s</a>',
								$this->ip_lookup_uri,
								$user->last_login_ip,
								$user->last_login_ip
							);
					}
					$output = sprintf('<span title="%s">%s<br><small>@ %s</small>%s</span>',
								sprintf(__('%d total logins', 'ExtraUserData'), $user->login_count),
								date(get_option('date_format'), strtotime($user->last_login)),
								date(get_option('time_format'), strtotime($user->last_login)),
								$ip
							);
				}
				break;

			case 'registered_date':
				$user = get_userdata($user_id); $output = '-'; $ip = '';
				if ($user->user_registered) {
					if (isset($user->registration_ip)) {
						$ip = sprintf('<br><a href="%s%s" class="ip-highlight">IP: %s</a>',
								$this->ip_lookup_uri,
								$user->registration_ip,
								$user->registration_ip
							);
					}
					$output = sprintf('<span>%s<br><small>@ %s</small>%s</span>',
								date(get_option('date_format'), strtotime($user->user_registered)),
								date(get_option('time_format'), strtotime($user->user_registered)),
								$ip
							);
				}
				break;

			case 'total_logins':
				$user = get_userdata($user_id);
				$output = ($user->login_count) ? $user->login_count : '0';
				break;

			case 'user_id':
				$output = $user_id;
				break;
		}
		return $output;
	}


	public function manage_users_custom_sortable_columns($columns) {
		$new = array(
			'comments'			=> 'comments',
			'custom_posts'		=> 'custom_posts',
			'last_login'		=> 'last_login',
			'registered_date'	=> 'registered_date',
			'total_logins'		=> 'total_logins',
			'user_id'			=> 'ID',
		);
		if (is_array($columns)) {
			$columns = array_merge($columns, $new);
		} else {
			$columns = $new;
		}
		return $columns;
	}


	public function manage_users_orderby($query) {
		$orderby = (isset($_REQUEST['orderby']) AND in_array($_REQUEST['orderby'], array('comments', 'custom_posts', 'last_login', 'registered_date', 'total_logins'))) ? $_REQUEST['orderby'] : '';
		$order = (isset($_REQUEST['order']) AND in_array($_REQUEST['order'], array('asc', 'desc'))) ? strtoupper($_REQUEST['order']) : '';
	
		global $wpdb;
		switch ($orderby) {
			case 'comments':
				$query->query_from = "FROM {$wpdb->users} LEFT OUTER JOIN {$wpdb->comments} c ON {$wpdb->users}.ID = c.user_id";
				$query->query_orderby = "GROUP BY {$wpdb->users}.ID ORDER BY COUNT(*) {$order}";
				break;

			case 'custom_posts':
				$query->query_from = "FROM {$wpdb->users} LEFT OUTER JOIN {$wpdb->posts} p ON {$wpdb->users}.ID = p.post_author";
				$query->query_where = "WHERE p.post_type NOT IN ('revision', 'nav_menu_item') AND p.post_status IN ('publish', 'pending', 'draft')";
				$query->query_orderby = "GROUP BY {$wpdb->users}.ID ORDER BY COUNT(*) {$order}";
				break;

			case 'last_login':
				$query->query_from = "FROM {$wpdb->users} LEFT OUTER JOIN {$wpdb->usermeta} um ON {$wpdb->users}.ID = um.user_id AND um.meta_key = 'last_login'";
				$query->query_orderby = "ORDER BY um.meta_value {$order}";
				break;

			case 'registered_date':
				$query->query_orderby = "ORDER BY user_registered {$order}";
				break;

			case 'total_logins':
				$query->query_from = "FROM {$wpdb->users} LEFT OUTER JOIN {$wpdb->usermeta} um ON {$wpdb->users}.ID = um.user_id AND um.meta_key = 'login_count'";
				$query->query_orderby = "ORDER BY CAST(um.meta_value AS UNSIGNED) {$order}";
				break;
		}
		
		return $query;
	}


	public function resize_users_columns() {
		echo "<style type='text/css'>
			
			/* only change column widths when on desktops */
			@media (min-width: 1200px) {
				table.users #comments			{ width:3vw; }
				table.users #user_id			{ width:4vw; }
				table.users #total_logins		{ width:5vw; }
				table.users #custom_posts,
				table.users #last_login,
				table.users #registered_date	{ width:10vw; }
			}
		
			table.users .ip-highlight {
				font-size:smaller;
				color:blue;
			}
			
			/* comment styling */
			table.users .column-comments .post-com-count-pending,
			table.users .column-comments .post-com-count-spam,
			table.users .column-comments .post-com-count-trash {
				background-color: #CA471F;
				border: 2px solid #FFFFFF;
				border-radius: 11px;
				color: #FFFFFF;
				font-size: 9px;
				height: 17px;
				left: -3px;
				line-height: 14px;
				min-width: 7px;
				padding: 0 5px;
				position: relative;
				text-align: center;
			}
			table.users .column-comments .post-com-count-spam,
			table.users .column-comments .post-com-count-trash {
				background-color: #cccccc;
				color: #333333;
			}
		</style>";
	}



	// can't use a nice filter as WP doesn't use one there, so have to do it this way instead
	public function change_admin_page_title_start() {
		ob_start(array($this, 'change_admin_page_title'));
	}
	public function change_admin_page_title($html) {
		$user_id = 0; if (isset($_REQUEST['user_id'])) $user_id = (int) $_REQUEST['user_id']; elseif (isset($_REQUEST['author'])) $user_id = (int) $_REQUEST['author'];
		$user = get_userdata($user_id);
		
		if (false !== $user) {
			$name = trim($user->first_name.' '.$user->last_name); if ('' == $name) $name = $user->user_login;
			
			global $pagenow;
			if ('edit-comments.php' == $pagenow) {
				$html = preg_replace(
					'/<h1( class="wp-heading-inline")?>\s*'.__('Comments').'\s*<\/h1>/',
					'<h1 class="wp-heading-inline">'.sprintf(__('Comments by %s'), $name).'</h1>',
					$html
				);

			} elseif ('edit.php' == $pagenow) {
				global $post_type_object;
				$html = preg_replace(
					'/<h1( class="wp-heading-inline")?>\s*'.esc_html($post_type_object->labels->name).'\s*<\/h1>/',
					'<h1 class="wp-heading-inline">'.sprintf(__('%s by %s'), esc_html($post_type_object->labels->name), $name).'</h1>',
					$html
				);
			}

		}
		return $html;
	}
	public function change_admin_page_title_end() {
		ob_end_flush();
	}




	/***********************************************************************************************************************************************/
	
	private function get_author_comment_counts($user_id = 0) {
		$counts = array(
			'1' => 0, '0' => 0, 'spam' => 0, 'trash' => 0,
		);
		global $wpdb;
		$data = $wpdb->get_results($wpdb->prepare("
			SELECT	comment_approved, COUNT(*) AS total
			FROM	{$wpdb->prefix}comments c
			WHERE	user_id = %d
			GROUP BY comment_approved
		", $user_id));

		if (is_array($data)) {
			foreach ($data as $row) {
				$counts[$row->comment_approved] = $row->total;
			}
		}

		return $counts;
	}


	// used for the Assets column
	private function get_author_post_type_counts($user_id = 0) {
		$counts = array();
		global $wpdb, $wp_post_types;
		
		$posts = $wpdb->get_results($wpdb->prepare("
			SELECT	p.post_author, p.post_type, COUNT(*) AS post_count
			FROM	{$wpdb->prefix}posts p
			WHERE	1=1
					AND p.post_type NOT IN ('revision', 'nav_menu_item')
					AND p.post_status IN ('publish', 'pending', 'draft')
					AND p.post_author = %d
			GROUP BY p.post_type
			", $user_id));
			
		foreach ($posts as $post) {
			if (isset($wp_post_types[$post->post_type])) {
				$post_type_object = $wp_post_types[$post->post_type];
				if (! empty($post_type_object->label))						$label = $post_type_object->label;
				elseif (!empty($post_type_object->labels->name))			$label = $post_type_object->labels->name;
				else														$label = ucfirst(str_replace(array('-','_'), ' ', $post->post_type));
				
				if (! isset($counts[$post->post_author]))					$counts[$post->post_author] = array();
				$counts[$post->post_author][] = array('label' => $label, 'count' => $post->post_count, 'type' => $post->post_type);
			}
		}
		return $counts;
	}

}

$ExtraUserData = new ExtraUserDataPlugin();
