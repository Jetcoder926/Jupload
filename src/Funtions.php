<?php
namespace Jetcoder\Jupload;




Trait Funtions
{
    static protected $hash = [];

    function parse_attr($value = '') {
        $array = preg_split('/[,;\r\n]+/', trim($value, ",;\r\n"));
        if (strpos($value, ':')) {
            $value  = array();
            foreach ($array as $val) {
                list($k, $v) = explode(':', $val);
                $value[$k]   = $v;
            }
        } else {
            $value = $array;
        }
        return $value;
    }

    function hash($filename,$type = 'sha1')
    {
        if (!isset($this->hash[$type])) {
            self::$hash[$type] = hash_file($type, $filename);
        }

        return self::$hash[$type];
    }

    function ck_js($callback = '', $file_path = '', $error_msg = '')
    {
        return "<script type='text/javascript'>window.parent.CKEDITOR.tools.callFunction($callback, '$file_path' , '$error_msg');</script>";
    }

    static function message(string $msg,array $data,string $state)
    {
        return response()->json(['msg'=>$msg,'data'=>$data,'state'=>$state]);
    }
}
