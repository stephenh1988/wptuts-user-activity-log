<?php
/*
Plugin Name: Wptuts User Activity Log 
Version: 1.0
Description: This code complements a series at wp.tutsplus.com on Custom Database Tables
Author: Stephen Harris
Author URI: http://www.stephenharris.info
*/
/*  Copyright 2011 Stephen Harris (contact@stephenharris.info)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
*/


/**
 * Store our table name in $wpdb with correct prefix
 * Prefix will vary between sites so hook onto switch_blog too
 * @since 1.0
*/
function wptuts_register_activity_log_table(){
    global $wpdb;
    $wpdb->wptuts_activity_log = "{$wpdb->prefix}wptuts_activity_log";
}
add_action( 'init', 'wptuts_register_activity_log_table',1);
add_action( 'switch_blog', 'wptuts_register_activity_log_table');



/**
 * Creates our table
 * Hooked onto activate_[plugin] (via register_activation_hook)
 * @since 1.0
*/
function wptuts_create_activity_log_table(){

	global $wpdb;
	global $charset_collate;

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

	//Call this manually as we may have missed the init hook
	wptuts_register_activity_log_table();

	$sql_create_table = "CREATE TABLE {$wpdb->wptuts_activity_log} (
		log_id bigint(20) unsigned NOT NULL auto_increment,
		user_id bigint(20) unsigned NOT NULL default '0',
		activity varchar(30) NOT NULL default 'updated',
		object_id bigint(20) unsigned NOT NULL default '0',
		object_type varchar(20) NOT NULL default 'post',
		activity_date datetime NOT NULL default '0000-00-00 00:00:00',
		PRIMARY KEY  (log_id),
		KEY abc (user_id)
		) $charset_collate; ";

	dbDelta($sql_create_table);
}
register_activation_hook(__FILE__,'wptuts_create_activity_log_table');
