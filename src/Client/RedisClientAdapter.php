<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Client;

/**
 * Adapter to normalize different Redis client implementations
 */
class RedisClientAdapter
{
    private $client;
    private string $clientType;

    /**
     * @param object $client Either a \Redis (phpredis) or Predis\Client instance
     * @param string $clientType The client type ('phpredis' or 'predis')
     */
    public function __construct(object $client, string $clientType)
    {
        $this->client = $client;
        $this->clientType = $clientType;

        if ($clientType === 'phpredis' && !$client instanceof \Redis) {
            throw new \InvalidArgumentException('Client must be an instance of \Redis when using phpredis');
        }

        if ($clientType === 'predis' && !$client instanceof \Predis\ClientInterface) {
            throw new \InvalidArgumentException('Client must be an instance of Predis\ClientInterface when using predis');
        }
    }

    /**
     * Helper method to handle Redis result values that might be Redis objects
     *
     * @param mixed $result The result from a Redis command
     * @param mixed $successValue The value to return on success
     * @return mixed Normalized result
     */
    private function handleRedisResult($result, $successValue)
    {
        if (is_object($result) && $result instanceof \Redis) {
            return $successValue;
        }
        return $result;
    }

    /**
     * Get the underlying Redis client
     *
     * @return object
     */
    public function getClient(): object
    {
        return $this->client;
    }

    /**
     * Get all values in a hash
     *
     * @param string $key The hash key
     * @return array
     */
    public function hGetAll(string $key): array
    {
        $result = $this->client->hGetAll($key);
        return is_array($result) ? $result : [];
    }

    /**
     * Set multiple hash fields
     *
     * @param string $key The hash key
     * @param array $dictionary The field/value pairs to set
     * @return bool
     */
    public function hMSet(string $key, array $dictionary): bool
    {
        try {
            if ($this->clientType === 'phpredis') {
                $result = $this->handleRedisResult($this->client->hMSet($key, $dictionary), true);
                return (bool)$result;
            } else {
                // Predis returns the client for fluent interface
                $result = $this->client->hmset($key, $dictionary);
                return $result == 'OK' || $result === true;
            }
        } catch (\Exception $e) {
            error_log('Error in hMSet: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a string value
     *
     * @param string $key The key
     * @return string|bool
     */
    public function get(string $key)
    {
        return $this->client->get($key);
    }

    /**
     * Set a string value
     *
     * @param string $key The key
     * @param string $value The value
     * @param int|array $expireResolution Optional expiration
     * @return bool
     */
    public function set(string $key, string $value, $expireResolution = null): bool
    {
        try {
            if ($expireResolution === null) {
                if ($this->clientType === 'phpredis') {
                    $result = $this->handleRedisResult($this->client->set($key, $value), true);
                    return (bool)$result;
                } else {
                    $result = $this->client->set($key, $value);
                    return $result == 'OK' || $result === true;
                }
            } else {
                if ($this->clientType === 'phpredis') {
                    $result = $this->handleRedisResult($this->client->set($key, $value, $expireResolution), true);
                    return (bool)$result;
                } else {
                    $result = $this->client->set($key, $value, 'EX', $expireResolution);
                    return $result == 'OK' || $result === true;
                }
            }
        } catch (\Exception $e) {
            error_log('Error in set: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a key
     *
     * @param string|array $key The key(s) to delete
     * @return int Number of keys deleted
     */
    public function del($key): int
    {
        try {
            $result = $this->client->del($key);
            if (is_object($result) && $result instanceof \Redis) {
                return 1; // Assume at least one key was deleted
            }
            return is_int($result) ? $result : (int)$result;
        } catch (\Exception $e) {
            error_log('Error in del: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Set a key's time to live in seconds
     *
     * @param string $key The key
     * @param int $ttl Time to live in seconds
     * @return bool
     */
    public function expire(string $key, int $ttl): bool
    {
        try {
            $result = $this->handleRedisResult($this->client->expire($key, $ttl), true);
            return (bool)$result;
        } catch (\Exception $e) {
            error_log('Error in expire: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find all keys matching a pattern
     *
     * @param string $pattern Pattern to match
     * @return array
     */
    public function keys(string $pattern): array
    {
        try {
            $result = $this->client->keys($pattern);
            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            error_log('Error in keys: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Start a transaction
     *
     * @return self
     */
    public function multi()
    {
        try {
            $this->client->multi();
        } catch (\Exception $e) {
            error_log('Error starting Redis transaction: ' . $e->getMessage());
        }
        return $this;
    }

    /**
     * Execute a transaction
     *
     * @return array
     */
    public function exec(): array
    {
        try {
            $result = $this->client->exec();

            // Handle the case when exec fails or returns false
            if ($result === false) {
                error_log('Redis transaction execution failed');
                return [];
            }

            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            error_log('Error executing Redis transaction: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Add a value to a set
     *
     * @param string $key The set key
     * @param mixed ...$values The values to add
     * @return int Number of elements added
     */
    public function sAdd(string $key, ...$values): int
    {
        try {
            if ($this->clientType === 'phpredis') {
                $result = $this->handleRedisResult($this->client->sAdd($key, ...$values), count($values));
                return is_int($result) ? $result : (int)$result;
            } else {
                // For Predis
                $result = $this->client->sadd($key, ...$values);
                return is_int($result) ? $result : (int)$result;
            }
        } catch (\Exception $e) {
            error_log('Error in sAdd: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get all members of a set
     *
     * @param string $key The set key
     * @return array
     */
    public function sMembers(string $key): array
    {
        try {
            $result = $this->client->sMembers($key);
            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            error_log('Error in sMembers: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Remove a member from a set
     *
     * @param string $key The set key
     * @param mixed ...$values The values to remove
     * @return int Number of elements removed
     */
    public function sRem(string $key, ...$values): int
    {
        try {
            if ($this->clientType === 'phpredis') {
                $result = $this->handleRedisResult($this->client->sRem($key, ...$values), count($values));
                return is_int($result) ? $result : (int)$result;
            } else {
                // For Predis with correct argument handling
                $result = $this->client->srem($key, ...$values);
                return is_int($result) ? $result : (int)$result;
            }
        } catch (\Exception $e) {
            error_log('Error in sRem: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if key exists
     *
     * @param string $key The key
     * @return bool
     */
    public function exists(string $key): bool
    {
        try {
            $result = $this->handleRedisResult($this->client->exists($key), true);
            return (bool)$result;
        } catch (\Exception $e) {
            error_log('Error in exists: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Increment a key's integer value
     *
     * @param string $key The key
     * @param int $increment Amount to increment
     * @return int New value
     */
    public function incrBy(string $key, int $increment = 1): int
    {
        try {
            $result = $this->handleRedisResult($this->client->incrBy($key, $increment), $increment);
            return is_int($result) ? $result : (int)$result;
        } catch (\Exception $e) {
            error_log('Error in incrBy: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get a hash field value
     *
     * @param string $key The hash key
     * @param string $field The field name
     * @return string|bool
     */
    public function hGet(string $key, string $field)
    {
        try {
            return $this->client->hGet($key, $field);
        } catch (\Exception $e) {
            error_log('Error in hGet: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set a hash field value
     *
     * @param string $key The hash key
     * @param string $field The field name
     * @param string $value The value
     * @return bool|int
     */
    public function hSet(string $key, string $field, string $value)
    {
        try {
            $result = $this->handleRedisResult($this->client->hSet($key, $field, $value), 1);
            return $result;
        } catch (\Exception $e) {
            error_log('Error in hSet: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Magic method to proxy any other Redis commands
     *
     * @param string $name The method name
     * @param array $arguments The method arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        try {
            if (method_exists($this->client, $name)) {
                return $this->client->$name(...$arguments);
            }

            // Try lowercase method name for Predis
            if ($this->clientType === 'predis' && method_exists($this->client, strtolower($name))) {
                $lowercaseName = strtolower($name);
                return $this->client->$lowercaseName(...$arguments);
            }

            throw new \BadMethodCallException(sprintf('Method "%s" does not exist on Redis client.', $name));
        } catch (\Exception $e) {
            error_log('Error in __call for method ' . $name . ': ' . $e->getMessage());
            return null;
        }
    }
    /**
     * Scan the keyspace for matching keys
     *
     * @param int $cursor The cursor returned by the previous call, or 0 for the first call
     * @param string|null $pattern Pattern to match keys against
     * @param int|null $count Number of elements to return per iteration (Redis might return more or less)
     * @return array [new cursor, array of keys]
     */
    /**
     * Scan the keyspace for matching keys
     *
     * @param int $cursor The cursor returned by the previous call, or 0 for the first call
     * @param string|array|null $pattern Pattern to match keys against, or options array
     * @param int|null $count Number of elements to return per iteration (Redis might return more or less)
     * @return array [new cursor, array of keys]
     */
    public function scan(int $cursor, $pattern = null, ?int $count = null): array
    {
        try {
            if ($this->clientType === 'phpredis') {
                // Handle case when pattern is an array of options
                if (is_array($pattern)) {
                    return $this->client->scan($cursor, $pattern);
                }

                // Otherwise, build options array
                $options = [];

                if ($pattern !== null) {
                    $options['match'] = $pattern;
                }

                if ($count !== null) {
                    $options['count'] = $count;
                }

                return $this->client->scan($cursor, $options);
            } else {
                // For Predis, the scan command has a different signature
                // Handle case when pattern is an array of options
                if (is_array($pattern)) {
                    $args = [$cursor];

                    if (isset($pattern['match'])) {
                        $args[] = 'MATCH';
                        $args[] = $pattern['match'];
                    }

                    if (isset($pattern['count'])) {
                        $args[] = 'COUNT';
                        $args[] = $pattern['count'];
                    }

                    $result = $this->client->scan(...$args);
                } else {
                    // Original string pattern handling
                    $args = [$cursor];

                    if ($pattern !== null) {
                        $args[] = 'MATCH';
                        $args[] = $pattern;
                    }

                    if ($count !== null) {
                        $args[] = 'COUNT';
                        $args[] = $count;
                    }

                    $result = $this->client->scan(...$args);
                }

                // Predis returns an array with cursor as first element and keys as the second
                if (is_array($result) && count($result) === 2) {
                    return [$result[0], $result[1]]; // [cursor, keys]
                }

                return [0, []]; // Fallback for unexpected response
            }
        } catch (\Exception $e) {
            error_log('Error in scan: ' . $e->getMessage());
            return [0, []]; // Return empty result on error
        }
    }
}