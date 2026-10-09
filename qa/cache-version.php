<?php
use ShootCalTestimonials\Compatibility;
use const ShootCalTestimonials\VERSION;
if(!defined('WP_CLI')||!WP_CLI||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','shootcal-plugin-dev.local'],true))throw new RuntimeException('Local only');
$key='sct_render_cache_version';$original=get_option($key,false);
try {
 update_option($key,'0.7.1',false);$c=new Compatibility;$flag=new ReflectionProperty(Compatibility::class,'purge_pending');
 $c->check_render_version();if(!$flag->getValue($c))throw new RuntimeException('Update did not schedule purge');
 $c->flush_pending();if(get_option($key)!==VERSION||$flag->getValue($c))throw new RuntimeException('Update was not completed');
 $c->check_render_version();if($flag->getValue($c))throw new RuntimeException('Unchanged version repeatedly purges pages');
 echo "PASS plugin upgrade refreshes rendering pages once, unchanged requests do not purge\n";
}finally{false===$original?delete_option($key):update_option($key,$original,false);}
