<?php
namespace porter;

use Exception;
use Closure;

class Port
{
    /**
     * @var string|array 元数据
     */
    public $meta;
    /**
     * @var string|array n数据
     */
    public $many;

    public $multi = false;

    public $method = 'GET';

    protected $keys;

    protected $nodes;

    protected $connection = Connection::class;

    /**
     * 查询接口 1->n
     * @param string|array $name
     * 1. string: 查询接口数据
     * 2. array: 查询多个接口的数据，并将数据合并成一个数组
     * @param null $params
     * @return mixed
     * @throws Exception
     */
    public static function find($name, $params = null)
    {
        ob_start();
        ob_implicit_flush(false);
        try {
            $model = new static();
            $model->multi = false;
            $model->meta = $params;
            $model->many = $name;

            $result = $model->handle();
        }catch (\Exception $e) {
            ob_clean();
            throw $e;
        }

        ob_clean();

        return $result;
    }

    /**
     * 多个查询数据查询接口，并将数据单独返回 n -> 1
     * @param $name
     * @param null $params
     * @return mixed
     * @throws Exception
     */
    public static function findMany($name, $params = null)
    {
        ob_start();
        ob_implicit_flush(false);
        try {
            $model = new static();
            $model->multi = true;
            $model->meta = $name;
            $model->many = $params;

            $result = $model->handle();
        }catch (\Exception $e) {
            ob_clean();
            throw $e;
        }

        ob_clean();

        return $result;
    }

    public function init()
    {
        if (!empty($this->many)) {
            $this->many = [$this->many];
        }
        $this->many = array_values(array_filter($this->many));

        $this->initConnection();
    }

    public function handle()
    {
        try {
            $this->init();

            $result = $this->connection->handle();
            if (empty($result)) return [];

            return $this->resultInternal($result);
        }catch (Exception $e) {
            ## @TODO 添加日志
            return [];
        }
    }

    protected function initConnection()
    {
        if (empty($connection = $this->connection))
            throw new Exception('connection can not empty.');

        if (is_string($class = $this->connection)) {
            $this->initNodes();
            if (empty($this->nodes)) throw new Exception('nodes can not empty.');

            $this->connection = new $class($this->nodes);
        }
    }

    protected function initNodes()
    {
        $nodes = [];
        foreach ($this->many as $item) {
            $multi = $this->multi;
            $name = $multi? $this->meta: $item;
            $params = $multi? $item: $this->meta;

            $node = $this->initConfig($name, $params);
            if (empty($node)) continue;

            $nodes[] = $node;
        }

        $this->nodes = $nodes;

        return $nodes;
    }

    public function getConfigOptions($name)
    {
        $names = explode('.', $name);
        $count = count($names) - 1;
        $configs = [];
        $fields = ['method', 'options', 'headers', 'cookies'];
        $name = '';
        foreach ($names as $num => $item) {
            $name .= $item;
            $items = config('ports.'.$name);
            if ($num < $count) {
                $items = array_intersect_key($items, array_flip($fields));
            }
            $configs = array_merge($items, $configs);
            if ($num == $count) break;

            $name .= '.';
        }

        return $configs;
    }

    public function initConfig($name, $body = null)
    {
        $configs = $this->getConfigOptions($name);
        $attributes = get_class_vars($this->connection);

        $result = [];
        foreach ($attributes as $attribute => $value) {
            $value = $configs[$attribute]?? $value;

            switch ($attribute) {
                case 'url':
                    if (empty($value)) throw new Exception(__FUNCTION__.':url can not empty.');
                    break;
                case 'method':
                    $value = strtoupper($value?? $this->method);
                    if ($value == 'GET') {
                        $variable = 'options';
                        $hasParam = false;
                    }else {
                        $variable = 'params';
                        $hasParam = true;
                    }
                    break;
            }

            if (!is_numeric($value) && empty($value)) continue;

            $result[$attribute] = $value;
        }

        if (empty($body) && empty($variable)) return $result;
        if (empty($hasParam) && empty($value = $configs[$variable]?? [])) return $result;

        if (is_array($body)) {
            $value = array_merge($value?? [], $body);
        }else {
            $key = array_search(null, $value);
            if (empty($body)) throw new Exception(__METHOD__.':'.$key.' can not empty.');

            $value[$key] = $body;
        }
        $result[$variable] = $value;

        return $result;
    }

    public function resultInternal($data)
    {
        $result = [];
        if (empty($data)) return $result;

        if ($this->multi) {
            $result = array_combine($this->many, $data);
        }else {
            if (count($this->many) > 1) {
                $result = array_merge_recursive(... $data);
            }else {
                $result = reset($data);
            }
        }

        return $result;
    }

    public function collapse($data, $depth = 0, $result = [])
    {
        foreach ($data as $key => $value) {
            $value = $this->optimize($value);

            if ($depth > 0 && is_array($value) && !empty($value)) {
                $result = $this->collapse($value, $depth - 1, $result);
            }else {
                $default = $result[$key]?? null;

                $result[$key] = $this->optimize($value, $default);
            }
        }

        return $result;
    }

    public function optimize($value, $default = null)
    {
        $type = gettype($value);

        switch ($type) {
            case 'array':
                $index = key($value);
                if (is_numeric($index)) {
                    $value = reset($value);
                }
                if (empty($value)) $value = null;
                $default = $default?? [];
                break;
            case 'string':
                $default = $default?? '';
                break;
        }

        if (is_string($value)) {
            $value = trim($value);
            if (empty($value)) $value = null;
        }

        if (!isset($value)) return $default;

        return $value;
    }
}