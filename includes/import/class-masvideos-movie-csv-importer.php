<?php
/**
 * MasVideos Movie CSV importer
 *
 * @package  MasVideos/Import
 * @version  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Include dependencies.
 */
if ( ! class_exists( 'MasVideos_Movie_Importer', false ) ) {
	include_once dirname( __FILE__ ) . '/abstract-masvideos-movie-importer.php';
}

/**
 * MasVideos_Movie_CSV_Importer Class.
 */
class MasVideos_Movie_CSV_Importer extends MasVideos_Movie_Importer {

	/**
	 * Tracks current row being parsed.
	 *
	 * @var integer
	 */
	protected $parsing_raw_data_index = 0;

	/**
	 * Initialize importer.
	 *
	 * @param string $file   File to read.
	 * @param array  $params Arguments for the parser.
	 */
	public function __construct( $file, $params = array() ) {
		$default_args = array(
			'start_pos'        => 0, // File pointer start.
			'end_pos'          => -1, // File pointer end.
			'lines'            => -1, // Max lines to read.
			'mapping'          => array(), // Column mapping. csv_heading => schema_heading.
			'parse'            => false, // Whether to sanitize and format data.
			'update_existing'  => false, // Whether to update existing items.
			'delimiter'        => ',', // CSV delimiter.
			'prevent_timeouts' => true, // Check memory and time usage and abort if reaching limit.
			'enclosure'        => '"', // The character used to wrap text in the CSV.
			'escape'           => "\0", // PHP uses '\' as the default escape character. This is not RFC-4180 compliant. This disables the escape character.
		);

		$this->params = wp_parse_args( $params, $default_args );
		$this->file   = $file;

		if ( isset( $this->params['mapping']['from'], $this->params['mapping']['to'] ) ) {
			$this->params['mapping'] = array_combine( $this->params['mapping']['from'], $this->params['mapping']['to'] );
		}

		$this->read_file();
	}

	/**
	 * Read file.
	 */
	protected function read_file() {
		if ( ! MasVideos_Movie_CSV_Importer_Controller::is_file_valid_csv( $this->file ) ) {
			wp_die( __( 'Invalid file type. The importer supports CSV and TXT file formats.', 'masvideos' ) );
		}

		$handle = fopen( $this->file, 'r' ); // @codingStandardsIgnoreLine.

		if ( false !== $handle ) {
			$this->raw_keys = version_compare( PHP_VERSION, '5.3', '>=' ) ? array_map( 'trim', fgetcsv( $handle, 0, $this->params['delimiter'], $this->params['enclosure'], $this->params['escape'] ) ) : array_map( 'trim', fgetcsv( $handle, 0, $this->params['delimiter'], $this->params['enclosure'] ) ); // @codingStandardsIgnoreLine

			// Remove BOM signature from the first item.
			if ( isset( $this->raw_keys[0] ) ) {
				$this->raw_keys[0] = $this->remove_utf8_bom( $this->raw_keys[0] );
			}

			if ( 0 !== $this->params['start_pos'] ) {
				fseek( $handle, (int) $this->params['start_pos'] );
			}

			while ( 1 ) {
				$row = version_compare( PHP_VERSION, '5.3', '>=' ) ? fgetcsv( $handle, 0, $this->params['delimiter'], $this->params['enclosure'], $this->params['escape'] ) : fgetcsv( $handle, 0, $this->params['delimiter'], $this->params['enclosure'] ); // @codingStandardsIgnoreLine

				if ( false !== $row ) {
					$this->raw_data[]                                 = $row;
					$this->file_positions[ count( $this->raw_data ) ] = ftell( $handle );

					if ( ( $this->params['end_pos'] > 0 && ftell( $handle ) >= $this->params['end_pos'] ) || 0 === --$this->params['lines'] ) {
						break;
					}
				} else {
					break;
				}
			}

			$this->file_position = ftell( $handle );
		}

		if ( ! empty( $this->params['mapping'] ) ) {
			$this->set_mapped_keys();
		}

		if ( $this->params['parse'] ) {
			$this->set_parsed_data();
		}
	}

	/**
	 * Remove UTF-8 BOM signature.
	 *
	 * @param  string $string String to handle.
	 * @return string
	 */
	protected function remove_utf8_bom( $string ) {
		if ( 'efbbbf' === substr( bin2hex( $string ), 0, 6 ) ) {
			$string = substr( $string, 3 );
		}

		return $string;
	}

	/**
	 * Set file mapped keys.
	 */
	protected function set_mapped_keys() {
		$mapping = $this->params['mapping'];

		foreach ( $this->raw_keys as $key ) {
			$this->mapped_keys[] = isset( $mapping[ $key ] ) ? $mapping[ $key ] : $key;
		}
	}

	/**
	 * Parse relative field and return movie ID.
	 *
	 * Handles `id:xx` and SKUs.
	 *
	 * If mapping to an id: and the movie ID does not exist, this link is not
	 * valid.
	 *
	 * If mapping to a SKU and the movie ID does not exist, a temporary object
	 * will be created so it can be updated later.
	 *
	 * @param  string $value Field value.
	 * @return int|string
	 */
	public function parse_relative_field( $value ) {
		global $wpdb;

		if ( empty( $value ) ) {
			return '';
		}

		// IDs are prefixed with id:.
		if ( preg_match( '/^id:(\d+)$/', $value, $matches ) ) {
			$id = intval( $matches[1] );

			// If original_id is found, use that instead of the given ID since a new placeholder must have been created already.
			$original_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_original_id' AND meta_value = %s;", $id ) ); // WPCS: db call ok, cache ok.

			if ( $original_id ) {
				return absint( $original_id );
			}

			// See if the given ID maps to a valid movie allready.
			$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( 'movie' ) AND ID = %d;", $id ) ); // WPCS: db call ok, cache ok.

			if ( $existing_id ) {
				return absint( $existing_id );
			}

			// If we're not updating existing posts, we may need a placeholder movie to map to.
			if ( ! $this->params['update_existing'] ) {
				$movie = new MasVideos_Movie();
				$movie->set_name( 'Import placeholder for ' . $id );
				$movie->set_status( 'importing' );
				$movie->add_meta_data( '_original_id', $id, true );
				$id = $movie->save();
			}

			return $id;
		}

		try {
			$movie = new MasVideos_Movie();
			$movie->set_name( 'Import placeholder for ' . $value );
			$movie->set_status( 'importing' );
			$id = $movie->save();

			if ( $id && ! is_wp_error( $id ) ) {
				return $id;
			}
		} catch ( Exception $e ) {
			return '';
		}

		return '';
	}

	/**
	 * Parse the ID field.
	 *
	 * If we're not doing an update, create a placeholder movie so mapping works
	 * for rows following this one.
	 *
	 * @param  string $value Field value.
	 * @return int
	 */
	public function parse_id_field( $value ) {
		global $wpdb;

		$id = absint( $value );

		if ( ! $id ) {
			return 0;
		}

		// See if this maps to an ID placeholder already.
		$original_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_original_id' AND meta_value = %s;", $id ) ); // WPCS: db call ok, cache ok.

		if ( $original_id ) {
			return absint( $original_id );
		}

		// Not updating? Make sure we have a new placeholder for this ID.
		if ( ! $this->params['update_existing'] ) {
			$movie = new MasVideos_Movie();
			$movie->set_name( 'Import placeholder for ' . $id );
			$movie->set_status( 'importing' );
			$movie->add_meta_data( '_original_id', $id, true );
			$id = $movie->save();
		}

		return $id && ! is_wp_error( $id ) ? $id : 0;
	}

	/**
	 * Parse relative comma-delineated field and return movie ID.
	 *
	 * @param string $value Field value.
	 * @return array
	 */
	public function parse_relative_comma_field( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		return array_filter( array_map( array( $this, 'parse_relative_field' ), $this->explode_values( $value ) ) );
	}

	/**
	 * Parse a comma-delineated field from a CSV.
	 *
	 * @param string $value Field value.
	 * @return array
	 */
	public function parse_comma_field( $value ) {
		if ( empty( $value ) && '0' !== $value ) {
			return array();
		}

		return array_map( 'masvideos_clean', $this->explode_values( $value ) );
	}

	/**
	 * Parse a field that is generally '1' or '0' but can be something else.
	 *
	 * @param string $value Field value.
	 * @return bool|string
	 */
	public function parse_bool_field( $value ) {
		if ( '0' === $value ) {
			return false;
		}

		if ( '1' === $value ) {
			return true;
		}

		// Don't return explicit true or false for empty fields or values like 'notify'.
		return masvideos_clean( $value );
	}

	/**
	 * Parse a float value field.
	 *
	 * @param string $value Field value.
	 * @return float|string
	 */
	public function parse_float_field( $value ) {
		if ( '' === $value ) {
			return $value;
		}

		// Remove the ' prepended to fields that start with - if needed.
		$value = $this->unescape_negative_number( $value );

		return floatval( $value );
	}

	/**
	 * Parse a category field from a CSV.
	 * Categories are separated by commas and subgenres are "parent > subcategory".
	 *
	 * @param string $value Field value.
	 * @return array of arrays with "parent" and "name" keys.
	 */
	public function parse_genres_field( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		$row_terms  = $this->explode_values( $value );
		$genres = array();

		foreach ( $row_terms as $row_term ) {
			$parent = null;
			$_terms = array_map( 'trim', explode( '>', $row_term ) );
			$total  = count( $_terms );

			foreach ( $_terms as $index => $_term ) {
				// Check if category exists. Parent must be empty string or null if doesn't exists.
				$term = term_exists( $_term, 'movie_genre', $parent );

				if ( is_array( $term ) ) {
					$term_id = $term['term_id'];
					// Don't allow users without capabilities to create new genres.
				} elseif ( ! current_user_can( 'manage_movie_terms' ) ) {
					break;
				} else {
					$term = wp_insert_term( $_term, 'movie_genre', array( 'parent' => intval( $parent ) ) );

					if ( is_wp_error( $term ) ) {
						break; // We cannot continue if the term cannot be inserted.
					}

					$term_id = $term['term_id'];
				}

				// Only requires assign the last category.
				if ( ( 1 + $index ) === $total ) {
					$genres[] = $term_id;
				} else {
					// Store parent to be able to insert or query genres based in parent ID.
					$parent = $term_id;
				}
			}
		}

		return $genres;
	}

	/**
	 * Parse a tag field from a CSV.
	 *
	 * @param  string $value Field value.
	 * @return array
	 */
	public function parse_tags_field( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		$names = $this->explode_values( $value );
		$tags  = array();

		foreach ( $names as $name ) {
			$term = get_term_by( 'name', $name, 'movie_tag' );

			if ( ! $term || is_wp_error( $term ) ) {
				$term = (object) wp_insert_term( $name, 'movie_tag' );
			}

			if ( ! is_wp_error( $term ) ) {
				$tags[] = $term->term_id;
			}
		}

		return $tags;
	}

	/**
	 * Parse images list from a CSV. Images can be filenames or URLs.
	 *
	 * @param  string $value Field value.
	 * @return array
	 */
	public function parse_images_field( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		$images = array();

		foreach ( $this->explode_values( $value ) as $image ) {
			if ( stristr( $image, '://' ) ) {
				$images[] = esc_url_raw( $image );
			} else {
				$images[] = sanitize_file_name( $image );
			}
		}

		return $images;
	}

	/**
	 * Parse dates from a CSV.
	 * Dates requires the format YYYY-MM-DD and time is optional.
	 *
	 * @param  string $value Field value.
	 * @return string|null
	 */
	public function parse_date_field( $value ) {
		if ( empty( $value ) ) {
			return null;
		}

		if ( preg_match( '/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])([ 01-9:]*)$/', $value ) ) {
			// Don't include the time if the field had time in it.
			return current( explode( ' ', $value ) );
		}

		return null;
	}

	/**
	 * Just skip current field.
	 *
	 * By default is applied masvideos_clean() to all not listed fields
	 * in self::get_formating_callback(), use this method to skip any formating.
	 *
	 * @param  string $value Field value.
	 * @return string
	 */
	public function parse_skip_field( $value ) {
		return $value;
	}


	/**
	 * Parse an int value field
	 *
	 * @param int $value field value.
	 * @return int
	 */
	public function parse_int_field( $value ) {
		// Remove the ' prepended to fields that start with - if needed.
		$value = $this->unescape_negative_number( $value );

		return intval( $value );
	}

	/**
	 * Parse a category field from a CSV.
	 * Categories are separated by commas and subcategories are "parent > subcategory".
	 *
	 * @param string $value Field value.
	 * @return array of arrays with "parent" and "name" keys.
	 */
	public function parse_person_categories_field( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		$row_terms  = $this->explode_values( $value );
		$categories = array();

		foreach ( $row_terms as $row_term ) {
			$parent = null;
			$_terms = array_map( 'trim', explode( '>', $row_term ) );
			$total  = count( $_terms );

			foreach ( $_terms as $index => $_term ) {
				// Check if category exists. Parent must be empty string or null if doesn't exists.
				$term = term_exists( $_term, 'person_cat', $parent );

				if ( is_array( $term ) ) {
					$term_id = $term['term_id'];
					// Don't allow users without capabilities to create new categories.
				} elseif ( ! current_user_can( 'manage_person_terms' ) ) {
					break;
				} else {
					$term = wp_insert_term( $_term, 'person_cat', array( 'parent' => intval( $parent ) ) );

					if ( is_wp_error( $term ) ) {
						break; // We cannot continue if the term cannot be inserted.
					}

					$term_id = $term['term_id'];
				}

				// Only requires assign the last category.
				if ( ( 1 + $index ) === $total ) {
					$categories[] = $term_id;
				} else {
					// Store parent to be able to insert or query categories based in parent ID.
					$parent = $term_id;
				}
			}
		}

		return $categories;
	}

	/**
	 * Get formatting callback.
	 *
	 * @return array
	 */
	protected function get_formating_callback() {

		/**
		 * Columns not mentioned here will get parsed with 'masvideos_clean'.
		 * column_name => callback.
		 */
		$data_formatting = array(
			'id'                     => array( $this, 'parse_id_field' ),
			'type'                   => array( $this, 'parse_comma_field' ),
			'published'              => array( $this, 'parse_float_field' ),
			'featured'               => array( $this, 'parse_bool_field' ),
			'name'                   => array( $this, 'parse_skip_field' ),
			'short_description'      => array( $this, 'parse_skip_field' ),
			'description'            => array( $this, 'parse_skip_field' ),
			'reviews_allowed'        => array( $this, 'parse_bool_field' ),
			'genre_ids'              => array( $this, 'parse_genres_field' ),
			'tag_ids'                => array( $this, 'parse_tags_field' ),
			'images'                 => array( $this, 'parse_images_field' ),
			'parent_id'              => array( $this, 'parse_relative_field' ),
			'movie_choice'           => array( $this, 'parse_skip_field' ),
			'movie_attachment_id'    => array( $this, 'parse_images_field' ),
			'movie_embed_content'    => 'masvideos_sanitize_textarea_iframe',
			'movie_url_link'         => 'esc_url_raw',
			'movie_is_affiliate_link'=> array( $this, 'parse_bool_field' ),
			'movie_release_date'     => array( $this, 'parse_date_field' ),
			'movie_run_time'         => array( $this, 'parse_skip_field' ),
			'movie_censor_rating'    => array( $this, 'parse_skip_field' ),
			'menu_order'             => 'intval',
			'recommended_movie_ids'  => array( $this, 'parse_relative_comma_field' ),
			'related_video_ids'      => array( $this, 'parse_relative_comma_field' ),
			'imdb_id'                => array( $this, 'parse_skip_field' ),
			'tmdb_id'                => array( $this, 'parse_skip_field' ),
		);

		/**
		 * Match special column names.
		 */
		$regex_match_data_formatting = array(
			'/cast:images*/'             => array( $this, 'parse_images_field' ),
			'/cast:category*/'           => array( $this, 'parse_person_categories_field' ),
			'/cast:position*/'           => 'intval',
			'/crew:images*/'             => array( $this, 'parse_images_field' ),
			'/crew:category*/'           => array( $this, 'parse_person_categories_field' ),
			'/crew:position*/'           => 'intval',
			'/attributes:value*/'        => array( $this, 'parse_comma_field' ),
			'/attributes:visible*/'      => array( $this, 'parse_bool_field' ),
			'/attributes:taxonomy*/'     => array( $this, 'parse_bool_field' ),
			'/sources:embed_content*/'   => 'masvideos_sanitize_textarea_iframe',
			'/sources:link*/'            => 'esc_url_raw',
			'/sources:is_affiliate*/'	 => array( $this, 'parse_bool_field' ),
			'/sources:date_added*/'      => array( $this, 'parse_date_field' ),
			'/sources:position*/'        => 'intval',
			'/meta:*/'                   => 'wp_kses_post', // Allow some HTML in meta fields.
		);

		$callbacks = array();

		// Figure out the parse function for each column.
		foreach ( $this->get_mapped_keys() as $index => $heading ) {
			$callback = 'masvideos_clean';

			if ( isset( $data_formatting[ $heading ] ) ) {
				$callback = $data_formatting[ $heading ];
			} else {
				foreach ( $regex_match_data_formatting as $regex => $callback ) {
					if ( preg_match( $regex, $heading ) ) {
						$callback = $callback;
						break;
					}
				}
			}

			$callbacks[] = $callback;
		}

		return apply_filters( 'masvideos_movie_importer_formatting_callbacks', $callbacks, $this );
	}

	/**
	 * Check if strings starts with determined word.
	 *
	 * @param  string $haystack Complete sentence.
	 * @param  string $needle   Excerpt.
	 * @return bool
	 */
	protected function starts_with( $haystack, $needle ) {
		return substr( $haystack, 0, strlen( $needle ) ) === $needle;
	}

	/**
	 * Expand special and internal data into the correct formats for the movie CRUD.
	 *
	 * @param  array $data Data to import.
	 * @return array
	 */
	protected function expand_data( $data ) {
		$data = apply_filters( 'masvideos_movie_importer_pre_expand_data', $data );

		// Images field maps to image and gallery id fields.
		if ( isset( $data['images'] ) ) {
			$images               = $data['images'];
			$data['raw_image_id'] = array_shift( $images );

			if ( ! empty( $images ) ) {
				$data['raw_gallery_image_ids'] = $images;
			}
			unset( $data['images'] );
		}

		// Status is mapped from a special published field.
		if ( isset( $data['published'] ) ) {
			$statuses       = array(
				-1 => 'draft',
				0  => 'private',
				1  => 'publish',
			);
			$data['status'] = isset( $statuses[ $data['published'] ] ) ? $statuses[ $data['published'] ] : -1;

			unset( $data['published'] );
		}

		// Handle special column names which span multiple columns.
		$cast = array();
		$crew = array();
		$attributes = array();
		$sources = array();
		$meta_data  = array();

		foreach ( $data as $key => $value ) {
			if ( $this->starts_with( $key, 'cast:id' ) ) {
				if ( ! empty( $value ) ) {
					$cast[ str_replace( 'cast:id', '', $key ) ]['id'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'cast:imdb_id' ) ) {
				if ( ! empty( $value ) ) {
					$cast[ str_replace( 'cast:imdb_id', '', $key ) ]['imdb_id'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'cast:tmdb_id' ) ) {
				if ( ! empty( $value ) ) {
					$cast[ str_replace( 'cast:tmdb_id', '', $key ) ]['tmdb_id'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'cast:name' ) ) {
				if ( ! empty( $value ) ) {
					$cast[ str_replace( 'cast:name', '', $key ) ]['name'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'cast:images' ) ) {
				if ( ! empty( $value ) ) {
					$cast[ str_replace( 'cast:images', '', $key ) ]['images'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'cast:category' ) ) {
				if ( ! empty( $value ) ) {
					$cast[ str_replace( 'cast:category', '', $key ) ]['category'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'cast:character' ) ) {
				if ( ! empty( $value ) ) {
					$cast[ str_replace( 'cast:character', '', $key ) ]['character'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'cast:position' ) ) {
				if ( ! empty( $value ) ) {
					$cast[ str_replace( 'cast:position', '', $key ) ]['position'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'crew:id' ) ) {
				if ( ! empty( $value ) ) {
					$crew[ str_replace( 'crew:id', '', $key ) ]['id'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'crew:imdb_id' ) ) {
				if ( ! empty( $value ) ) {
					$crew[ str_replace( 'crew:imdb_id', '', $key ) ]['imdb_id'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'crew:tmdb_id' ) ) {
				if ( ! empty( $value ) ) {
					$crew[ str_replace( 'crew:tmdb_id', '', $key ) ]['tmdb_id'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'crew:name' ) ) {
				if ( ! empty( $value ) ) {
					$crew[ str_replace( 'crew:name', '', $key ) ]['name'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'crew:images' ) ) {
				if ( ! empty( $value ) ) {
					$crew[ str_replace( 'crew:images', '', $key ) ]['images'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'crew:category' ) ) {
				if ( ! empty( $value ) ) {
					$crew[ str_replace( 'crew:category', '', $key ) ]['category'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'crew:job' ) ) {
				if ( ! empty( $value ) ) {
					$crew[ str_replace( 'crew:job', '', $key ) ]['job'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'crew:position' ) ) {
				if ( ! empty( $value ) ) {
					$crew[ str_replace( 'crew:position', '', $key ) ]['position'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'attributes:name' ) ) {
				if ( ! empty( $value ) ) {
					$attributes[ str_replace( 'attributes:name', '', $key ) ]['name'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'attributes:value' ) ) {
				$attributes[ str_replace( 'attributes:value', '', $key ) ]['value'] = $value;
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'attributes:taxonomy' ) ) {
				$attributes[ str_replace( 'attributes:taxonomy', '', $key ) ]['taxonomy'] = masvideos_string_to_bool( $value );
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'attributes:visible' ) ) {
				$attributes[ str_replace( 'attributes:visible', '', $key ) ]['visible'] = masvideos_string_to_bool( $value );
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'attributes:default' ) ) {
				if ( ! empty( $value ) ) {
					$attributes[ str_replace( 'attributes:default', '', $key ) ]['default'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'sources:name' ) ) {
				if ( ! empty( $value ) ) {
					$sources[ str_replace( 'sources:name', '', $key ) ]['name'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'sources:choice' ) ) {
				if ( ! empty( $value ) ) {
					$sources[ str_replace( 'sources:choice', '', $key ) ]['choice'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'sources:embed_content' ) ) {
				if ( ! empty( $value ) ) {
					$sources[ str_replace( 'sources:embed_content', '', $key ) ]['embed_content'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'sources:link' ) ) {
				if ( ! empty( $value ) ) {
					$sources[ str_replace( 'sources:link', '', $key ) ]['link'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'sources:is_affiliate' ) ) {
				if ( ! empty( $value ) ) {
					$sources[ str_replace( 'sources:is_affiliate', '', $key ) ]['is_affiliate'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'sources:quality' ) ) {
				if ( ! empty( $value ) ) {
					$sources[ str_replace( 'sources:quality', '', $key ) ]['quality'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'sources:language' ) ) {
				if ( ! empty( $value ) ) {
					$sources[ str_replace( 'sources:language', '', $key ) ]['language'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'sources:player' ) ) {
				if ( ! empty( $value ) ) {
					$sources[ str_replace( 'sources:player', '', $key ) ]['player'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'sources:date_added' ) ) {
				if ( ! empty( $value ) ) {
					$sources[ str_replace( 'sources:date_added', '', $key ) ]['date_added'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'sources:position' ) ) {
				if ( ! empty( $value ) ) {
					$sources[ str_replace( 'sources:position', '', $key ) ]['position'] = $value;
				}
				unset( $data[ $key ] );

			} elseif ( $this->starts_with( $key, 'meta:' ) ) {
				$meta_data[] = array(
					'key'   => str_replace( 'meta:', '', $key ),
					'value' => $value,
				);
				unset( $data[ $key ] );
			}
		}

		if ( ! empty( $cast ) ) {
			// Remove empty cast and clear indexes.
			foreach ( $cast as $person ) {
				if ( empty( $person['id'] ) && empty( $person['name'] ) ) {
					continue;
				}

				$data['raw_cast'][] = $person;
			}
		}

		if ( ! empty( $crew ) ) {
			// Remove empty crew and clear indexes.
			foreach ( $crew as $person ) {
				if ( empty( $person['id'] ) && empty( $person['name'] ) ) {
					continue;
				}

				$data['raw_crew'][] = $person;
			}
		}

		if ( ! empty( $attributes ) ) {
			// Remove empty attributes and clear indexes.
			foreach ( $attributes as $attribute ) {
				if ( empty( $attribute['name'] ) ) {
					continue;
				}

				$data['raw_attributes'][] = $attribute;
			}
		}

		if ( ! empty( $sources ) ) {
			// Remove empty sources and clear indexes.
			foreach ( $sources as $source ) {
				if ( empty( $source['name'] ) ) {
					continue;
				}

				$data['raw_sources'][] = $source;
			}
		}

		if ( ! empty( $meta_data ) ) {
			$data['meta_data'] = $meta_data;
		}

		return $data;
	}

	/**
	 * Map and format raw data to known fields.
	 */
	protected function set_parsed_data() {
		$parse_functions = $this->get_formating_callback();
		$mapped_keys     = $this->get_mapped_keys();
		$use_mb          = function_exists( 'mb_convert_encoding' );

		// Parse the data.
		foreach ( $this->raw_data as $row_index => $row ) {
			// Skip empty rows.
			if ( ! count( array_filter( $row ) ) ) {
				continue;
			}

			$this->parsing_raw_data_index = $row_index;

			$data = array();

			do_action( 'masvideos_movie_importer_before_set_parsed_data', $row, $mapped_keys );

			foreach ( $row as $id => $value ) {
				// Skip ignored columns.
				if ( empty( $mapped_keys[ $id ] ) ) {
					continue;
				}

				// Convert UTF8.
				if ( $use_mb ) {
					$encoding = mb_detect_encoding( $value, mb_detect_order(), true );
					if ( $encoding ) {
						$value = mb_convert_encoding( $value, 'UTF-8', $encoding );
					} else {
						$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
					}
				} else {
					$value = wp_check_invalid_utf8( $value, true );
				}

				$data[ $mapped_keys[ $id ] ] = call_user_func( $parse_functions[ $id ], $value );
			}

			$this->parsed_data[] = apply_filters( 'masvideos_movie_importer_parsed_data', $this->expand_data( $data ), $this );
		}
	}

	/**
	 * Get a string to identify the row from parsed data.
	 *
	 * @param  array $parsed_data Parsed data.
	 * @return string
	 */
	protected function get_row_id( $parsed_data ) {
		$id       = isset( $parsed_data['id'] ) ? absint( $parsed_data['id'] ) : 0;
		$name     = isset( $parsed_data['name'] ) ? esc_attr( $parsed_data['name'] ) : '';
		$row_data = array();

		if ( $name ) {
			$row_data[] = $name;
		}
		if ( $id ) {
			/* translators: %d: movie ID */
			$row_data[] = sprintf( __( 'ID %d', 'masvideos' ), $id );
		}

		return implode( ', ', $row_data );
	}

	/**
	 * Process importer.
	 *
	 * Do not import movies with IDs or SKUs that already exist if option
	 * update existing is false, and likewise, if updating movies, do not
	 * process rows which do not exist if an ID/SKU is provided.
	 *
	 * @return array
	 */
	public function import() {
		$this->start_time = time();
		$index            = 0;
		$update_existing  = $this->params['update_existing'];
		$data             = array(
			'imported' => array(),
			'failed'   => array(),
			'updated'  => array(),
			'skipped'  => array(),
		);

		foreach ( $this->parsed_data as $parsed_data_key => $parsed_data ) {
			do_action( 'masvideos_movie_import_before_import', $parsed_data );

			$id         = isset( $parsed_data['id'] ) ? absint( $parsed_data['id'] ) : 0;
			$id_exists  = false;

			if ( $id ) {
				$movie   = masvideos_get_movie( $id );
				$id_exists = $movie && 'importing' !== $movie->get_status();
			}

			if ( $id_exists && ! $update_existing ) {
				$data['skipped'][] = new WP_Error( 'masvideos_movie_importer_error', __( 'A movie with this ID already exists.', 'masvideos' ), array(
					'id'  => $id,
					'row' => $this->get_row_id( $parsed_data ),
				) );
				continue;
			}

			if ( $update_existing && $id  && ! $id_exists ) {
				$data['skipped'][] = new WP_Error( 'masvideos_movie_importer_error', __( 'No matching movie exists to update.', 'masvideos' ), array(
					'id'  => $id,
					'row' => $this->get_row_id( $parsed_data ),
				) );
				continue;
			}

			$result = $this->process_item( $parsed_data );

			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'row' => $this->get_row_id( $parsed_data ) ) );
				$data['failed'][] = $result;
			} elseif ( $result['updated'] ) {
				$data['updated'][] = $result['id'];
			} else {
				$data['imported'][] = $result['id'];
			}

			$index ++;

			if ( $this->params['prevent_timeouts'] && ( $this->time_exceeded() || $this->memory_exceeded() ) ) {
				$this->file_position = $this->file_positions[ $index ];
				break;
			}
		}

		return $data;
	}
}
