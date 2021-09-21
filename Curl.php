<?php
namespace porter;

use App\Exceptions\MethodException;

class Curl
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

    public $connectTimeout = 10;

    public $timeout = 10;

    public $headers = [];

    public $resolves = [];

    public $cookies = [];

    public $callback;

    protected $multiple = false;

    protected $asArray = true;

    const METHOD_ENUM = [
        'GET',
        'POST',
    ];

    /**
     * @param $name
     * @param $args
     * eg [
     * 0 => url,
     * 1 => options,
     * ]
     */
    public static function __callStatic($name, $args)
    {
        if (empty($args)) throw new MemcachedException('url', 26);

        $method = strtoupper($name);
        if (!in_array($method, self::METHOD_ENUM))
            throw new MemcachedException($name, 21);

        $variable = $method == 'GET'? 'options': 'params';
        $data = [
            'method' => $method,
            'url' => array_shift($args),
            $variable => array_shift($args),
            'multiple' => false,
        ];

        $options = array_shift($args);
        if (!is_null($options)) {
            if (is_numeric($options)) {
                $data['timeout'] = intval($options);
            }else if (is_array($options)) {
                if (!empty($options)) {
                    $data = array_merge($options, $data);
                }
            }
        }

        $model = new Connection($data);

        return $model->handle();
    }

    public static function multi($nodes, $options = [])
    {
        ob_start();
        ob_implicit_flush(false);
        try {
            $model = new Connection($nodes);
            if (!empty($options)) {
                $model->fill($options, false);
            }

            $result = $model->handle();
        }catch (\Exception $e) {
            ob_clean();
            throw $e;
        }

        ob_clean();

        return $result;
    }
}
