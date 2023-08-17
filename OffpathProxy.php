<?php

class OffpathProxy
{
    protected \OpenSwoole\Http\Server $server;
    protected string $basic_auth;
    protected array $proxies;

    public function __construct(string $host = '0.0.0.0', int $port = 8080)
    {
        // Load config
        $config = json_decode(file_get_contents('config.json'));

        if (!$config || !isset($config->auth))
        {
            exit("config.json file is missing or lacks the auth key");
        }

        $this->basic_auth = 'Basic ' . base64_encode($config->auth);

        // Process proxies
        $this->proxies = [];

        foreach ($config->proxies as $proxy)
        {
            // Skip example proxies if still present in the config
            if (!str_contains($proxy, '0.0.0.0:'))
            {
                // user:pass:host:port
                if (preg_match('#^([^:]+:[^:]+):([^:]+:\d+)$#', $proxy, $match))
                {
                    $this->proxies[] = [$match[2], $match[1]];
                }
                // host:port format
                elseif (preg_match('#^([^:]+:\d+)$#', $proxy, $match))
                {
                    $this->proxies[] = [$match[1]];
                }
                else
                {
                    exit("Could not parse invalid proxy [$proxy]\n");
                }
            }
        }

        $this->server = new \OpenSwoole\Http\Server($host, $port);
        $this->server->on('Request', $this->onRequest(...));
    }

    public function start(): bool
    {
        return $this->server->start();
    }

    protected function onRequest(OpenSwoole\Http\Request $request,  OpenSwoole\Http\Response $response): bool
    {
        // Handle /healhcheck
        if ($request->server['request_uri'] === "/healthcheck")
        {
            return $response->end('alive');
        }

        // Validate authorization
        if (!isset($request->header['authorization']) || $request->header['authorization'] !== $this->basic_auth)
        {
            $response->status(401);
            return $response->end('unauthorized');
        }

        // Process a regular request
        if (preg_match('#^/(\w+)/(.+)$#', $request->server['request_uri'], $match))
        {
            // Build the roblox URL
            $roblox_url = sprintf("https://%s.roblox.com/%s?%s", $match[1], $match[2], $request->server['query_string']);

            // Build the curl handle
            $ch = curl_init($roblox_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Select a random proxy if we have any
            if (count($this->proxies))
            {
                $proxy = $this->proxies[random_int(0, count($this->proxies)-1)];
                curl_setopt($ch, CURLOPT_PROXY, $proxy[0]);

                // Set user:auth if present
                if (isset($proxy[1]))
                {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy[1]);
                }
            }

            // Execute the request
            $curl_result = curl_exec($ch);

            // Mirror the content-type if we got one
            if ($content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE))
            {
                $response->header('content-type', $content_type);
            }

            // Mirror the response code
            $response->status(curl_getinfo($ch, CURLINFO_HTTP_CODE));

            // Send the response to the client
            return $response->end($curl_result);
        }
        else
        {
            $response->status(404);
            return $response->end('error');
        }
    }
}

$proxy = new OffpathProxy();
$proxy->start();
