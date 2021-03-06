<?php
/**
 * Class for parameter-based Video querying
 *
 * @package  MasVideos/Classes
 * @version  1.0.0
 * @since    1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Video query class.
 */
class MasVideos_Video_Query extends MasVideos_Object_Query {

    /**
     * Valid query vars for videos.
     *
     * @return array
     */
    protected function get_default_query_vars() {
        return array_merge(
            parent::get_default_query_vars(),
            array(
                'status'            => array( 'draft', 'pending', 'private', 'publish' ),
                'type'              => array( 'video' ),
                'limit'             => get_option( 'posts_per_page' ),
                'include'           => array(),
                'date_created'      => '',
                'date_modified'     => '',
                'featured'          => '',
                'visibility'        => '',
                'reviews_allowed'   => '',
                'category'          => array(),
                'tag'               => array(),
                'average_rating'    => '',
                'review_count'      => '',
            )
        );
    }

    /**
     * Get videos matching the current query vars.
     *
     * @return array|object of MasVideos_Video objects
     */
    public function get_videos() {
        $args    = apply_filters( 'masvideos_video_object_query_args', $this->get_query_vars() );
        $results = MasVideos_Data_Store::load( 'video' )->query( $args );
        return apply_filters( 'masvideos_video_object_query', $results, $args );
    }
}
