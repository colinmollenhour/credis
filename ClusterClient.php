<?php
/**
 * Credis_Client (a fork of Redisent)
 *
 * Most commands are compatible with phpredis library:
 *   - use "pipeline()" to start a pipeline of commands instead of multi(Redis::PIPELINE)
 *   - any arrays passed as arguments will be flattened automatically
 *   - setOption and getOption are not supported in standalone mode
 *   - order of arguments follows redis-cli instead of phpredis where they differ (lrem)
 *
 * - Uses phpredis library if extension is installed for better performance.
 * - Establishes connection lazily.
 * - Supports tcp and unix sockets.
 * - Reconnects automatically unless a watch or transaction is in progress.
 * - Can set automatic retry connection attempts for iffy Redis connections.
 *
 * @author Colin Mollenhour <colin@mollenhour.com>
 * @copyright 2011 Colin Mollenhour <colin@mollenhour.com>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @package Credis_Client
 */

/**
 * Credis_ClusterClient, subclass to Credis_Client that uses RedisCluster (in phpredis extension)
 * Note: RedisCluster currently has limitations like not supporting pipeline or multi.
 */
class Credis_ClusterClient extends Credis_Client
{
    /**
     * Name of the cluster as configured in redis.ini
     * @var string|null
     */
    protected ?string $clusterName;

    /**
     * Hosts & ports of the cluster
     * Eg: ['redis-node-1:6379', 'redis-node-2:6379', 'redis-node-3:6379', 'redis-node-4:6379']
     * @var array|null
     */
    protected ?array $clusterSeeds;

    /**
     * Enable persistent connections
     * @var bool
     */
    protected bool $persistentBool;


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
     * @param array|null $tlsOptions The authentication username of the Redis server
     * @throws CredisException
     */
    public function __construct(?string $clusterName, ?array $clusterSeeds = [], $timeout = null, $readTimeout = null, $persistentBool = false, $password = null, $username = null, $tlsOptions = null)
    {
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
        if (is_array($tlsOptions) && count($tlsOptions) !== 0) {
            $this->setTlsOptions($tlsOptions);
        }
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
                $this->timeout,
                $this->readTimeout,
                $this->persistentBool, // Note:  This can't be $this->persistent, because it is string
                ['user' => $this->authUsername, 'pass' => $this->authPassword],
                // Note: RedisCluster uses TLS even if empty array is passed here, so we must pass null instead
                empty($this->tlsOptions) ? null : $this->tlsOptions,
            );
            $this->connectFailures = 0;
            $this->connected = true;
            return $this;
        }
        return $this;
    }
}
