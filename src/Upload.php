<?php
namespace Jetcoder\Jupload;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Jetcoder\Jupload\Exceptions\Exception;
use Jetcoder\Jupload\Exceptions\InvalidParamException;
use Illuminate\Support\Facades\Storage;

Class Upload
{
    use Funtions;

    const SUCCESS = 1001;
    const FAIL    = 1002;
    static protected $attachment_table ;

    static protected $storePlace ;

    static protected $storeWay ;
    //缩略图SIZE
    static protected $thumbSize ;
    // KB为单位
    static protected $ImageMaxSize ;
    static protected $FileMaxSize  ;
    //上传文件允许的后缀
    static protected $ImageEXT ;
    static protected $FileEXT ;

    public function __construct()
    {
        self::$attachment_table = config('connections.mysql.prefix').'jupload_attachment';

        self::$storeWay  = config('jupload.store_passage','local');

        self::$storePlace = config('jupload.image_local','storage/');

        self::$ImageEXT = config('jupload.image_size_limit',2 * 1024 );

        self::$FileMaxSize = config('jupload.file_size_limit',50 * 1024 );

        self::$thumbSize = config('jupload.thumb_size_limit','400,300');

        self::$FileEXT   = config('jupload.file_ext','doc,docx,xls,xlsx,ppt,pptx,pdf,wps,txt,rar,zip,gz,bz2,7z');

        self::$ImageEXT  = config('jupload.image_ext','gif,jpg,jpeg,bmp,png');
    }

    /**
     * 上传附件
     * @param string $dir 保存的目录:images,files,videos,voices
     * @param string $from 来源，wangeditor：wangEditor编辑器, ueditor:ueditor编辑器, editormd:editormd编辑器等
     * @param string $module 来自哪个模块
     * @author Allen
     * @return mixed
     */
    public function upload(Request $request)
    {
        // 临时取消执行时间限制
        set_time_limit(0);

        $dir = $request->input('dir','');
        $from = $request->input('from','');
        $module = $request->input('module','admin');

        if ($dir == '') return self::message('没有指定上传目录',[],self::FAIL);

        return $this->saveFile($dir, $from, $module);
    }

    /**
     * 保存附件
     * @param string $dir 附件存放的目录
     * @param string $from 来源
     * @param string $module 来自哪个模块
     * @author Allen

     */
    private function saveFile($dir = '', $from = '', $module = '')
    {

        // 附件大小限制
        $size_limit = $dir == 'images' ? self::$ImageMaxSize : self::$FileMaxSize;
        $size_limit = $size_limit * 1024;
        // 附件类型限制
        $ext_limit = $dir == 'images' ? self::$ImageEXT : self::$FileEXT;
        $ext_limit = $ext_limit != '' ? $this->parse_attr($ext_limit) : '';

        // 获取附件数据
        $callback = '';
        switch ($from) {
            case 'editormd':
                $file_input_name = 'editormd-image-file';
                break;
            case 'ckeditor':
                $file_input_name = 'upload';
                $callback = request()->get('CKEditorFuncNum');
                break;
            default:
                $file_input_name = $from;
                break;
        }
        $file = request()->file($file_input_name);

        // 判断附件是否已存在

        if ($file_exists = DB::table(self::$attachment_table)->where(['md5' => $this->hash($file->getRealPath() ?: $file->getPathname(),'md5')])->first()) {

            $file_path = $file_exists->driver == 'local' ? DIRECTORY_SEPARATOR.$file_exists->path:$file_exists->path;


            switch ($from) {
                case 'ckeditor':
                    return $this->ck_js($callback, $file_path);
                    break;
                default:
                    return self::message('上传成功',["id" => $file_exists->id,"path" => $file_path,"title" => $file_exists->name],self::SUCCESS);
            }
        }

        // 判断附件大小是否超过限制
        if ($size_limit > 0 && ($file->getSize() > $size_limit)) {
            switch ($from) {
                case 'wangeditor':
                    return "error|附件过大";
                    break;
                case 'ckeditor':
                    return $this->ck_js($callback, '', '附件过大');
                    break;
                default:

                    return self::message('附件过大',[],self::FAIL);

                    break;
            }
        }

        // 判断附件格式是否符合

        $file_ext  = $file->extension();

        $error_msg = '';
        if ($ext_limit == '') {
            $error_msg = '获取文件信息失败！';
        }
        if ($file->getClientMimeType() == 'text/x-php' || $file->getClientMimeType() == 'text/html') {
            $error_msg = '禁止上传非法文件！';
        }
        if (preg_grep("/php/i", $ext_limit)) {
            $error_msg = '禁止上传非法文件！';
        }
        if (!preg_grep("/$file_ext/i", $ext_limit)) {
            $error_msg = '附件类型不正确！';
        }

        if ($error_msg != '') {
            switch ($from) {
                case 'wangeditor':
                    return "error|{$error_msg}";
                    break;
                case 'ckeditor':
                    return $this->ck_js($callback, '', $error_msg);
                    break;
                default:
                    return self::message($error_msg ,[],self::FAIL);
            }
        }
        $filename = $this->hash($file->getRealPath() ?: $file->getPathname(),'md5').'.' . $file_ext;


        switch (self::$storeWay)
        {
            case 'local':
                $info = $file->storePubliclyAs(self::$storePlace,$filename);

                $filePath = self::$storePlace.$dir.DIRECTORY_SEPARATOR.$filename;

                break;
            case 'qiniu':

                $info = true;

                $disk = Storage::disk('qiniu');

                $disk->has($filename) === false && $info = $disk->put($filename, file_get_contents($file->getRealPath()));

                $filePath = $disk->getUrl($filename);

                break;
        }


        if($info){

            // 缩略图路径
            $thumb_path_name = '';
            // 生成缩略图
            if ($dir == 'images' && self::$thumbSize != '') {
                list($thumb_max_width, $thumb_max_height) = explode(',', self::$thumbSize);

                $thumb_path_name = $_SERVER['REQUEST_SCHEME'].'://'.env('QINIU_DOMAIN').'/'.$filename.'?imageMogr2/thumbnail/'.$thumb_max_width.'x'.$thumb_max_height;
            }

            // 获取附件信息
            $file_info = [
                'uid'    =>  1,
                'name'   =>  $file->getClientOriginalName(),
                'mime'   =>  $file->getMimeType(),
                'path'   =>  $filePath,
                'ext'    =>  $file_ext,
                'size'   =>  $file->getSize(),
                'md5'    =>  $this->hash($file->getRealPath() ?: $file->getPathname(),'md5'),
                'sha1'   =>  $this->hash($file->getRealPath() ?: $file->getPathname()),
                'thumb'  =>  $thumb_path_name,
                'driver'  => self::$storeWay,
                'module' =>  $module
            ];

            // 写入数据库
            if ($file_insert_id = DB::table(self::$attachment_table)->insertGetId($file_info)) {

                $file_add = DB::table(self::$attachment_table)->where('id','=',$file_insert_id)->first();

                $file_add->driver == 'local'? $file_path = DIRECTORY_SEPARATOR. $file_add->path: $file_path = $file_add['path'];

                switch ($from) {
                    case 'ckeditor':
                        return $this->ck_js($callback, $file_path);
                        break;
                    default:
                        return self::message('上传成功',["title" => $file_info['name'],'id'=> $file_add['id'],'path'=> $file_path],self::SUCCESS);

                }
            } else {
                switch ($from) {
                    case 'wangeditor':
                        return "error|上传失败";
                        break;
                    case 'ckeditor':
                        return $this->ck_js($callback, '', '上传失败');
                        break;
                    default:
                        return self::message('上传失败',[],self::FAIL);

                }
            }
        }else{
            switch ($from) {
                case 'wangeditor':
                    return "error|".$file->getError();
                    break;
                case 'ckeditor':
                    return $this->ck_js($callback, '', $file->getError());
                    break;
                default:
                    return self::message($file->getError(),[],self::FAIL);
            }
        }
    }


}
