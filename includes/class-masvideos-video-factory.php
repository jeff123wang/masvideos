<?php
/**
 * Video Factory
 *
 * The MasVideos movie factory creating the right movie object.
 *
 * @package MasVideos/Classes
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Video factory class.
 */
class MasVideos_Video_Factory {

    /**
     * Get a movie.
     *
     * @param mixed $movie_id MasVideos_Video|WP_Post|int|bool $movie Video instance, post instance, numeric or false to use global $post.
     * @return MasVideos_Video|bool Video object or null if the movie cannot be loaded.
     */
    public function get_movie( $movie_id = false ) {
        $movie_id = $this->get_movie_id( $movie_id );

        if ( ! $movie_id ) {
            return false;
        }

        $classname = $this->get_movie_classname( $movie_id );

        try {
            return new $classname( $movie_id );
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Gets a movie classname and allows filtering. Returns MasVideos_Video if the class does not exist.
     *
     * @since  1.0.0
     * @param  int    $movie_id   Video ID.
     * @return string
     */
    public static function get_movie_classname( $movie_id ) {
        $classname = apply_filters( 'masvideos_movie_class', 'MasVideos_Video', $movie_id );

        if ( ! $classname || ! class_exists( $classname ) ) {
            $classname = 'MasVideos_Video';
        }

        return $classname;
    }

    /**
     * Get the movie ID depending on what was passed.
     *
     * @since  1.0.0
     * @param  MasVideos_Video|WP_Post|int|bool $movie Video instance, post instance, numeric or false to use global $post.
     * @return int|bool false on failure
     */
    private function get_movie_id( $movie ) {
        global $post;

        if ( false === $movie && isset( $post, $post->ID ) && 'movie' === get_post_type( $post->ID ) ) {
            return absint( $post->ID );
        } elseif ( is_numeric( $movie ) ) {
            return $movie;
        } elseif ( $movie instanceof MasVideos_Video ) {
            return $movie->get_id();
        } elseif ( ! empty( $movie->ID ) ) {
            return $movie->ID;
        } else {
            return false;
        }
    }
}
