<?php

namespace Wabeo\AdvancedRemoveLinksInComments;

/**
 * Plugin name: Advanced Remove Links in Comments
 * Description: Remove links in comments based on article publication date and comment length.
 * Author: Willy Bahuaud
 * Author URI: https://wabeo.fr/
 * Version:1.0
 * Text Domain: advanced-remove-links-in-comments
 * Stable Tag:1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( esc_html__( 'Cheatin&#8217; uh?' ) );
}

/**
 * Load languages files on plugin loaded
 */
add_action( 'plugins_loaded', __NAMESPACE__ . '\load_languages' );
function load_languages() {
	load_plugin_textdomain( 'advanced-remove-links-in-comments', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

/**
 * Remove links in comments
 */
add_filter( 'preprocess_comment', __NAMESPACE__ . '\filter_links_in_comments' );
function filter_links_in_comments( $commentdata ) {
	// bypass registred users
	if ( $commentdata['user_id'] ) {
		return $commentdata;
	}

	// Check date
	$comment_post = get_post( intval( $commentdata['comment_post_ID'] ) );
	$date = ! empty( $commentdata['comment_date_gmt'] ) ? $commentdata['comment_date_gmt'] : current_time( 'mysql', 1 );
	$post_age = strtotime( $date ) - strtotime( $comment_post->post_date_gmt );
	// If the post is too old, disallow links in content
	if ( get_option( 'post_age_for_allowed_comments', MONTH_IN_SECONDS ) < $post_age ) {
		$allowed_links = 0;
	} else {
		// Else, define how much links are allowed, depending words count
		$max_link = get_option( 'max_links_in_comments', array( 'words' => 50, 'max' => 3 ) );
		$words = count_words( $commentdata['comment_content'] );
		$allowed_links = ( $max_link['max'] < $count = floor( $words / $max_link['words'] ) ) ? $max_link['max'] : $count;
	}

	// Check link into `comment_author_url`
	if ( $allowed_links && ! empty( $commentdata['comment_author_url'] ) ) {
		$allowed_links--;
	} else {
		$commentdata['comment_author_url'] = false;
	}

	// Link buster Regex
	$re = '/<a[^>]*>([\s\S]*?)<\/a>/mi';

	// Check links into `comment_content`
	if ( preg_match_all( $re, $commentdata['comment_content'], $links, PREG_SET_ORDER ) ) {
		if ( count( $links ) > intval( get_option( 'comment_max_links' ) ) ) {
			add_filter( 'pre_comment_approved', '__return_zero' );
		}
		if ( count( $links ) > $allowed_links ) {
		  	$commentdata['comment_content'] = preg_replace_callback( $re,
				function( $link ) use ( &$allowed_links ) {
					if ( $allowed_links ) {
						$allowed_links--;
						return $link[0];
					} else {
						return preg_replace( '/^https?:\/\//', '', $link[1] );
					}
				},
				$commentdata['comment_content']
			);
		}
	}

	return $commentdata;
}


/**
 * Count words
 *
 * Inspired of `wp_trim_words` in wp-inc/formatting.php
 * @param  	$text	[text to parse]
 * @return  integer [word count]
 */
function count_words( $text ) {
	$text = wp_strip_all_tags( $text );

	if ( strpos( _x( 'words', 'Word count type. Do not translate!' ), 'characters' ) === 0
	  && preg_match( '/^utf\-?8$/i', get_option( 'blog_charset' ) ) ) {
		$text = trim( preg_replace( "/[\n\r\t ]+/", ' ', $text ), ' ' );
		preg_match_all( '/./u', $text, $words_array );
	} else {
		$words_array = preg_split( "/[\n\r\t ]+/", $text, null, PREG_SPLIT_NO_EMPTY );
	}
	return count( $words_array );
}

/**
 * Setting panel
 */
add_action( 'admin_init', __NAMESPACE__ . '\register_comments_spam_rules' );
function register_comments_spam_rules() {
	register_setting( 'discussion', 'post_age_for_allowed_comments' );
	register_setting( 'discussion', 'max_links_in_comments' );

	add_settings_field(
		'post_age_for_allowed_comments',
		__( 'Period during which links are allowed', 'advanced-remove-links-in-comments' ),
		__NAMESPACE__ . '\selectbox',
		'discussion',
		'default',
		array(
			'label_for' => 'post_age_for_allowed_comments',
			'selected'  => get_option( 'post_age_for_allowed_comments', MONTH_IN_SECONDS ),
			'label'     => __( 'Period during which links are allowed into comments of an article', 'advanced-remove-links-in-comments' ),
		)
	);

	add_settings_field(
		'max_links_in_comments',
		__( 'Max number of allowed links by comment', 'advanced-remove-links-in-comments' ),
		__NAMESPACE__ . '\max_link',
		'discussion',
		'default',
		array(
			'label_for' => 'max_links_in_comments',
			'value'     => get_option( 'max_links_in_comments', array( 'words' => 50, 'max' => 3 ) ),
		)
	);
}

/**
 * Max links option callback
 */
function max_link( $args ) {
	$args['value'] = wp_parse_args( $args['value'], array(
		'words' => 50,
		'max'   => 3,
	) );
	printf( '<p>' . __( 'One allowed link by %s words under the limit of %s links.<br/>This limit is not applied on registred users.', 'advanced-remove-links-in-comments' ) . '</p>',
	    sprintf( '<input type="number" value="%1$d" min="0" step="1" name="%2$s[words]" class="small-text"/>',
			esc_attr( $args['value']['words'] ),
			esc_attr( $args['label_for'] )
		),
		sprintf( '<input type="number" value="%1$d" min="0" step="1" name="%2$s[max]" class="small-text"/>',
			esc_attr( $args['value']['max'] ),
			esc_attr( $args['label_for'] )
		)
	);
}

/**
 * Select calback
 */
function selectbox( $args ) {
	if ( ! empty( $args['label_for'] ) ) {
		printf( '<p><label for="%s">%s</label></p>', esc_attr( $args['label_for'] ), esc_html( $args['label'] ) );
	}
	printf( '<p><select name="%1$s" id="%1$s">', esc_attr( $args['label_for'] ) );
	foreach ( array(
		WEEK_IN_SECONDS      => __( 'One week', 'advanced-remove-links-in-comments' ),
		2 * WEEK_IN_SECONDS  => __( 'Two weeks', 'advanced-remove-links-in-comments' ),
		MONTH_IN_SECONDS     => __( 'One month', 'advanced-remove-links-in-comments' ),
		2 * MONTH_IN_SECONDS => __( 'Two months', 'advanced-remove-links-in-comments' ),
		3 * MONTH_IN_SECONDS => __( 'Three months', 'advanced-remove-links-in-comments' ),
		6 * MONTH_IN_SECONDS => __( 'Six months', 'advanced-remove-links-in-comments' ),
		YEAR_IN_SECONDS      => __( 'One year', 'advanced-remove-links-in-comments' ),
	) as $k => $value ) {
		printf( '<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( $k ),
			selected( $k, $args['selected'], false ),
			esc_html( $value )
		);
	}
	echo '</select><p>';
}

/**
 * Settings link into plugin row
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\settings_action_links', 10, 2 );
function settings_action_links( $links, $file ) {
	if ( current_user_can( 'manage_options' ) ) {
		array_unshift( $links, '<a href="' . admin_url( 'options-discussion.php' ) . '">' . __( 'Settings' ) . '</a>' );
	}
	return $links;
}
