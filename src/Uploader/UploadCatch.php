<?php
namespace QQun\UEditor\Uploader;

/**
 * Class UploadCatch
 * 图片远程抓取
 *
 * @package QQun\UEditor\Uploader
 */
class UploadCatch extends Upload
{
    use UploadQiniu, UploadUpyun, UploadSCS;

    public function doUpload()
    {

        $imgUrl = strtolower(str_replace("&amp;", "&", $this->config['imgUrl']));
        //http开头验证
        if (strpos($imgUrl, "http") !== 0) {
            $this->stateInfo = $this->getStateInfo("ERROR_HTTP_LINK");
            return false;
        }
        //获取请求头并检测死链
        $heads = get_headers($imgUrl);

        if (!(stristr($heads[0], "200") && stristr($heads[0], "OK"))) {
            $this->stateInfo = $this->getStateInfo("ERROR_DEAD_LINK");
            return false;
        }

        //格式验证(扩展名验证和Content-Type验证)
        $fileType = strtolower(strrchr($imgUrl, '.'));
        if (!in_array($fileType, $this->config['allowFiles'])) {
            $this->stateInfo = $this->getStateInfo("ERROR_HTTP_CONTENTTYPE");
            return false;
        }

        //打开输出缓冲区并获取远程图片
        ob_start();
        $context = stream_context_create(
            array('http' => array(
                'follow_location' => false // don't follow redirects
            ))
        );
        readfile($imgUrl, false, $context);
        $img = ob_get_contents();

        ob_end_clean();

        preg_match("/[\/]([^\/]*)[\.]?[^\.\/]*$/", $imgUrl, $m);


        $this->oriName = $m ? $m[1] : "";
        $this->fileSize = strlen($img);
        $this->fileType = $this->getFileExt();
        $this->fullName = $this->getFullName();
        $this->filePath = $this->getFilePath();
        $this->fileName = basename($this->filePath);
        $dirname = dirname($this->filePath);

        //检查文件大小是否超出限制
        if (!$this->checkSize()) {
            $this->stateInfo = $this->getStateInfo("ERROR_SIZE_EXCEED");
            return false;
        }


        if (config('Ueditor.core.mode') == self::LOCAL_MODEL) {
            //创建目录失败
            if (!file_exists($dirname) && !mkdir($dirname, 0777, true)) {
                $this->stateInfo = $this->getStateInfo("ERROR_CREATE_DIR");
                return false;
            } else if (!is_writeable($dirname)) {
                $this->stateInfo = $this->getStateInfo("ERROR_DIR_NOT_WRITEABLE");
                return false;
            }

            //移动文件
            if (!(file_put_contents($this->filePath, $img) && file_exists($this->filePath))) { //移动失败
                $this->stateInfo = $this->getStateInfo("ERROR_WRITE_CONTENT");
                return false;
            } else { //移动成功
                $this->stateInfo = $this->stateMap[0];
                return true;
            }
        } else if (config('Ueditor.core.mode') == self::QINIU_MODEL) {

            return $this->uploadQiniu($this->filePath, $img);

        } else if (config('Ueditor.core.mode') == self::UPYUN_MODEL) {

            try {
                //本地保存
                file_put_contents($this->filePath, $img);
                return $this->uploadUpyun('/' . dirname($this->filePath) . '/' . $this->fileName, $img);

            } catch (FileException $exception) {
                $this->stateInfo = $this->getStateInfo("ERROR_WRITE_CONTENT");
                return false;
            }

        } else if (config('Ueditor.core.mode') == self::SCS_MODEL) {

            try {
                //本地保存
                file_put_contents($this->filePath, $img);
                return $this->uploadSCS($this->filePath . '/' . $this->fileName, $img);

            } catch (FileException $exception) {
                $this->stateInfo = $this->getStateInfo("ERROR_WRITE_CONTENT");
                return false;
            }

        } else {
            $this->stateInfo = $this->getStateInfo("ERROR_UNKNOWN_MODE");
            return false;
        }


    }

    /**
     * 获取文件扩展名
     * @return string
     */
    protected function getFileExt()
    {
        return strtolower(strrchr($this->oriName, '.'));
    }
}