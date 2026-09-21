<?php
if (!defined('ABSPATH')) exit;
class WFEBPG_Logger {
    public static function log($message,$level='info'){
        $logs=get_option('wfebpg_logs',[]); array_unshift($logs,['time'=>current_time('mysql'),'level'=>$level,'message'=>wp_strip_all_tags($message)]); update_option('wfebpg_logs',array_slice($logs,0,300),false);
    }
    public static function get(){return get_option('wfebpg_logs',[]);}
}
