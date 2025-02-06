<?php

/**
 * Credis, a Redis interface for the modest
 *
 * @author Justin Poliey <jdp34@njit.edu>
 * @copyright 2009 Justin Poliey <jdp34@njit.edu>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @package Credis
 */

/**
 * Credis_Cluster, subclass to Credis_Client that uses RedisCluster (in phpredis extension)
 *
 * Note: RedisCluster currently has limitations like not supporting pipeline or multi.
 * Note: Many methods require an additional parameter to specify which node to run on, and only run on that node,
 *       such as save(), flushDB(), ping(), and scan().
 * Note: Redis clusters do not support select(), as they only have a single database.
 * Note: RedisCluster currently has buggy/broken behaviour for pSubscribe and script.
 */
class Credis_Cluster extends Credis_Client
{
    /**
     * Name of the cluster as configured in redis.ini
     * @var string|null
     */
    protected $clusterName;

    /**
     * Hosts & ports of the cluster
     * Eg: ['redis-node-1:6379', 'redis-node-2:6379', 'redis-node-3:6379', 'redis-node-4:6379']
     * @var array|null
     */
    protected $clusterSeeds;

    /**
     * Enable persistent connections
     * @var bool
     */
    protected $persistentBool;

    /**
     * Creates a connection to the Redis Cluster on cluser named {@link $clusterName} or seeds {@link $clusterSeeds}.
     *
     * @param string|null $clusterName Name of the cluster as configured in redis.ini
     * @param array|null $clusterSeeds Hosts & ports of the cluster; eg: ['redis-node-1:6379', 'redis-node-2:6379']
     * @param float|null $timeout Timeout period in seconds
     * @param float|null $readTimeout Timeout period in seconds
     * @param bool $persistentBool Flag to establish persistent connection
     * @param string|null $password The authentication password of the Redis server
     * @param string|null $username The authentication username of the Redis server
     * @param array|null $tlsOptions If array, then uses TLS for non-seed connections; if null, no TLS for non-seed
     * @throws CredisException
     */
    public function __construct($clusterName = null, array $clusterSeeds = [], $timeout = null, $readTimeout = null, $persistentBool = false, $password = null, $username = null, $tlsOptions = null)
    {
        if (!class_exists(\RedisCluster::class)) {
            throw new \Exception("Credis_Cluster depends on RedisCluster class from phpredis extension. "
                . " Please verify that phpredis extension is installed and enabled");
        }
        $this->clusterName = $clusterName;
        $this->clusterSeeds = $clusterSeeds;
        $this->scheme = null;
        $this->timeout = $timeout;
        $this->readTimeout = $readTimeout;
        $this->persistentBool = $persistentBool;
        $this->standalone = false;
        $this->authPassword = $password;
        $this->authUsername = $username;
        $this->selectedDb = 0; // Note: Clusters don't have db, but it's in superclass
        $this->tlsOptions = $tlsOptions;
        // PHP Redis extension support TLS/ACL AUTH since 5.3.0 // Note: Do we need this in Credis_ClusterClient?
        $this->oldPhpRedis = (bool)version_compare(phpversion('redis'), '5.3.0', '<');
    }

    /**
     * @inheritDoc
     */
    public function connect()
    {
        if ($this->connected) {
            return $this;
        }
        $this->close(true);
        if (!$this->redis) {
            $this->redis = new RedisCluster(
                $this->clusterName,
                $this->clusterSeeds,
                isset($this->timeout) ? $this->timeout : 0,
                isset($this->readTimeout) ? $this->readTimeout : 0,
                $this->persistentBool, // Note:  This can't be $this->persistent, because it is string
                ['user' => $this->authUsername, 'pass' => $this->authPassword],
                // Note: RedisCluster won't use TLS for non-seed connections if this is null
                $this->tlsOptions
            );
            $this->connectFailures = 0;
            $this->connected = true;
            return $this;
        }
        return $this;
    }

    /**
     * @return string|null
     */
    public function getClusterName()
    {
        return $this->clusterName;
    }

    /**
     * @return array|null
     */
    public function getClusterSeeds()
    {
        return $this->clusterSeeds;
    }

    /**
     * @return bool
     */
    public function getPersistenceBool()
    {
        return $this->persistentBool;
    }

    public function getClusterMasters() : array {
        $this->connect();
        return $this->redis->_masters();
    }
}
