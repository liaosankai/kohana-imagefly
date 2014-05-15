<?php

defined('SYSPATH') or die('No direct script access.');

/**
 * @package   Modules
 * @category  Imagefly
 * @author    Fady Khalife
 * @uses      Image Module
 */
class ImageFly {

    /**
     * @var  array       This modules config options
     */
    protected $config = NULL;

    /**
     * @var  string      Stores the path to the cache directory which is either whats set in the config "cache_dir"
     *                   or processed sub directories when the "mimic_source_dir" config option id set to TRUE
     */
    protected $cache_dir = NULL;

    /**
     * @var  object      Kohana image instance
     */
    protected $image = NULL;

    /**
     * @var  boolean     A flag for weither we should serve the default or cached image
     */
    protected $serve_default = FALSE;

    /**
     * @var  string      The source filepath and filename
     */
    protected $source_file = NULL;

    /**
     * @var  array       Stores the URL params in the following format
     */
    protected $url_params = array(
        'w' => NULL, // Width (int)
        'h' => NULL, // Height (int)
        'c' => FALSE, // Crop (bool)
        'q' => NULL   // Quality (int)
    );

    /**
     * @var  string      Last modified Unix timestamp of the source file
     */
    protected $source_modified = NULL;

    /**
     * @var  string      The cached filename with path ($this->cache_dir)
     */
    protected $cached_file = NULL;

    /**
     * Constructorbot
     */
    public function __construct()
    {
        // Prevent unnecessary warnings on servers that are set to display E_STRICT errors, these will damage the image data.
        error_reporting(error_reporting() & ~E_STRICT);

        // Set the config
        $this->config = Kohana::$config->load('imagefly');

        // Try to create the cache directory if it does not exist
        $this->_create_cache_dir();

        // Parse and set the image modify params
        $this->_set_params();

        // Set the source file modified timestamp
        $this->source_modified = filemtime($this->source_file);

        // Try to create the mimic directory structure if required
        $this->_create_mimic_cache_dir();

        // Set the cached filepath with filename
        $this->cached_file = $this->cache_dir . $this->_encoded_filename();

        // Create a modified cache file if required
        if (!$this->_cached_exists() AND $this->_cached_required()) {
            $this->_create_cached();
        }

        // Serve the image file
        $this->_serve_file();
    }

    /**
     * Try to create the config cache dir if required
     * Set $cache_dir
     */
    private function _create_cache_dir()
    {
        if (!file_exists($this->config['cache_dir'])) {
            try {
                mkdir($this->config['cache_dir'], 0755, TRUE);
            } catch (Exception $e) {
                throw new Kohana_Exception($e);
            }
        }

        // Set the cache dir
        $this->cache_dir = $this->config['cache_dir'];
    }

    /**
     * Try to create the mimic cache dir from the source path if required
     * Set $cache_dir
     */
    private function _create_mimic_cache_dir()
    {
        if ($this->config['mimic_source_dir']) {
            // Get the dir from the source file
            $source_file = str_replace($this->config['cache_dir'], "", $this->source_file);
            $mimic_dir = $this->config['cache_dir'] . pathinfo($source_file, PATHINFO_DIRNAME);
            // Try to create if it does not exist
            if (!file_exists($mimic_dir)) {
                try {
                    mkdir($mimic_dir, 0755, TRUE);
                } catch (Exception $e) {
                    throw new Kohana_Exception($e);
                }
            }

            // Set the cache dir, with trailling slash
            $this->cache_dir = $mimic_dir . DIRECTORY_SEPARATOR;
        }
    }

    /**
     * Sets the operations params from the url
     */
    private function _set_params()
    {
        // Get values from request
        $params = Request::current()->param('params');
        $filepath = Request::current()->param('imagepath');
        $query = Request::current()->query();

        // If enforcing params, ensure it's a match
        if ($this->config['enforce_presets'] AND ! in_array($params, $this->config['presets']))
            throw new HTTP_Exception_404('The requested URL :uri was not found on this server.', array(':uri' => Request::$current->uri()));

        // Support non-ASCII path
        if ($this->config['charset'] != '') {
            $filepath = iconv("UTF-8", "{$this->config['charset']}//IGNORE", $filepath);
        }

        // 修復 http:// 被轉成 http:/ 的問題												
        $filepath = str_replace(':/', '://', $filepath);

        // 分析檔案路徑是否以 http 或 https 開頭
        $parse_url = parse_url($filepath);
        if (isset($parse_url["scheme"]) && in_array($parse_url["scheme"], array("http", "https"))) {
            // 重構完整的 URL 路徑
            $parse_url["query"] = http_build_query($query);
            $filepath = http_build_url($parse_url);
            // 建立暫存圖檔
            $tempdir = $this->config['cache_dir'] . $parse_url["host"] . DIRECTORY_SEPARATOR;
            if (!is_dir($tempdir)) {
                mkdir($tempdir, 0755, TRUE);
            }
            $tempfile = $tempdir . md5($filepath);
            // 檢查暫存圖檔是否已存在
            if (!file_exists($tempfile)) {
                $ch = curl_init($filepath);
                $fp = fopen($tempfile, 'wb');
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_exec($ch);
                curl_close($ch);
                fclose($fp);
            }
            $filepath = $tempfile;
        }
        $this->image = Image::factory($filepath);

        // The parameters are separated by hyphens
        $raw_params = explode('-', $params);

        // Update param values from passed values
        foreach ($raw_params as $raw_param) {
            $name = $raw_param[0];
            $value = substr($raw_param, 1, strlen($raw_param) - 1);

            if ($name == 'c') {
                $this->url_params[$name] = TRUE;

                // When croping, we must have a width and height to pass to imagecreatetruecolor method
                // Make width the height or vice versa if either is not passed
                if (empty($this->url_params['w'])) {
                    $this->url_params['w'] = $this->url_params['h'];
                }
                if (empty($this->url_params['h'])) {
                    $this->url_params['h'] = $this->url_params['w'];
                }
            } elseif (key_exists($name, $this->url_params)) {
                // Remaining expected params (w, h, q)
                $this->url_params[$name] = $value;
            } else {
                // Watermarks or invalid params
                $this->url_params[$raw_param] = $raw_param;
            }
        }

        //Do not scale up images
        if (!$this->config['scale_up']) {
            if ($this->url_params['w'] > $this->image->width)
                $this->url_params['w'] = $this->image->width;
            if ($this->url_params['h'] > $this->image->height)
                $this->url_params['h'] = $this->image->height;
        }

        // Must have at least a width or height
        if (empty($this->url_params['w']) AND empty($this->url_params['h'])) {
            throw new HTTP_Exception_404('The requested URL :uri was not found on this server.', array(':uri' => Request::$current->uri()));
        }

        // Set the url filepath
        $this->source_file = $filepath;
    }

    /**
     * Checks if a physical version of the cached image exists
     * 
     * @return boolean
     */
    private function _cached_exists()
    {
        return file_exists($this->cached_file);
    }

    /**
     * Checks that the param dimensions are are lower then current image dimensions
     * 
     * @return boolean
     */
    private function _cached_required()
    {
        $image_info = getimagesize($this->source_file);

        if (($this->url_params['w'] == $image_info[0]) AND ( $this->url_params['h'] == $image_info[1])) {
            $this->serve_default = TRUE;
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Returns a hash of the filepath and params plus last modified of source to be used as a unique filename
     * 
     * @return  string
     */
    private function _encoded_filename()
    {
        $ext = File::ext_by_mime(Arr::get(getimagesize($this->source_file), "mime"));

        // 這邊取得可能是 jpe，但對 Image 會判斷不支援這種類型(這是 bug，已回報官方 #4836)
        // 但避免官方不更新，所以這邊自己先做個轉換
        $ext = ($ext == 'jpe') ? 'jpg' : 'jpg';

        $encode = md5($this->source_file . http_build_query($this->url_params));

        // Build the parts of the filename
        $encoded_name = $encode . '-' . $this->source_modified . '.' . $ext;

        return $encoded_name;
    }

    /**
     * Creates a cached cropped/resized version of the file
     */
    private function _create_cached()
    {
        if ($this->url_params['c']) {
            // Resize to highest width or height with overflow on the larger side
            $this->image->resize($this->url_params['w'], $this->url_params['h'], Image::INVERSE);

            // Crop any overflow from the larger side
            $this->image->crop($this->url_params['w'], $this->url_params['h']);
        } else {
            // Just Resize
            $this->image->resize($this->url_params['w'], $this->url_params['h']);
        }

        // Apply any valid watermark params
        $watermarks = Arr::get($this->config, 'watermarks');
        if (!empty($watermarks)) {
            foreach ($watermarks as $key => $watermark) {
                if (key_exists($key, $this->url_params)) {
                    $image = Image::factory($watermark['image']);
                    $this->image->watermark($image, $watermark['offset_x'], $watermark['offset_y'], $watermark['opacity']);
                }
            }
        }

        // Save
        if ($this->url_params['q']) {
            //Save image with quality param
            $this->image->save($this->cached_file, $this->url_params['q']);
        } else {
            //Save image with default quality
            $this->image->save($this->cached_file, Arr::get($this->config, 'quality', 80));
        }
    }

    /**
     * Create the image HTTP headers
     * 
     * @param  string     path to the file to server (either default or cached version)
     */
    private function _create_headers($file_data)
    {
        // Create the required header vars
        $last_modified = gmdate('D, d M Y H:i:s', filemtime($file_data)) . ' GMT';
        $content_type = File::mime($file_data);
        $content_length = filesize($file_data);
        $expires = gmdate('D, d M Y H:i:s', (time() + $this->config['cache_expire'])) . ' GMT';
        $max_age = 'max-age=' . $this->config['cache_expire'] . ', public';

        // Some required headers
        header("Last-Modified: $last_modified");
        header("Content-Type: $content_type");
        header("Content-Length: $content_length");

        // How long to hold in the browser cache
        header("Expires: $expires");

        /**
         * Public in the Cache-Control lets proxies know that it is okay to
         * cache this content. If this is being served over HTTPS, there may be
         * sensitive content and therefore should probably not be cached by
         * proxy servers.
         */
        header("Cache-Control: $max_age");

        // Set the 304 Not Modified if required
        $this->_modified_headers($last_modified);

        /**
         * The "Connection: close" header allows us to serve the file and let
         * the browser finish processing the script so we can do extra work
         * without making the user wait. This header must come last or the file
         * size will not properly work for images in the browser's cache
         */
        header("Connection: close");
    }

    /**
     * Rerurns 304 Not Modified HTTP headers if required and exits
     * 
     * @param  string  header formatted date
     */
    private function _modified_headers($last_modified)
    {
        $modified_since = (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) ? stripslashes($_SERVER['HTTP_IF_MODIFIED_SINCE']) : FALSE;

        if (!$modified_since OR $modified_since != $last_modified)
            return;

        // Nothing has changed since their last request - serve a 304 and exit
        header('HTTP/1.1 304 Not Modified');
        header('Connection: close');
        exit();
    }

    /**
     * Decide which filesource we are using and serve
     */
    private function _serve_file()
    {
        // Set either the source or cache file as our datasource
        if ($this->serve_default) {
            $file_data = $this->source_file;
        } else {
            $file_data = $this->cached_file;
        }

        // Output the file
        $this->_output_file($file_data);
    }

    /**
     * Outputs the cached image file and exits
     * 
     * @param  string     path to the file to server (either default or cached version)
     */
    private function _output_file($file_data)
    {
        // Create the headers
        $this->_create_headers($file_data);

        // Get the file data
        $data = file_get_contents($file_data);

        // Send the image to the browser in bite-sized chunks
        $chunk_size = 1024 * 8;
        $fp = fopen('php://memory', 'r+b');

        // Process file data
        fwrite($fp, $data);
        rewind($fp);
        while (!feof($fp)) {
            echo fread($fp, $chunk_size);
            flush();
        }
        fclose($fp);

        exit();
    }

}
