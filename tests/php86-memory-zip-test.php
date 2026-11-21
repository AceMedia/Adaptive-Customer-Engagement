<?php
/** Offline native ZIP packaging. No credentials, AWS calls, database, mail or cron. */
define('ABSPATH', __DIR__ . '/');
$root = sys_get_temp_dir() . '/ace-memory-zip-' . bin2hex(random_bytes(6));
mkdir($root . '/assets/lambda', 0700, true);
define('ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_DIR', $root . '/');
class WP_Error { private $code; public function __construct($code, ...$unused) {$this->code=$code;} public function get_error_code(){return $this->code;} }
function __($value, $domain='') {return $value;}
function wp_json_encode($value) {return json_encode($value);}
$temps=0; $tempFailure=false;
function wp_tempnam($name) {global $temps,$root,$tempFailure; ++$temps; return $tempFailure ? false : tempnam($root,'zip-');}
require dirname(__DIR__) . '/includes/AmazonConnect/Client.php';
$client = new ACE\AdaptiveCustomerEngagement\AmazonConnect\Client();
$method = new ReflectionMethod($client, 'build_site_context_lambda_zip_base64');
if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$checks=0;
function zip_check($ok,$label) {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS ',$label,"\n";}
$template="def handler(event, context):\n    return {'ok': True}\n";
$path=$root.'/assets/lambda/lex_site_context_runtime.py';file_put_contents($path,$template);
$nativeMemory=method_exists('ZipArchive','openString')&&method_exists('ZipArchive','closeString');
try {
 foreach([[],['text'=>'Café 🐑'],['text'=>str_repeat('x',1024*1024)]] as $case){
  $before=$temps;$encoded=$method->invoke($client,$case);
  zip_check(is_string($encoded),'packager returns base64 string');
  $bytes=base64_decode($encoded,true);zip_check(is_string($bytes)&&$bytes!=='','strict base64 decodes a non-empty archive');
  $expectedTemps=$nativeMemory&&strlen($template)+strlen(json_encode($case))<=1024*1024?0:1;
  zip_check($temps-$before===$expectedTemps,'small native archives avoid temporary files; older and larger archives use fallback');
  $archive=$root.'/verify.zip';file_put_contents($archive,$bytes);$zip=new ZipArchive();
  zip_check($zip->open($archive,ZipArchive::RDONLY)===true&&$zip->numFiles===2,'archive opens with exactly two entries');
  zip_check($zip->getFromName('lambda_function.py')===$template&&$zip->getFromName('site_context_snapshot.json')===json_encode($case),'both archive entries preserve exact template and snapshot bytes');
  $zip->close();unlink($archive);
  zip_check(glob($root.'/zip-*')===[],'packager removes fallback temporary files');
 }
 $before=$temps;$error=$method->invoke($client,['bad'=>"\xB1"]);
 zip_check($error instanceof WP_Error&&$error->get_error_code()==='ace_connect_lambda_snapshot_empty'&&$temps===$before,'invalid JSON fails before creating any temporary archive');
 $tempFailure=true;$result=$method->invoke($client,[]);
 zip_check($nativeMemory?is_string($result):($result instanceof WP_Error&&$result->get_error_code()==='ace_connect_lambda_zip_temp_missing'),'small native package works without a writable temporary directory; old runtime fails safely');
 $result=$method->invoke($client,['text'=>str_repeat('x',1024*1024)]);
 zip_check($result instanceof WP_Error&&$result->get_error_code()==='ace_connect_lambda_zip_temp_missing','large payload retains bounded-memory disk path and safe failure');
 unlink($path);$error=$method->invoke($client,[]);
 zip_check($error instanceof WP_Error&&$error->get_error_code()==='ace_connect_lambda_template_missing','missing bundled template retains existing error contract');
 echo "Completed $checks ZIP checks on PHP ",PHP_VERSION,"\n";
} finally {
 foreach(glob($root.'/*') as $p)if(is_file($p))unlink($p);
 if(is_file($path))unlink($path);rmdir($root.'/assets/lambda');rmdir($root.'/assets');rmdir($root);
}
