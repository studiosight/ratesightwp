<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Post { public int $ID=42; public string $post_type='post'; public string $post_date_gmt='2026-09-09 01:00:00'; public string $post_modified_gmt='2026-09-09 01:00:00'; public string $post_content='A useful article for local customers.'; }
class Ratesight_Options { public static function get(string $key){return '2';} }
class Ratesight_Pairing { public static function is_connected():bool{return true;} }
class Ratesight_Request_Auth { public static function signed_headers(string $secret,string $method,string $route,array $query=[],string $body=''):array{return ['X-Ratesight-Signature'=>'fixture'];} }

$options=['ratesight_webhook_secret'=>'a-secret'];$meta=[];$scheduled=[];$requests=[];
class FakeWpdb {
  public string $prefix='wp_'; public array $rows=[];
  public function prepare($sql,...$args){return ['sql'=>$sql,'args'=>$args];}
  public function query($query){
    if(is_array($query)&&str_contains($query['sql'],'INSERT IGNORE')){$event=$query['args'][0];if(!isset($this->rows[$event]))$this->rows[$event]=['event_id'=>$event,'body'=>$query['args'][1],'attempts'=>0,'queued_at'=>'2026-09-09 01:00:00','next_attempt_at'=>'2026-09-09 01:00:00'];}
    if(is_array($query)&&str_contains($query['sql'],'UPDATE')){$event=$query['args'][3];$this->rows[$event]['attempts']=$query['args'][0];}
    return 1;
  }
  public function get_results($sql,$format){return array_values($this->rows);}
  public function get_row($sql,$format){return ['pending'=>count($this->rows),'oldest'=>$this->rows?reset($this->rows)['queued_at']:null];}
  public function delete($table,$where,$format){unset($this->rows[$where['event_id']]);return 1;}
}
$wpdb=new FakeWpdb();
function get_option($key,$default=false){global $options;return $options[$key]??$default;}
function update_option($key,$value,$autoload=null){global $options;$options[$key]=$value;return true;}
function get_post_meta($id,$key,$single=true){global $meta;return $meta[$id][$key]??'';}
function update_post_meta($id,$key,$value){global $meta;$meta[$id][$key]=$value;return true;}
function wp_is_post_revision($id){return false;} function wp_is_post_autosave($id){return false;}
function get_permalink($post){return 'https://example.test/new-post/';} function get_the_title($post){return 'New post';}
function has_excerpt($post){return false;} function get_the_excerpt($post){return '';}
function wp_trim_words($text,$count,$more=''){return $text;} function wp_strip_all_tags($text,$remove=false){return strip_tags($text);}
function get_the_post_thumbnail_url($post,$size){return false;} function wp_html_excerpt($text,$count,$more=''){return substr($text,0,$count);}
function home_url($path='/'){return 'https://example.test/';} function untrailingslashit($value){return rtrim($value,'/');}
function esc_url_raw($value){return $value;} function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_schedule_single_event($time,$hook,$args=[]){global $scheduled;$scheduled[]=[$hook,$args];return true;}
function apply_filters($hook,$value){return $value;} function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower($value));}
function wp_remote_post($url,$args){global $requests;$requests[]=[$url,$args];return ['response'=>['code'=>200],'body'=>'{"ok":true,"code":"wordpress_publication_event_accepted"}'];}
function is_wp_error($value){return false;} function wp_remote_retrieve_response_code($response){return $response['response']['code'];}
function wp_remote_retrieve_body($response){return $response['body'];}

require_once __DIR__ . '/../includes/class-ratesight-publication-events.php';

$failures=0;
function check($condition,$label){global $failures;echo ($condition?'ok':'FAIL')."     {$label}\n";if(!$condition)$failures++;}
$post=new WP_Post();
Ratesight_Publication_Events::on_transition('publish','draft',$post);
$status=Ratesight_Publication_Events::status();
check($status['pending']===1,'manual or scheduled transition queues exactly one event');
$payload=json_decode(reset($wpdb->rows)['body'],true);
check($payload['eventType']==='post.published'&&$payload['oid']==='2'&&$payload['postId']===42,'queued event carries the signed dashboard contract identity');
check(preg_match('/^wpevt:v1:2:42:[0-9a-f]{24}$/',$payload['eventId'])===1,'event id is stable and bounded');
Ratesight_Publication_Events::on_transition('publish','publish',$post);
check(Ratesight_Publication_Events::status()['pending']===1,'ordinary edits to an already-published post do not queue');
Ratesight_Publication_Events::drain();
check(Ratesight_Publication_Events::status()['pending']===0,'typed dashboard acknowledgment removes the durable outbox row');
check(count($requests)===1&&str_ends_with($requests[0][0],Ratesight_Publication_Events::ENDPOINT_ROUTE),'delivery targets the dashboard publication endpoint');
Ratesight_Publication_Events::on_transition('publish','trash',$post);
check(Ratesight_Publication_Events::status()['pending']===1&&get_post_meta(42,Ratesight_Publication_Events::SEQUENCE_META,true)===2,'restore into publish creates the next publication sequence');

echo $failures?"FAIL — {$failures} publication event checks\n":"ALL PUBLICATION EVENT CHECKS PASSED\n";
exit($failures?1:0);
