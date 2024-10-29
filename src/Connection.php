<?php

namespace Mak\Driver;

use DateTime;
use DateTimeZone;
use Illuminate\Database\Connection as ConnectionBase;
use RuntimeException;

class Connection extends ConnectionBase
{
    protected function getDefaultQueryGrammar()
    {
        $grammar = app(Grammar::class);
        $grammar->setConfig($this->getConfig());

        return $this->withTablePrefix($grammar);
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if (!$query) {
            return [];
        }

        return $this->run($query, $bindings, function ($query) {
            $maxPerPage = $this->getConfig('default_params')['per_page'];
            $maxUrlLength = $this->getConfig('max_url_length') ?: 4000;

            $fullUrl = $this->getDatabaseName() . $query;

            if (strlen($fullUrl) > $maxUrlLength) {
                $questionIx = strpos($fullUrl, '?');
                if ($questionIx === false) {
                    throw new RuntimeException('Long URLs should have query string');
                }
                $params = Str::parseQuery(substr($fullUrl, $questionIx + 1));

                $keyWithMaxCnt = null;
                $maxCnt = 0;
                foreach ($params as $key => $values) {
                    if (is_array($values)) {
                        $cnt = count($values);
                        if ($cnt > $maxCnt) {
                            $keyWithMaxCnt = $key;
                            $maxCnt = $cnt;
                        }
                    }
                }
                if ($keyWithMaxCnt === null) {
                    throw new RuntimeException('Long URLs should have at least one array in query string');
                }

                $urls = [];
                foreach (array_chunk($params[$keyWithMaxCnt], 200) as $values) {
                    $params[$keyWithMaxCnt] = $values;
                    $urls[] = substr($fullUrl, 0, $questionIx + 1) . Str::httpBuildQuery($params);
                }
            } else {
                $urls = [$fullUrl];
            }

            // Get rows for each partial URL.
            $allRows = [];
            foreach ($urls as $url) {
                // Get data.
                $json = $this->getJsonByUrl($url);
                if (isset($json['current_page'])) {
                    // There is pagination. We expect to receive data objects in the 'data' property.
                    foreach ($json['data'] as $row) {
                        $allRows[] = $row;
                    }

                    // If the URL does not have the 'page' parameter, get data from all the pages.
                    if (count($json['data']) >= $maxPerPage && !preg_match('#(\?|&)page=\d+#', $url)) {
                        $page = $json['current_page'];
                        $hasQueryString = (strpos($url, '?') !== false);
                        while (count($json['data']) >= $maxPerPage) {
                            $page++;
                            $nextUrl = $url . ($hasQueryString ? '&' : '?') . "page=$page";
                            $json = $this->getJsonByUrl($nextUrl);
                            foreach ($json['data'] as $row) {
                                $allRows[] = $row;
                            }
                        }
                    }
                } else {
                    // No pagination.
                    foreach ($json as $row) {
                        $allRows[] = $row;
                    }
                }
            }
            unset($json);

            // Convert timezone in datetime keys.
            $connectionTimezone = $this->getConfig('timezone');
            if ($connectionTimezone && !empty($allRows)) {
                $appTimezone = config('app.timezone');
                if ($connectionTimezone !== $appTimezone) {
                    $configDatetimeKeys = $this->getConfig('datetime_keys');
                    if (!empty($configDatetimeKeys)) {
                        // Get available datetime keys.
                        $datetimeKeys = [];
                        $firstRow = $allRows[0];
                        foreach ($configDatetimeKeys as $key) {
                            if (array_key_exists($key, $firstRow)) {
                                $datetimeKeys[] = $key;
                            }
                        }
                        if (!empty($datetimeKeys)) {
                            $connDtZone = new DateTimeZone($connectionTimezone);
                            $appDtZone = new DateTimeZone($appTimezone);

                            // Convert timezone for each object.
                            foreach ($allRows as &$pRow) {
                                foreach ($datetimeKeys as $key) {
                                    $connValue = $pRow[$key];

                                    // Check if it is a correct datetime in 'Y-m-d H:i:s' format.
                                    if ($connValue != '' && strlen($connValue) === 19 && $connValue !== '0000-00-00 00:00:00') {
                                        // Convert and save.
                                        $dt = new DateTime($connValue, $connDtZone);
                                        $dt->setTimezone($appDtZone);
                                        $pRow[$key] = $dt->format('Y-m-d H:i:s');
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return $allRows;
        });
    }

    private function getJsonByUrl($url)
    {
        // Get curl handler.
        static $ch = null;
        if ($ch === null) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);
        }

        $headers = $this->getConfig('headers') ?: [];

        $headers[] = "Authorization: Bearer " . request()->cookie('token');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_URL, $url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_POSTFIELDS, null);

        $result = curl_exec($ch);
        if (!$result) {
            throw new RuntimeException("Failed to call $url");
        }

        return json_decode($result);
    }

    public function getPdo()
    {
        return null;
    }
}
