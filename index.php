<?php
/*
Plugin Name: Easy Instagram
Plugin URI: 
Description: Display one or more Instagram images by user id or tag
Version: 1.0
Author: Velomedia
Author URI: http://www.velomedia.com
Licence: 
*/

require_once 'include/Instagram-PHP-API/Instagram.php';

add_action( 'admin_menu', array( 'Easy_Instagram', 'admin_menu' ) );
add_action( 'wp_enqueue_scripts', array( 'Easy_Instagram', 'init_scripts_and_styles' ) );
add_action( 'admin_init', array( 'Easy_Instagram', 'admin_init' ) );

register_activation_hook( __FILE__, array( 'Easy_Instagram', 'plugin_activation' ) );
register_deactivation_hook( __FILE__, array( 'Easy_Instagram', 'plugin_deactivation' ) );

add_action( 'easy_instagram_clear_cache_event', array( 'Easy_Instagram', 'clear_expired_cache_action' ) );

add_shortcode( 'easy-instagram', array( 'Easy_Instagram', 'shortcode' ) );

//=============================================================================

class Easy_Instagram {
	static $cache_dir = 'cache/';
	static $minimum_cache_expire_minutes = 10;
	static $default_cache_expire_minutes = 30;
	static $max_images = 10;

	static function admin_menu() {
		add_submenu_page(
			'options-general.php',
			__( 'Easy Instagram', 'Easy_Instagram' ), 
			__( 'Easy Instagram', 'Easy_Instagram' ), 
			'manage_options',
			'easy-instagram', 
			array( 'Easy_Instagram', 'admin_page' )
		);
	}

	//=========================================================================

	static function init_scripts_and_styles() {
		if ( ! is_admin() ) {
			wp_register_style( 'Easy_Instagram', plugins_url( 'css/style.css', __FILE__ ) );
			wp_enqueue_style( 'Easy_Instagram' );
		}
	}

	//=========================================================================

	static function admin_init() {
		wp_register_style( 'Easy_Instagram_Admin', plugins_url( 'css/admin.css', __FILE__ ) );
		wp_enqueue_style( 'Easy_Instagram_Admin' );	
	}

	//=========================================================================

	static function set_instagram_settings( $client_id, $client_secret, $redirect_uri ) {
		update_option( 'easy_instagram_client_id', $client_id );
		update_option( 'easy_instagram_client_secret', $client_secret );
		update_option( 'easy_instagram_redirect_uri', $redirect_uri );
	}

	//=========================================================================

	static function get_instagram_settings() {
		$client_id = get_option( 'easy_instagram_client_id' );
		$client_secret = get_option( 'easy_instagram_client_secret' );
		$redirect_uri = get_option( 'easy_instagram_redirect_uri' );
		return array( $client_id, $client_secret, $redirect_uri );
	}

	//=========================================================================

	static function get_instagram_config() {
		list( $client_id, $client_secret, $redirect_uri ) = self::get_instagram_settings();

		return array(
			'client_id' 	=> $client_id,
			'client_secret' => $client_secret,
			'grant_type' 	=> 'authorization_code',
			'redirect_uri' 	=> $redirect_uri
		);
	}	

	//=========================================================================

	static function admin_page() {
		if ( isset( $_POST['ei_general_settings'] ) &&
				check_admin_referer( 'ei_general_settings_nonce', 'ei_general_settings_nonce' ) ) {

			$errors = array();

			$instagram_client_id = isset( $_POST['ei_client_id'] ) 
				? trim( $_POST['ei_client_id'] ) 
				: '';

			$instagram_client_secret = isset( $_POST['ei_client_secret'] ) 
				? trim( $_POST['ei_client_secret'] ) 
				: '';

			$instagram_redirect_uri = isset( $_POST['ei_redirect_uri'] ) 
				? trim( $_POST['ei_redirect_uri'] ) 
				: '';

			if ( empty( $instagram_client_id ) ) {
				$errors['client_id'] = __( 'Please enter your Instagram client id', 'Easy_Instagram' );
			}
			
			if ( empty( $instagram_client_secret ) ) {
				$errors['client_secret'] = __( 'Please enter your Instagram client secret', 'Easy_Instagram' );
			}

			if ( empty( $instagram_redirect_uri ) ) {
				$errors['redirect_uri'] = __( 'Please enter your Instagram redirect URI', 'Easy_Instagram' );
			}

			if ( empty( $errors ) ) {
				self::set_instagram_settings( $instagram_client_id, $instagram_client_secret, $instagram_redirect_uri );
			}

			$cache_expire_time = isset( $_POST['ei_cache_expire_time'] ) 
				? (int) $_POST['ei_cache_expire_time'] 
				: 0;

			if ( $cache_expire_time < self::$minimum_cache_expire_minutes ) {
				$cache_expire_time = self::$minimum_cache_expire_minutes;
			}

			self::set_cache_refresh_minutes( $cache_expire_time );		
		}
		else {
			list( $instagram_client_id, $instagram_client_secret, $instagram_redirect_uri ) 
				= self::get_instagram_settings();
		}

		if ( isset( $_POST['instagram-logout'] )
				&& check_admin_referer( 'ei_user_logout_nonce', 'ei_user_logout_nonce' ) ) {
			self::set_access_token( '' );
			update_option( 'ei_access_token', '' );
		}


		$config = self::get_instagram_config();
		$instagram = new Instagram( $config );
		$access_token = self::get_access_token();
		$cache_dir = self::get_cache_dir();
		$cache_expire_time = self::get_cache_refresh_minutes();

		if ( empty ( $access_token ) ) {
			if ( isset( $_GET['code'] ) ) {
				$access_token = $instagram->getAccessToken();
				if ( !empty( $access_token ) ) {
					self::set_access_token( $access_token );
				}

				$instagram_user = $instagram->getCurrentUser();
				if ( !empty( $instagram_user ) ) {
					self::set_instagram_user_data( $instagram_user->username, $instagram_user->id );
				}
			}
		}
?>
	<div id="icon-options-general" class="icon32"></div>
	<h2><?php _e( 'Easy Instagram', 'Easy_Instagram' ) ?></h2>

	<form method='POST' action='' class='easy-instagram-settings-form'>
		<table class='easy-instagram-settings'>
			<?php if ( !is_writable( $cache_dir ) ): ?>
				<tr class='warning'>
					<td colspan='2'>
						<?php printf( __( 'The directory %s is not writable !', 'Easy_Instagram' ), $cache_dir ); ?>
					</td>
				</tr>
			<?php endif; ?>

			<tr>
				<td colspan='2'><h3><?php _e( 'General Settings', 'Easy_Instagram' ); ?></h3></td>
			</tr>
			<tr>
				<td class='labels'>
					<label for='ei-client-id'><?php _e( 'Application Client ID', 'Easy_Instagram' ); ?></label>
				</td>
				<td>
					<input type='text' name='ei_client_id' id='ei-client-id' value='<?php echo esc_html( $instagram_client_id ); ?>' />
					<br />
					<?php if ( isset( $errors['client_id'] ) ): ?>
						<div class='form-error'><?php echo $errors['client_id']; ?></div>
					<?php endif; ?>

					<span class='info'><?php _e( 'This is the ID of your Instagram application', 'Easy_Instagram' ); ?></span>
				</td>
			</tr>

			<tr>
				<td class='labels'>
					<label for='ei-client-secret'><?php _e( 'Application Client Secret', 'Easy_Instagram' ); ?></label>
				</td>
				<td>
					<input type='text' name='ei_client_secret' id='ei-client-secret' value='<?php echo esc_html( $instagram_client_secret ); ?>' />
					<br />
					<?php if ( isset( $errors['client_secret'] ) ): ?>
						<div class='form-error'><?php echo $errors['client_secret']; ?></div>
					<?php endif; ?>

					<span class='info'><?php _e( 'This is your Instagram application secret', 'Easy_Instagram' ); ?></span>
				</td>
			</tr>

			<tr>
				<td class='labels'>
					<label for='ei-redirect-uri'><?php _e( 'Application Redirect URI', 'Easy_Instagram' ); ?></label>
				</td>
				<td>
					<input type='text' name='ei_redirect_uri' id='ei-redirect-uri' value='<?php echo esc_html( $instagram_redirect_uri ); ?>' />
					<br />
					<?php if ( isset( $errors['redirect_uri'] ) ): ?>
						<div class='form-error'><?php echo $errors['redirect_uri']; ?></div>
					<?php endif; ?>
					<span class='info'><?php _e( 'This is your Instagram application redirect URI', 'Easy_Instagram' ); ?></span>
				</td>
			</tr>

			<tr>
				<td class='labels'>
					<label for='ei-cache-expire-time'><?php _e( 'Cache Expire Time (minutes)', 'Easy_Instagram' ); ?></label>
				</td>	
				<td>
					<input type='text' name='ei_cache_expire_time' id='ei-cache-expire-time' value='<?php echo esc_html( $cache_expire_time ); ?>' />
					<br />
					<span class='info'>
						<?php printf( __( 'Minimum expire time: %d minute.', 
											'Easy_Instagram' ), 
										self::$minimum_cache_expire_minutes ); ?>
					</span>
				</td>
			</tr>

			<tr>
				<td>
					<input type='hidden' name='ei_general_settings' value='1' />
					<?php wp_nonce_field( 'ei_general_settings_nonce', 'ei_general_settings_nonce' ); ?>
				</td>
				<td>
					<input type='submit' value='<?php _e( "Save Settings" , "Easy_Instagram" ) ?>' name='submit' />
				</td>
			</tr>

		</table>
	</form>

	<form method='POST' action='' class='easy-instagram-settings-form'>
		<table class='easy-instagram-settings'>
		<?php if ( empty( $access_token ) ) : ?>
			<tr>
				<td colspan='2'><h3><?php _e( 'Instagram Account', 'Easy_Instagram' ); ?></h3></td>
			</tr>

			<tr>
				<td>
					<?php if ( !empty( $instagram_client_id ) 
						&& !empty( $instagram_client_secret ) 
						&& ! empty( $instagram_redirect_uri ) ): ?>
						<?php $authorization_url = $instagram->getAuthorizationUrl(); ?>
						<a href="<?php echo $authorization_url;?>"><?php _e( 'Instagram Login' );?></a>
					<?php else: ?>
						<?php _e( 'Please configure the General Settings first', 'Easy_Instagram' ); ?>
					<?php endif; ?>
				</td>	
				<td>
				</td>			
			</tr>
		<?php else: ?>
			<?php list( $username, $user_id ) = self::get_instagram_user_data(); ?>
				<tr>
					<td colspan='2'><h3><?php _e( 'Instagram Account', 'Easy_Instagram' ); ?></h3></td>
				</tr>
				<tr>
					<td class='labels'>
						<label><?php _e( 'Instagram Username', 'Easy_Instagram' ); ?></label>
					</td>
					<td>
						<?php echo $username; ?>	
					</td>
				</tr>

				<tr>
					<td class='labels'>
						<label><?php _e( 'Instagram User ID', 'Easy_Instagram' ); ?></label>
					</td>
					<td>
						<?php echo $user_id; ?>	
					</td>
				</tr>

				<tr>
					<td>
						<?php wp_nonce_field( 'ei_user_logout_nonce', 'ei_user_logout_nonce' ); ?>
					</td>
					<td>
						<input type='submit' name='instagram-logout' value="<?php _e( 'Instagram Logout' );?>" />
					</td>
				</tr>				
		<?php endif; ?>
		</table>
	</form>		

<?php		

	}
	//=========================================================================

	static function set_instagram_user_data( $username, $id ) {
		update_option( 'easy_instagram_username', $username );
		update_option( 'easy_instagram_user_id', $id );
	}

	//=========================================================================

	static function get_instagram_user_data() {
		$username = get_option( 'easy_instagram_username' );
		$user_id = get_option( 'easy_instagram_user_id' );
		return array( $username, $user_id );
	}

	//=========================================================================

	static function set_access_token( $access_token ) {
		update_option( 'easy_instagram_access_token', $access_token );
	}

	//=========================================================================

	static function get_access_token() {
		return get_option( 'easy_instagram_access_token' );
	}

	//=========================================================================

	static function get_live_user_data( $instagram, $user_id, $limit = 1 ) {
		if ( $limit > self::$max_images ) {
			$limit = $max_images;
		}

		$live_data = $instagram->getUserRecent( $user_id );

		$recent = json_decode( $live_data );
		if ( empty( $recent ) ) {
			$live_data = NULL;
		}				
		else {
			$live_data = array_slice( $recent->data, 0, $limit );
		}
		return $live_data;
	}

	//=========================================================================

	static function get_live_tag_data( $instagram, $tag, $limit = 1 ) {

		if ( $limit > self::$max_images ) {
			$limit = $max_images;
		}

		$live_data = $instagram->getRecentTags( $tag );

		$recent = json_decode( $live_data );
		if ( empty( $recent ) ) {
			$live_data = NULL;
		}				
		else {
			$live_data = array_slice( $recent->data, 0, $limit );
		}
		return $live_data;
	}

	//=========================================================================
	
	static function shortcode( $attributes ) {
		extract(
			shortcode_atts( 
				array(
					'tag' 		=> '',
					'user_id' 	=> '',
					'limit'     => 1
				), 
				$attributes
			) 
		);

		return self::generate_content( $tag, $user_id, $limit );
	}

	//=========================================================================
	
	static function generate_content( $tag, $user_id, $limit ) {
		if ( empty( $tag ) && empty( $user_id ) ) {
			return '';
		}

		$access_token = self::get_access_token();
		if ( empty( $access_token ) ) {
			return '';
		}

		$out = '';

		$config = self::get_instagram_config();
		$instagram = new Instagram( $config );
		$instagram->setAccessToken( $access_token );


		if ( !empty( $user_id ) ) {
			list( $data, $expired ) = self::get_cached_data_for_id_or_tag( $user_id, $limit, 'id' );
			
			if ( $expired ) {
				$live_data = self::get_live_user_data( $instagram, $user_id, $limit );
				if ( !empty( $live_data ) ) {
					self::clear_cache( $user_id, 'id' );
				}
				else {
					$live_data = NULL;
				}
			}
			elseif ( NULL == $data ) {
				$live_data = self::get_live_user_data( $instagram, $user_id, $limit );
			}
			
			$cache_index = 'id' . $user_id;			
		}
		else {
			if ( !empty( $tag ) ) {
				list( $data, $expired ) = self::get_cached_data_for_id_or_tag( $tag, $limit, 'tag' );

				if ( $expired ) {
					$live_data = self::get_live_tag_data( $instagram, $tag, $limit );
					if ( !empty( $live_data ) ) {
						self::clear_cache( $user_id, 'tag' );
					}
					else {
						$live_data = NULL;
					}
				}
				elseif ( NULL == $data ) {
					$live_data = self::get_live_tag_data( $instagram, $tag, $limit );
				}

				$cache_index = 'tag' . $tag;
			}
		}

		if ( isset( $live_data ) && !empty( $live_data ) ) {
			$hash = md5( $cache_index );
			$timestamp = time();
			$cache_data = array( 'cache_timestamp' => $timestamp );
			$cache_data['data'] = array();

			foreach ( $live_data as $elem ) {
				if ( isset( $elem->caption ) ) {
					$caption_text = isset( $elem->caption->text ) ? $elem->caption->text : '';
					$caption_from = ( isset( $elem->caption->from ) && isset( $elem->caption->from->full_name ) ) 
						? $elem->caption->from->full_name : '';
					$caption_created_time = isset( $elem->caption->created_time ) ? $elem->caption->created_time : time();
				}
				else {
					$caption_text = '';
					$caption_from = '';
					$caption_created_time = time();
				}
				
				
				$cached_elem = array(
					'link' 					=> isset( $elem->link ) ? $elem->link : '#',
					'caption_text' 			=> $caption_text,
					'caption_from' 			=> $caption_from,
					'created_time' 			=> $elem->created_time,
					'caption_created_time' 	=> $caption_created_time
				);

				$images = $elem->images;
				if ( isset( $images->low_resolution ) ) {
					$cached_elem['low_resolution'] = array(
						'width' 	=> $images->low_resolution->width,
						'height' 	=> $images->low_resolution->height
					);

					$local_url = self::save_remote_image( 
						$images->low_resolution->url, 
						'low_resolution'
					);

					if ( NULL == $local_url ) {
						$cached_elem['low_resolution']['url'] = $images->low_resolution->url;
					}
					else {
						$cached_elem['low_resolution']['url'] = $local_url;
					}
				}

				if ( isset( $images->thumbnail ) ) {
					$cached_elem['thumbnail'] = array(
						'width' 	=> $images->thumbnail->width,
						'height' 	=> $images->thumbnail->height
					);
						
					$local_url = self::save_remote_image( 
						$images->thumbnail->url, 
						'thumbnail'
					);

					if ( NULL == $local_url ) {
						$cached_elem['thumbnail']['url'] = $images->thumbnail->url;
					}
					else {
						$cached_elem['thumbnail']['url'] = $local_url;
					}
				}

				if ( isset( $images->standard_resolution ) ) {
					$cached_elem['standard_resolution'] = array(
						'width' 	=> $images->standard_resolution->width,
						'height' 	=> $images->standard_resolution->height
					);
						
					$local_url = self::save_remote_image( 
						$images->standard_resolution->url, 
						'standard_resolution'
					);

					if ( NULL == $local_url ) {
						$cached_elem['standard_resolution']['url'] = $images->standard_resolution->url;
					}
					else {
						$cached_elem['standard_resolution']['url'] = $local_url;
					}
				}

				$cache_data['data'][] = $cached_elem;
			}

			self::cache_data( $cache_index, $cache_data );

			$instagram_elements = $cache_data['data'];
		}
		else {
			if ( ! empty( $data ) ) {
				$instagram_elements = $data['data'];
			}
		}

		if ( isset( $instagram_elements ) ) {
			$crt = 0;
			foreach ( $instagram_elements as $elem ) {
				$image_url = $elem['thumbnail']['url'];
				$width = $elem['thumbnail']['width'];
				$height = $elem['thumbnail']['height'];

				$out .= '<div class="easy-instagram-thumbnail-wrapper">';
				$out .= '<img src="' . $image_url . '" alt="" style="width:' 
					. $width. 'px; height: ' . $height . 'px;" class="easy-instagram-thumbnail" />';

				$out .= '<div class="easy-instagram-thumbnail-author">by ' . $elem['caption_from'] . '</div>';
				$out .= '<div class="easy-instagram-thumbnail-caption">' . $elem['caption_text'] . '</div>';

				$elem_time = ( $elem['caption_created_time'] > $elem['created_time'] )
							? $elem['caption_created_time'] : $elem['created_time'];
				$out .= '<div class="easy-instagram-thumbnail-time">' 
					. self::relative_time( $elem_time ) 
					. __( ' using Instagram', 'Easy_Instagram' )
					. '</div>';

				$out .= '</div>';

				$crt++;	
				if ( $crt >= $limit ) {	
					break;
				}
			}		
		}
		return $out;	
	}

	//=========================================================================

	static public function get_cache_dir() {
		return plugin_dir_path( __FILE__ ) . Easy_Instagram::$cache_dir;
	}

	//=========================================================================

	static function cache_data( $id_or_tag, $data ) {
		$timestamp = time();
		$hash = md5( $id_or_tag );

		$path = self::get_cache_dir() . $hash . '.cache';

		$handle = fopen( $path, 'w' );
		if ( FALSE === $handle ) {
			return FALSE;
		}

		$serialized = serialize( $data );

		$would_block = TRUE;
		if ( flock( $handle, LOCK_EX, $would_block ) ) {
			fwrite( $handle, $serialized );
			fflush( $handle ); 
			flock( $handle, LOCK_UN ); // release the lock
		}
		else {
			error_log( 'Couldn\'t get the lock in cache_data.' );
		}

		fclose( $handle );
		return TRUE;	
	}

	//=========================================================================
	// Returns the cached data and a flag telling if the data expired
	static function get_cached_data_for_id_or_tag( $id_or_tag, $limit, $type = 'id' ) {
		$now = time();
		$hash = md5( $type . $id_or_tag );

		$path = self::get_cache_dir() . $hash . '.cache';
		if ( !file_exists( $path ) ) {
			return array( NULL, FALSE );
		}

		$handle = fopen( $path, 'r' );

		if ( flock( $handle, LOCK_SH ) ) {	
			$data = fgets( $handle );
			flock( $handle, LOCK_UN ); // release the lock
		}
		else {
			error_log( 'Couldn\'t get the lock in get_cached_data_for_id_or_tag.' );
		}

		if ( !empty( $data ) ) {
			$data = unserialize( $data );
		}

		fclose( $handle );

		// If limit is greater than the cached data size, force clear cache
		if ( $limit > count( $data['data'] ) ) {
			return array( $data, TRUE );
		}

		$cache_minutes = self::get_cache_refresh_minutes();

		$delta = ( $now - $data['cache_timestamp'] ) / 60;
		if ( $delta > $cache_minutes ) {
			return array( $data, TRUE );
		}
		else {
			return array( $data, FALSE );
		}
	}

	//=========================================================================

	static function clear_cache( $id_or_tag, $type ) {
		$hash = md5( $type . $id_or_tag );

		$path = self::get_cache_dir() . $hash . '.cache';
		if ( file_exists( $path ) ) {
			$handle = fopen( $path, 'r' );

			if ( flock( $handle, LOCK_EX ) ) {
				$data = fread( $handle, filesize( $path ) );
			}

			if ( !empty( $data ) ) {
				$data = unserialize( $data );
			}

			fclose( $handle );

			unlink( $path );	
	
			if ( !empty( $data ) ) {
				foreach ( $data as $elem ) {
					//Delete images
					if ( isset( $elem['thumbnail']['url'] ) && file_exists( $elem['thumbnail']['url'] ) ) {
						unlink( $elem['thumbnail']['url'] );
					}

					if ( isset( $elem['standard_resolution']['url'] ) && file_exists( $elem['standard_resolution']['url'] ) ) {
						unlink( $elem['standard_resolution']['url'] );
					}

					if ( isset( $elem['low_resolution']['url'] ) && file_exists( $elem['low_resolution']['url'] ) ) {
						unlink( $elem['low_resolution']['url'] );
					}
				}
			}
		}
	}

	//=========================================================================	

	static function save_remote_image( $remote_image_url, $id ) {
		$filename = '';
		if ( preg_match( '/([^\/\.\?\&]+)\.([^\.\?\/]+)(\?[^\.\/]*)?$/', $remote_image_url, $matches ) ) {
			$filename .= $matches[1] . '_' . $id . '.' . $matches[2];
		}
		else {
			return NULL;
		}

		$path = self::get_cache_dir() . $filename;

		$content = file_get_contents( $remote_image_url );
		file_put_contents( $path, $content );

		return plugins_url( self::$cache_dir . $filename, __FILE__ );
	}

	//=========================================================================	

	static function get_cache_refresh_minutes() {
		return get_option( 'easy_instagram_cache_expire_time', self::$default_cache_expire_minutes );
	}

	//=========================================================================		

	static function set_cache_refresh_minutes( $minutes = 0 ) {
		if ( 0 == $minutes ) {
			$minutes = self::$default_cache_expire_minutes;
		}
		update_option( 'easy_instagram_cache_expire_time', (int) $minutes );
	}

	//=========================================================================

	static function relative_time( $timestamp ) {
		$difference = time() - $timestamp;
		$periods = array( "sec", "min", "hour", "day", "week", "month", "years", "decade" );
		$lengths = array( "60", "60", "24", "7", "4.35", "12", "10" );

		if ($difference > 0) { // this was in the past
			$ending = "ago";
		} else { // this was in the future
			$difference = -$difference;
			$ending = "to go";
		}
		for( $j = 0; $difference >= $lengths[$j]; $j++ ) {
			$difference /= $lengths[$j];
		}
		$difference = round( $difference );
		if($difference != 1) {
			$periods[$j] .= "s";
		}
		
		$text = "$difference $periods[$j] $ending";
		return $text;
	}		

	//=====================================================================

	static function plugin_activation() {
		wp_schedule_event( 
			current_time( 'timestamp' ), 
			'daily', 
			'easy_instagram_clear_cache_event' 
		);	
	}
	
	//=====================================================================
	
	static function clear_expired_cache_action() {
		$valid_files = array();
		$cache_dir = self::get_cache_dir();
		$files = scandir( $cache_dir );
		if ( !empty( $files ) ) {
			foreach ( $files as $file ) {
				if ( preg_match( '/\.cache$/', $file ) ) {
					$ret = self::remove_cache_file( $file );
					if ( ! empty( $ret ) ) {
						$valid_files = array_merge( $valid_files, $ret );
					}
				}
			}
		}

		if ( ! empty( $valid_files ) ) {
			// Remove all the files from the cache folder not in the valid files array
			$files = scandir( $cache_dir );
			if ( !empty( $files ) ) {
				foreach ( $files as $file ) {
					if ( ( '.' != $file ) && ( '..' != $file ) ) {
						if ( ! in_array( $file, $valid_files ) ) {
							unlink( $cache_dir . '/' . $file );
						}
					}
				}
			}
		}

	}

	//=====================================================================

	static function remove_cache_file( $filename ) {
		$path = self::get_cache_dir() . $filename;
		
		$handle = fopen( $path, 'r' );

		if ( flock( $handle, LOCK_EX ) ) {
			$data = fread( $handle, filesize( $path ) );
		}

		if ( !empty( $data ) ) {
			$data = unserialize( $data );
		}

		fclose( $handle );

		$now = time();
		$delta = ( $now - $data['cache_timestamp'] ) / 60;

		$valid_files = array();
		if ( $delta > 24 * 60 ) {
			if ( !empty( $data['data'] ) ) {
				foreach ( $data['data'] as $elem ) {
					//Delete images
					if ( isset( $elem['thumbnail']['url'] ) && file_exists( $elem['thumbnail']['url'] ) ) {
						unlink( $elem['thumbnail']['url'] );
					}

					if ( isset( $elem['standard_resolution']['url'] ) && file_exists( $elem['standard_resolution']['url'] ) ) {
						unlink( $elem['standard_resolution']['url'] );
					}

					if ( isset( $elem['low_resolution']['url'] ) && file_exists( $elem['low_resolution']['url'] ) ) {
						unlink( $elem['low_resolution']['url'] );
					}
				}
			}
			
			unlink( $path );
		}
		else {
			if ( !empty( $data['data'] ) ) {
				foreach ( $data['data'] as $elem ) {
					if ( isset( $elem['thumbnail']['url'] ) && file_exists( $elem['thumbnail']['url'] ) ) {
						$valid_files[] = $elem['thumbnail']['url'];
					}

					if ( isset( $elem['standard_resolution']['url'] ) && file_exists( $elem['standard_resolution']['url'] ) ) {
						$valid_files[] = $elem['standard_resolution']['url'];	
					}

					if ( isset( $elem['low_resolution']['url'] ) && file_exists( $elem['low_resolution']['url'] ) ) {
						$valid_files[] = $elem['low_resolution']['url'];
					}					
				}
				$valid_files[] = $path;
			}

		}
		
		return $valid_files;
	}

	//=====================================================================
	
	static function plugin_deactivation() {
		wp_clear_scheduled_hook( 'easy_instagram_clear_cache_event' );
	}
}

/*
 * Easy Instagram Widget
 */

class Easy_Instagram_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'easy_instagram_widget_base', 
			'Easy Instagram', 
			array( 
				'description' => 'Display one or more images from Instagram based on a tag or Instagram user id', 
				'class' => 'easy-instagram-widget'
			)
		);
	}

	//==========================================================================

 	public function form( $instance ) {
		if ( isset( $instance['type'] ) ) {
			$type = $instance['type'];
		}
		else {
			$type = 'tag';
		}
		
		if ( isset( $instance['value'] ) ) {
			$value = $instance['value'];
		}
		else {
			$value = '';
		}

		if ( isset( $instance['limit'] ) ) {
			$limit = $instance['limit'];
		}
		else {
			$limit = 1;
		}

		if ( $limit > Easy_Instagram::$max_images ) {
			$limit = Easy_Instagram::$max_images;
		}
?>
		<p>
		<label for="<?php echo $this->get_field_id( 'type' ); ?>"><?php _e( 'Type:' ); ?></label> 
		<select class="widefat" id="<?php echo $this->get_field_id( 'type' ); ?>" name="<?php echo $this->get_field_name( 'type' ); ?>">
			<?php $selected = ( 'tag' == $type ) ? 'selected="selected"' : ''; ?>
			<option value="tag" <?php echo $selected;?>><?php _e( 'Tag' ); ?></option>
			
			<?php $selected = ( 'user_id' == $type ) ? 'selected="selected"' : ''; ?>
			<option value="user_id" <?php echo $selected;?>><?php _e( 'User ID' ); ?></option>
		</select>
		</p>
		
		<p>
		<label for="<?php echo $this->get_field_id( 'value' ); ?>"><?php _e( 'Value:' ); ?></label> 
		<input type='text' class="widefat" id="<?php echo $this->get_field_id( 'value' ); ?>" name="<?php echo $this->get_field_name( 'value' ); ?>" value="<?php _e( $value ); ?> " />
		</p>
		
		<p>
		<label for="<?php echo $this->get_field_id( 'limit' ); ?>"><?php _e( 'Images:' ); ?></label> 
		<select class="widefat" id="<?php echo $this->get_field_id( 'limit' ); ?>" name="<?php echo $this->get_field_name( 'limit' ); ?>">
		<?php for ( $i=1; $i<= Easy_instagram::$max_images; $i++ ): ?>
		
		<?php printf(
			'<option value="%s"%s>%s</option>',
        		$i,
        		selected( $limit, $i, false ),
        		$i ); 
		?> 
		
		<?php endfor; ?>
		</select>
		</p>

<?php
		
	}

	//==========================================================================

	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['type']	= strip_tags( $new_instance['type'] );
		$instance['value']	= trim( strip_tags( $new_instance['value'] ) );
		$instance['limit']	= strip_tags( $new_instance['limit'] );		

		return $instance;
	}

	//==========================================================================
	
	public function widget( $args, $instance ) {
		extract( $args );

		echo $before_widget;
		
		$tag = '';
		$user_id = '';
		$limit = 1;
		
		if ( 'tag' == $instance['type'] ) {
			$tag = trim( $instance['value'] );
			$user_id = '';
		}
		else {
			$tag = '';
			$user_id = $instance['value'];
		}
		
		if ( isset( $instance['limit'] ) ) {
			$limit = (int) $instance['limit'];
			if ( $limit > Easy_Instagram::$max_images ) {
				$limit = Easy_Instagram::$max_images;
			}
		}
		
		$content = Easy_Instagram::generate_content( $tag, $user_id, $limit );
		
		echo $content;
		
		echo $after_widget;	
	}

	//==========================================================================
}


add_action( 'widgets_init', create_function( '', 'register_widget( "Easy_Instagram_Widget" );' ) );