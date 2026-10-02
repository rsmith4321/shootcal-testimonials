<?php
use ShootCalTestimonials\{Form,Meta,Assets,Shortcode,Config,Editor};
if ( ! defined('WP_CLI') || ! WP_CLI || ! in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost','127.0.0.1','::1'], true) ) { throw new RuntimeException('Run only in disposable localhost QA.'); }
function check($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
$made=[];
try{
 $quote="Literal \\ path, client's apostrophe — line one.\n\nLine two with more than twenty characters.";
 $p=wp_insert_post(wp_slash(['post_type'=>'sct_testimonial','post_status'=>'publish','post_title'=>'Regression Example','post_content'=>$quote]));$made[]=$p;
 $second=wp_insert_post(['post_type'=>'sct_testimonial','post_status'=>'publish','post_title'=>'Regression Unrated','post_content'=>'Another complete test review.']);$made[]=$second;
 $html=(new Shortcode)->render(['count'=>'60','more'=>'show','total'=>'60']);
 check(substr_count($html,'data-sct-card')<=60,'render ceiling respected');
 $a=(new Shortcode)->render(['count'=>'60']);$b=(new Shortcode)->render(['count'=>'60']);preg_match_all('/ id="([^"]+)"/',$a.$b,$ids);check(count($ids[1])===count(array_unique($ids[1])),'dialog IDs unique across separate renderers');
 check(str_contains($a,esc_html($quote)),'literal quote punctuation backslashes and paragraphs preserved');
 $sorted=(new Shortcode)->render(['orderby'=>'rating','count'=>'60']);check(str_contains($sorted,'Regression Unrated'),'rating sort includes unrated reviews');
 $form=new Form;$method=new ReflectionMethod(Form::class,'posted_rating');
 foreach(['-5','3.2','3abc','7'] as $value){$_POST[Form::FIELD_RATING]=$value;check($method->invoke($form)===0,'rejects invalid score '.$value);}
 $_POST[Form::FIELD_RATING]='5';check($method->invoke($form)===5,'accepts valid score');
 $insert=new ReflectionMethod(Form::class,'insert');$new=$insert->invoke($form,'Regression Submitter',$quote,0,'','private@example.test');$made[]=$new;
 check(get_post($new)->post_content===$quote,'public submission stores exact sanitized wording');check(get_post_status($new)==='pending','public submissions remain pending');
 wp_set_current_user(0);$req=new WP_REST_Request('GET','/wp/v2/sct_testimonial/'.$p);$res=rest_do_request($req);check($res->get_status()===200,'anonymous REST reads published review');
 update_post_meta($p,'sct_consent_note','PRIVATE TEST NOTE');update_post_meta($p,'sct_source_note','PRIVATE RESEARCH');update_post_meta($p,Form::EMAIL_META_KEY,'private@example.test');
 $res=rest_do_request($req);$meta=$res->get_data()['meta']??[];check(!array_intersect_key($meta,array_flip(['sct_consent_note','sct_source_note',Form::EMAIL_META_KEY])),'private notes and email absent from anonymous REST');
 $pending=rest_do_request(new WP_REST_Request('GET','/wp/v2/sct_testimonial/'.$new));check($pending->get_status()!==200,'anonymous cannot read pending review');
 $admin=get_user_by('login','qa');wp_set_current_user($admin->ID);
 $req=new WP_REST_Request('POST','/wp/v2/sct_testimonial/'.$p);$req->set_param('meta',['sct_rating'=>4,'sct_source'=>'google','sct_source_url'=>'https://example.test/review']);$res=rest_do_request($req);check($res->get_status()===200&&get_post_meta($p,'sct_rating',true)==4,'editor REST updates rating and source');
 $req=new WP_REST_Request('GET','/wp/v2/sct_testimonial/'.$p);$req->set_param('context','edit');$res=rest_do_request($req);check(($res->get_data()['meta']['sct_consent_note']??'')==='PRIVATE TEST NOTE','private annotations available in authorized edit context');
 wp_set_current_user(0);$_GET['sct_category']=['malformed'];check(is_string((new Shortcode)->render(['allow_query'=>'on'])),'array query parameter does not crash');unset($_GET['sct_category']);
 $assets=new Assets; $assets->note_usage([get_post($p)],false);$assets->enqueue();check(wp_script_is('shootcal-testimonials','enqueued'),'dialog script loads without View more');
 $validate=new ReflectionMethod(Form::class,'validate');$spamForm=new Form;$validate->invoke($spamForm,'Example Reviewer','Unrelated promotional content https://example.test/spam', '',0,'');$errors=new ReflectionProperty(Form::class,'errors');check(isset($errors->getValue($spamForm)['quote']),'promotional web links rejected without creating a post');
 $compat=new ShootCalTestimonials\Compatibility;$compat->register();$flag=new ReflectionProperty(ShootCalTestimonials\Compatibility::class,'purge_pending');update_post_meta($p,'sct_review_title','Changed title');check($flag->getValue($compat),'published metadata schedules cache refresh');$compat->flush_pending();check(!$flag->getValue($compat),'cache refresh flushes once');wp_delete_post($second,true);check($flag->getValue($compat),'published deletion schedules cache refresh');
 check(post_type_supports('sct_testimonial','custom-fields'),'REST metadata editable through core');
}catch(Throwable $e){fwrite(STDERR,"FAIL ".$e->getMessage()."\n");exit(1);}finally{foreach($made as $id)wp_delete_post($id,true);$_POST=[];}
