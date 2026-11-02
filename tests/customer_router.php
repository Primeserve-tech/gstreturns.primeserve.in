<?php
// Test-only PHP built-in-server router. Never used by Docker or production entry points.
if(PHP_SAPI!=='cli-server'||getenv('GST_MODE')!=='fixture'||!getenv('GST_TEST_WRITEPATH')){http_response_code(403);exit;}
$public=realpath(__DIR__.'/../public');$path=realpath($public.parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if($path&&str_starts_with($path,$public.DIRECTORY_SEPARATOR)&&is_file($path)&&pathinfo($path,PATHINFO_EXTENSION)!=='php')return false;
define('WRITEPATH',rtrim(getenv('GST_TEST_WRITEPATH'),'/\\').DIRECTORY_SEPARATOR);
require $public.'/index.php';
