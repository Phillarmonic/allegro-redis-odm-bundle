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
        return $this->client->hGetAll($key);
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
        if ($this->clientType === 'phpredis') {
            return $this->client->hMSet($key, $dictionary);
        } else {
            // Predis returns the client for fluent interface
            $result = $this->client->hmset($key, $dictionary);
            return $result == 'OK' || $result === true;
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
        if ($expireResolution === null) {
            if ($this->clientType === 'phpredis') {
                return $this->client->set($key, $value);
            } else {
                $result = $this->client->set($key, $value);
                return $result == 'OK' || $result === true;
            }
        } else {
            if ($this->clientType === 'phpredis') {
                return $this->client->set($key, $value, $expireResolution);
            } else {
                $result = $this->client->set($key, $value, 'EX', $expireResolution);
                return $result == 'OK' || $result === true;
            }
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
        return $this->client->del($key);
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
        return (bool)$this->client->expire($key, $ttl);
    }

    /**
     * Find all keys matching a pattern
     *
     * @param string $pattern Pattern to match
     * @return array
     */
    public function keys(string $pattern): array
    {
        return $this->client->keys($pattern);
    }

    /**
     * Start a transaction
     *
     * @return self
     */
    public function multi()
    {
        $this->client->multi();
        return $this;
    }

    /**
     * Execute a transaction
     *
     * @return array
     */
    public function exec(): array
    {
        return $this->client->exec();
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
        if ($this->clientType === 'phpredis') {
            // Cast the result to int to ensure it matches the return type
            $result = $this->client->sAdd($key, ...$values);
            return is_int($result) ? $result : (int)$result;
        } else {
            // Predis has a different method signature but needs array elements as separate arguments
            $result = $this->client->sadd($key, ...$values); // Just unwrap with splat again
            return is_int($result) ? $result : (int)$result;
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
        return $this->client->sMembers($key);
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
        if ($this->clientType === 'phpredis') {
            $result = $this->client->sRem($key, ...$values);
            return is_int($result) ? $result : (int)$result;
        } else {
            // Fix the same issue here by using splat operator
            $result = $this->client->srem($key, ...$values);
            return is_int($result) ? $result : (int)$result;
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
        $result = $this->client->exists($key);
        return (bool)$result;
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
        return $this->client->incrBy($key, $increment);
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
        return $this->client->hGet($key, $field);
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
        return $this->client->hSet($key, $field, $value);
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
        if (method_exists($this->client, $name)) {
            return $this->client->$name(...$arguments);
        }

        // Try lowercase method name for Predis
        if ($this->clientType === 'predis' && method_exists($this->client, strtolower($name))) {
            $lowercaseName = strtolower($name);
            return $this->client->$lowercaseName(...$arguments);
        }

        throw new \BadMethodCallException(sprintf('Method "%s" does not exist on Redis client.', $name));
    }
}