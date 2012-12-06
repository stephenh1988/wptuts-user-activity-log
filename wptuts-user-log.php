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

function wptuts_get_log_table_columns(){
    return array(
        'log_id'=> '%d',
        'user_id'=> '%d',
        'activity'=>'%s',
        'object_id'=>'%d',
        'object_type'=>'%s',
        'activity_date'=>'%s',
    );
}


/**
 * Inserts a log into the database
 *
 *@param $data array An array of key => value pairs to be inserted
 *@return int The log ID of the created activity log. Or WP_Error or false on failure.
*/
function wptuts_insert_log( $data=array() ){
    global $wpdb;

    //Set default values
    $data = wp_parse_args($data, array(
                 'user_id'=> get_current_user_id(),
                 'date'=> current_time('timestamp'),
    ));

    //Check date validity
    if( !is_float($data['date']) || $data['date'] <= 0 )
        return 0;

    //Convert activity date from local timestamp to GMT mysql format
    $data['activity_date'] = date_i18n( 'Y-m-d H:i:s', $data['date'], true );

    //Initialise column format array
    $column_formats = wptuts_get_log_table_columns();

    //Force fields to lower case
    $data = array_change_key_case ( $data );

    //White list columns
    $data = array_intersect_key($data, $column_formats);

    //Reorder $column_formats to match the order of columns given in $data
    $data_keys = array_keys($data);
    $column_formats = array_merge(array_flip($data_keys), $column_formats);
    $wpdb->insert($wpdb->wptuts_activity_log, $data, $column_formats);

    return $wpdb->insert_id;
}


/**
 * Updates an activity log with supplied data
 *
 *@param $log_id int ID of the activity log to be updated
 *@param $data array An array of column=>value pairs to be updated
 *@return bool Whether the log was successfully updated.
*/
function wptuts_update_log( $log_id, $data=array() ){
    global $wpdb;

    //Log ID must be positive integer
    $log_id = absint($log_id);
    if( empty($log_id) )
         return false;

    //Convert activity date from local timestamp to GMT mysql format
    if( isset($data['activity_date']) )
         $data['activity_date'] = date_i18n( 'Y-m-d H:i:s', $data['date'], true );

    //Initialise column format array
    $column_formats = wptuts_get_log_table_columns();

    //Force fields to lower case
    $data = array_change_key_case ( $data );

    //White list columns
    $data = array_intersect_key($data, $column_formats);

    //Reorder $column_formats to match the order of columns given in $data
    $data_keys = array_keys($data);
    $column_formats = array_merge(array_flip($data_keys), $column_formats);

    if ( false === $wpdb->update($wpdb->wptuts_activity_log, $data, array('log_id'=>$log_id), $column_formats) ) {
         return false;
    }

    return true;
}


/**
 * Retrieves activity logs from the database matching $query.
 * $query is an array which can contain the following keys:
 *
 * 'fields' - an array of columns to include in returned roles. Or 'count' to count rows. Default: empty (all fields).
 * 'orderby' - datetime, user_id or log_id. Default: datetime.
 * 'order' - asc or desc
 * 'user_id' - user ID to match, or an array of user IDs
 * 'since' - timestamp. Return only activities after this date. Default false, no restriction.
 * 'until' - timestamp. Return only activities up to this date. Default false, no restriction.
 *
 *@param $query Query array
 *@return array Array of matching logs. False on error.
*/
function wptuts_get_logs( $query=array() ){
     global $wpdb;

     /* Parse defaults */
     $defaults = array(
       'fields'=>array(),'orderby'=>'datetime','order'=>'desc', 'user_id'=>false,
       'since'=>false,'until'=>false,'number'=>10,'offset'=>0
     );
    $query = wp_parse_args($query, $defaults);

    /* Form a cache key from the query */
    $cache_key = 'wptuts_logs:'.md5( serialize($query));
    $cache = wp_cache_get( $cache_key );
    if ( false !== $cache ) {
            $cache = apply_filters('wptuts_get_logs', $cache, $query);
            return $cache;
    }
     extract($query);

    /* SQL Select */
    //Whitelist of allowed fields
    $allowed_fields = wptuts_get_log_table_columns();
    if( is_array($fields) ){

        //Convert fields to lowercase (as our column names are all lower case - see part 1)
        $fields = array_map('strtolower',$fields);

        //Sanitize by white listing
        $fields = array_intersect($fields, $allowed_fields);

    }else{
        $fields = strtolower($fields);
    }

    //Return only selected fields. Empty is interpreted as all
    if( empty($fields) ){
        $select_sql = "SELECT* FROM {$wpdb->wptuts_activity_log}";
    }elseif( 'count' == $fields ) {
        $select_sql = "SELECT COUNT(*) FROM {$wpdb->wptuts_activity_log}";
    }else{
        $select_sql = "SELECT ".implode(',',$fields)." FROM {$wpdb->wptuts_activity_log}";
    }

     /*SQL Join */
     //We don't need this, but we'll allow it be filtered (see 'wptuts_logs_clauses' )
     $join_sql='';

    /* SQL Where */
    //Initialise WHERE
    $where_sql = 'WHERE 1=1';
    if( !empty($log_id) )
       $where_sql .=  $wpdb->prepare(' AND log_id=%d', $log_id);

    if( !empty($user_id) ){
       //Force $user_id to be an array
       if( !is_array( $user_id) )
           $user_id = array($user_id);

       $user_id = array_map('absint',$user_id); //Cast as positive integers
       $user_id__in = implode(',',$user_id);
       $where_sql .=  " AND user_id IN($user_id__in)";
    }

    $since = absint($since);
    $until = absint($until);

    if( !empty($since) )
       $where_sql .=  $wpdb->prepare(' AND activity_date >= %s', date_i18n( 'Y-m-d H:i:s', $since,true));

    if( !empty($until) )
       $where_sql .=  $wpdb->prepare(' AND activity_date <= %s', date_i18n( 'Y-m-d H:i:s', $until,true));

    /* SQL Order */
    //Whitelist order
    $order = strtoupper($order);
    $order = ( 'ASC' == $order ? 'ASC' : 'DESC' );
    switch( $orderby ){
       case 'log_id':
            $order_sql = "ORDER BY log_id $order";
       break;
       case 'user_id':
            $order_sql = "ORDER BY user_id $order";
       break;
       case 'datetime':
             $order_sql = "ORDER BY activity_date $order";
       default:
       break;
    }

    /* SQL Limit */
    $offset = absint($offset); //Positive integer
    if( $number == -1 ){
         $limit_sql = "";
    }else{
         $number = absint($number); //Positive integer
         $limit_sql = "LIMIT $offset, $number";
    }

    /* Filter SQL */
    $pieces = array( 'select_sql', 'join_sql', 'where_sql', 'order_sql', 'limit_sql' );
    $clauses = apply_filters( 'wptuts_logs_clauses', compact( $pieces ), $query );
    foreach ( $pieces as $piece )
          $$piece = isset( $clauses[ $piece ] ) ? $clauses[ $piece ] : '';

    /* Form SQL statement */
    $sql = "$select_sql $where_sql $order_sql $limit_sql";
    if( 'count' == $fields ){
        return $wpdb->get_var($sql);
    }

    /* Perform query */
    $logs = $wpdb->get_results($sql);

    /* Add to cache and filter */
    wp_cache_add( $cache_key, $logs, 24*60*60 );
    $logs = apply_filters('wptuts_get_logs', $logs, $query);

    return $logs;
 }


/**
 * Deletes an activity log from the database
 *
 *@param $log_id int ID of the activity log to be deleted
 *@return bool Whether the log was successfully deleted.
*/
function wptuts_delete_log( $log_id ){
    global $wpdb;

    //Log ID must be positive integer
    $log_id = absint($log_id);

    if( empty($log_id) )
         return false;

    do_action('wptuts_delete_log',$log_id);

    $sql = $wpdb->prepare("DELETE from {$wpdb->wptuts_activity_log} WHERE log_id = %d", $log_id);

    if( !$wpdb->query( $sql ) )
         return false;

    do_action('wptuts_deleted_log',$log_id);

    return true;
}

