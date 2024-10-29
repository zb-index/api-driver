<?php

namespace Mak\Driver;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar as GrammarBase;
use RuntimeException;

class Grammar extends GrammarBase
{
    private $config = [];

    /**
     * @param array $config
     * @return Grammar
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @param Builder $query
     * @return string
     */
    public function compileSelect(Builder $query): string
    {
        $params = $this->config['default_params'] ?? [];

        foreach ($query->wheres as $where) {
            $key = $where['column'];
            $dotIx = strrpos($key, '.');
            if ($dotIx !== false) {
                $key = substr($key, $dotIx + 1);

                if ($where['type'] === 'Basic') {
                    $where['type'] = 'In';
                    $where['values'] = [$where['value']];
                    unset($where['value']);
                }
            }

            switch ($where['type']) {
                case 'Basic':
                    $param = match ($where['operator']) {
                        '=' => $key,
                        '>=' => "min_$key",
                        '<=' => "max_$key",
                        default => throw new RuntimeException('Unsupported query where operator ' . $where['operator']),
                    };
                    $params[$param] = $where['value'];
                    break;

                case 'In':
                case 'InRaw':
                    $params[$key] = $where['values'];
                    break;

                case 'between':
                    $params["min_$key"] = $where['values'][0];
                    $params["max_$key"] = $where['values'][1];
                    break;

                case 'Null':
                case 'NotNull':
                    break;

                default:
                    throw new RuntimeException('Unsupported query where type ' . $where['type']);
            }
        }

        if (!empty($query->orders)) {
            if (count($query->orders) > 1) {
                throw new RuntimeException('API query does not support multiple orders');
            }
            foreach ($query->orders as $order) {
                $params['order_by'] = $order['column'];
                if ($order['direction'] === 'desc') {
                    $params['sort'] = 'desc';
                } else {
                    unset($params['sort']);
                }
            }
        }

        if ($query->limit) {
            if ($query->limit >= $params['per_page']) {
                throw new RuntimeException('Query limit should be less than ' . $params['per_page']);
            }
            $params['per_page'] = $query->limit;
        }

        $url = "/$query->from";

        if (!empty($params)) {
            $url .= '?';
            $queryStr = Str::httpBuildQuery(
                $params,
                !empty($this->config['pluralize_array_query_params']),
                $this->config['pluralize_except'] ?? [],
            );
            if ($queryStr === false) {
                return false;
            }
            $url .= $queryStr;
        }

        return $url;
    }
}
