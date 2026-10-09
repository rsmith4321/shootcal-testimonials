<?php
use ShootCalTestimonials\Storage_Upgrade;
if ( ! defined('WP_CLI') || ! WP_CLI || 'shootcal-plugin-dev.local' !== wp_parse_url(home_url(), PHP_URL_HOST) ) { throw new RuntimeException('Local QA only.'); }
function shootcal_testimonials_assert($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
global $wpdb;
$original=get_option(Storage_Upgrade::OPTION,false);$made=[];$term=0;
$die=function(){return static function($message){throw new RuntimeException($message);};};
try{
 register_post_type('sct_testimonial');register_taxonomy('sct_category','sct_testimonial');
 $p=wp_insert_post(wp_slash(['post_type'=>'sct_testimonial','post_status'=>'publish','post_title'=>'Upgrade Fixture','post_content'=>"Exact client's quote — slash \\ intact.",'post_date'=>'2024-02-02 12:34:56']));$made[]=$p;
 update_post_meta($p,'sct_rating','5');add_post_meta($p,'sct_source_note','first');add_post_meta($p,'sct_source_note','second');update_post_meta($p,'_sct_submitter_email','private@example.test');update_post_meta($p,'unrelated_key','retain');
 $t=wp_insert_term('Storage Upgrade Fixture','sct_category');$term=$t['term_id'];update_term_meta($term,'sct_icon','ring');wp_set_object_terms($p,[$term],'sct_category');
 $before=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d",$p),ARRAY_A);$before['post_type']=ShootCalTestimonials\POST_TYPE;
 $relationships=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->term_relationships} WHERE object_id=%d",$p),ARRAY_A);
 update_post_meta($p,'shootcal_testimonials_rating','2');delete_option(Storage_Upgrade::OPTION);add_filter('wp_die_handler',$die);
 $failed=false;try{Storage_Upgrade::run();}catch(RuntimeException $e){$failed=true;}remove_filter('wp_die_handler',$die);
 shootcal_testimonials_assert($failed,'conflicting metadata refuses upgrade');
 shootcal_testimonials_assert(get_post_type($p)==='sct_testimonial'&&get_post_meta($p,'sct_rating',true)==='5','failed upgrade preserves original storage');
 delete_post_meta($p,'shootcal_testimonials_rating');Storage_Upgrade::run();
 $after=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d",$p),ARRAY_A);
 shootcal_testimonials_assert($before===$after,'all post fields and ID preserved except intended type');
 shootcal_testimonials_assert(get_post_meta($p,'shootcal_testimonials_rating',true)==='5'&&get_post_meta($p,'shootcal_testimonials_source_note',false)===['first','second'],'metadata values and duplicate rows preserved');
 shootcal_testimonials_assert(get_post_meta($p,'_shootcal_testimonials_submitter_email',true)==='private@example.test'&&get_post_meta($p,'unrelated_key',true)==='retain','private email and unrelated metadata preserved');
 shootcal_testimonials_assert(get_term_meta($term,'shootcal_testimonials_icon',true)==='ring'&&get_term($term,ShootCalTestimonials\TAXONOMY)->term_id===$term,'category identity and icon preserved');
 shootcal_testimonials_assert($relationships===$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->term_relationships} WHERE object_id=%d",$p),ARRAY_A),'category relationship rows untouched');
 Storage_Upgrade::run();shootcal_testimonials_assert($after===$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d",$p),ARRAY_A),'repeat upgrade is inert');
 $_GET=['sct_category'=>'wedding','sct_review_page'=>'2'];Storage_Upgrade::legacy_query_aliases();shootcal_testimonials_assert($_GET['shootcal_testimonials_category']==='wedding'&&$_GET['shootcal_testimonials_review_page']==='2','old category and pagination bookmarks remain readable');
}finally{remove_filter('wp_die_handler',$die);foreach($made as$id)wp_delete_post($id,true);if($term){wp_delete_term($term,ShootCalTestimonials\TAXONOMY);wp_delete_term($term,'sct_category');}update_option(Storage_Upgrade::OPTION,$original,false);$_GET=[];}
