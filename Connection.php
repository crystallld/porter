<?php
namespace porter;

use Closure;
use Exception;

class Connection
{
    /**
     * @var array 请求的节点
     */
    public $nodes = [];

    public $url;

    public $method = 'GET';
    /**
     * @var array post body
     */
    public $params = [];
    /**
     * @var array url 扩展参数
     */
    public $options = [];

    public $connectTimeout = 30;

    public $timeout = 30;
    /**
     * @var array|closure
     */
    public $headers;
    /**
     * @var array|closure
     */
    public $resolves = [];
    /**
     * @var array|closure
     */
    public $cookies = [];
    /**
     * @var closure
     */
    public $callback;

    protected $multiple = false;

    protected $asArray = true;

    protected $contentTypeJson = true;

    public function __construct($configs = [])
    {
        if (!empty($configs)) {
            if (is_string($configs)) {
                $this->url = $configs;
            }else if (is_array($configs)) {
                $index = key($configs);
                if (is_numeric($index)) {
                    if (count($configs) == 1) {
                        $this->fill(reset($configs));
                    }else {
                        $this->nodes = $configs;
                    }
                }else if (is_string($index)) {
                    $this->fill($configs);
                }
            }
        }

        $this->init();
    }

    /**
     * @param array $data
     * @param bool $fix 是否强制填充
     */
    public function fill(array $data = [], $fix = true)
    {
        if (empty($data)) return;

        foreach ($data as $property => $value) {
            if (!property_exists($this, $property)) continue;

            if (!$fix && !is_null($this->$property)) continue;

            $this->$property = $value;
        }
    }

    public function init()
    {
        if (!empty($nodes = $this->nodes)) {
            if (count($nodes) > 1) {
                foreach ($nodes as $node) {
                    if (empty($node['url'])) abort('url can not empty.');
                }
                $this->multiple = true;
            }else {
                $this->fill(reset($nodes), false);
                $this->multiple = false;
            }
        }

        if (!$this->multiple) {
            if (empty($this->url)) abort('url can not empty.');
        }
    }

    public function handle()
    {
        if ($this->multiple) {
            $result = $this->multiple();
        }else {
            $result = $this->single();
            if (!empty($result)) {
                $result = [$result];
            }
        }

        return $result;
    }

    public function single()
    {
        $ch = curl_init();

        $options = $this->options;
        if ($this->method == 'GET') {
            $options = array_merge($options, $this->params);
        }

        $url = $this->createUrl($this->url, $options);
        curl_setopt($ch, CURLOPT_URL, $url);

        $curlOptions = $this->initCurlOptions();

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);

        $code = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($code > 0) throw new Exception($error, $code);

        return $this->resultInternal($response);
    }

    public function multiple()
    {
        $conn = [];
        $mh = curl_multi_init();
        $nodes = $this->nodes;
        foreach ($nodes as $k => $config) {
            $conn[$k] = curl_init();  //初始化各个子连接
            //设置url和相应的选项
            $url = $this->createUrl($config['url'], $config['options'] ?? []);
            $options = $this->initCurlOptions($url, $config['params'] ?? null);

            curl_setopt($conn[$k], CURLOPT_URL, $url);
            curl_setopt_array($conn[$k], $options);
            curl_multi_add_handle($mh, $conn[$k]);
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);


        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }

        $result = [];
        foreach ($nodes as $k => $node) {
            $content = curl_multi_getcontent($conn[$k]);
            $result[$k] = $this->resultInternal($content);
            //移除curl批处理句柄资源中的某一个句柄资源
            curl_multi_remove_handle($mh, $conn[$k]);
            //关闭curl会话
            curl_close($conn[$k]);
        }
        //关闭全部句柄
        curl_multi_close($mh);

        return $result;
    }

    protected function resultInternal($result, $callback = null)
    {
        $callback = $callback?? $this->callback;
        if (!$this->asArray && empty($callback)) return $result;

        if (strpos($result, '<?xml') === 0) {
            //禁止引用外部xml实体
            libxml_disable_entity_loader(true);
            $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);
            $result = json_decode(json_encode($xml), true);
        }else if (strpos($result, '<?html') === 0) {
            $result = null;
        }else if (strpos($result, '{') === 0) {
            try {
                $data = json_decode($result, true);
                $result = $data;
            }catch (Exception $e) {}
        }

        if ($this->callback instanceof \Closure) {
            return call_user_func($this->callback, $result);
        }

        return $result?? [];
    }

    protected function initCurlOptions($url = null, $params = null)
    {
        $options = [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $this->method,
        ];

        $url = $url?? $this->url;
        if (strpos($url, 'https') === 0) {
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
        }

        if (!empty($resolve = $this->useCallback($this->resolves))) {
            $options[CURLOPT_RESOLVE] = $resolve;
        }

        if (!empty($headers = $this->buildHeaders($this->headers))) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        if (!empty($cookies = $this->buildCookies($this->cookies))) {
            $options[CURLOPT_COOKIE] = $cookies;
        }

        if (!empty($params = $params?? $this->params)) {
            $options[CURLOPT_POST] = true;

            if ($this->contentTypeJson) {
                if (is_array($params)) {
                    $params = json_encode($params);
                }
            }else {
                if (!is_array($params)) {
                    $params = json_decode($params);
                }
            }

            $options[CURLOPT_POSTFIELDS] = $params;
        }

        return $options;
    }

    protected function buildHeaders($headers)
    {
        if (empty($headers)) return [];

        $headers = $this->useCallback($headers);
        foreach ($headers as $key => &$value) {
            if (!is_numeric($key)) {
                $value = $key.': '.$value;
            }

            if (strpos($value, 'content-type') !== false) {
                $this->contentTypeJson = (strpos($value, 'multipart/form-data') === false);
            }
        }

        return array_values($headers);
    }

    protected function buildCookies($cookies)
    {
        if (empty($cookies)) return null;

        $cookies = $this->useCallback($cookies);
        foreach ($cookies as $key => &$value) {
            if (is_numeric($key)) continue;

            $value = $key.'='.$value;
        }

        return implode(';', $cookies);
    }

    protected function useCallback($method)
    {
        if (!$method instanceof Closure) return $method;

        $ref = new \ReflectionFunction($method);
        $params = $ref->getParameters();

        $data = [];
        foreach ($params as $param) {
            $name = $param->name;

            $data[] = $this->$name?? null;
        }

        return call_user_func($method, ... $data);
    }

    protected function createUrl($url = null, $options = null)
    {
        $url = $url?? $this->url;
        if (is_array($url)) {
            $url = implode(DIRECTORY_SEPARATOR, $url);
        }

        $options = $options?? $this->options;
        if (!empty($options) && is_array($options)) {
            $url .= (str_contains($url, '?')? '&' : '?') . http_build_query($options);
        }

        return $url;
    }
}
