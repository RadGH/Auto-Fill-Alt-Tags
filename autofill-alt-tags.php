<?php
/*
Plugin Name: Autofill Alt Tags
Version:     1.0.0
Plugin URI:  https://radleysustaire.com/
Description: Automatically fills in alt tags for your images in the post content. Preserves existing alt text if it exists. If alt text is not entered for an attachment, the caption or title will be used instead. No configuration necessary.
Author:      Radley Sustaire
Author URI:  https://radleysustaire.com/
License:     GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.txt
*/

/*
GNU GENERAL PUBLIC LICENSE

A WordPress plugin that allows you to mark pages with an option to hide
from search engines, by adding a noindex meta tag to the single page's <head>
Copyright (C) 2018 Radley Sustaire

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>
*/

if( !defined( 'ABSPATH' ) ) exit;

/**
 * Return an attachment ID from an image url or html tag.
 *
 * @see https://gist.github.com/RadGH/e15cbb190474a1b7b22a8d5eb99a25c8
 *
 * @param $url_or_img_tag
 *
 * @return int|false
 */
function rs_reverse_media_id_lookup( $url_or_img_tag ) {
	// Get the image url
	if ( preg_match('/(https?:\/\/[^ \'\"]+\.(jpg|jpeg|png|gif|bmp))/i', $url_or_img_tag, $m) && $m[1] ) $url = $m[1];
	else $url = $url_or_img_tag;
	
	// Split the $url into two parts with the wp-content directory as the separator.
	$parse_url  = explode( parse_url( WP_CONTENT_URL, PHP_URL_PATH ), $url );
	if ( !$parse_url || !isset($parse_url[1]) ) return false;
	
	$parse_url[1] = preg_replace( '/-[0-9]{1,4}x[0-9]{1,4}\.(jpg|jpeg|png|gif|bmp)$/i', '.$1', $parse_url[1] );
	
	// Get the host of the current site and the host of the $url, ignoring www.
	$this_host = str_ireplace( 'www.', '', parse_url( home_url(), PHP_URL_HOST ) );
	$file_host = str_ireplace( 'www.', '', parse_url( $url, PHP_URL_HOST ) );
	
	// Return nothing if there aren't any $url parts or if the current host and $url host do not match.
	if ( ! isset( $parse_url[1] ) || empty( $parse_url[1] ) || ( $this_host != $file_host ) )
		return false;
	
	// Now we're going to quickly search the DB for any attachment GUID with a partial path match.
	// Example: /uploads/2013/05/test-image.jpg
	global $wpdb;
	
	$sql        = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE guid RLIKE %s;", $parse_url[1] );
	$attachment = $wpdb->get_col( $sql );
	
	return empty($attachment[0]) ? false : (int) $attachment[0];
}

/**
 * Fill alt tags for images inside post content automatically, whenever the alt text is blank in the code but exists as attachment metadata.
 *
 * Note: Requires external function, rs_reverse_media_id_lookup()
 *       Get it here: https://gist.github.com/RadGH/e15cbb190474a1b7b22a8d5eb99a25c8
 *
 * @see https://gist.github.com/RadGH/8600eef8bd03575b0fa76b66faff8389
 *
 * @param $content
 *
 * @return mixed
 */
function rs_fill_missing_alt_tags( $content ) {
	if ( preg_match_all('/<img (.+?)\/?>/', $content, $images) ) {
		foreach( $images[1] as $index => $value ) {
			
			// Get the attachment id from the full image URL
			$attachment_id = rs_reverse_media_id_lookup( $images[0][$index] );
			if ( !$attachment_id ) continue; // External image?
			
			// Get the image alt, or caption, or title... otherwise skip the image.
			$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( !$alt ) {
				$p = get_post( $attachment_id );
				
				if ( $p->post_excerpt ) $alt = $p->post_excerpt;
				else if ( $p->post_title ) $alt = $p->post_title;
				else continue;
			}
			
			// Find alt tags that do not have content, such as:
			// <img alt src="test.jpg">
			// <img alt="" src="test.jpg">
			// <img alt='' src="test.jpg">
			// SEE MORE: https://regexr.com/3kj2f
			if ( preg_match( '/\salt(=([\'"])\2|(?!=))/', $value, $found) ) {
				$new_img = str_replace( $found[0], ' alt="'. esc_attr(preg_quote($alt)) .'"', $images[0][$index] );
				$content = str_replace( $images[0][$index], $new_img, $content );
			}
			
			// Or if the image has no alt tag, add one in.
			else if ( stripos($value, 'alt=') === false ) {
				$new_img = str_replace( $value, 'alt="'. esc_attr(preg_quote($alt)) .'" ' . $value, $images[0][$index] );
				$content = str_replace( $images[0][$index], $new_img, $content );
			}
			
		}
	}
	
	return $content;
}
add_filter( 'the_content', 'rs_fill_missing_alt_tags', 20 );