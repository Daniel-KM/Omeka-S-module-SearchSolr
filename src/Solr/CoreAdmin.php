<?php declare(strict_types=1);

namespace SearchSolr\Solr;

use Laminas\Log\LoggerInterface;

/**
 * Server-level administration of Solr cores and collections.
 *
 * Operates on a connection (base url + optional basic auth), not on a stored
 * SolrCore entity, so a core can be created before any core is registered in
 * Omeka. The connection array uses the keys "scheme", "host", "port",
 * "username" and "password", as returned by SolrCoreRepresentation::
 * clientSettings(); the target core name is always passed explicitly.
 */
class CoreAdmin
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Detect the Solr server mode through the system info api.
     *
     * @return string|null "cloud", "standalone", or null when unreachable.
     */
    public function serverMode(array $connection): ?string
    {
        $result = $this->httpGet($connection, '/solr/admin/info/system?wt=json');
        if ($result === null) {
            return null;
        }
        return ($result['mode'] ?? '') === 'solrcloud' ? 'cloud' : 'standalone';
    }

    /**
     * Create a core (standalone) or collection (cloud) on the Solr server.
     *
     * Standalone uses the CoreAdmin api with a config set (default "_default",
     * shipped with Solr, providing a managed schema and the dynamic fields used
     * by the module). SolrCloud uses the Collections api, where the config set
     * must already be uploaded to ZooKeeper. Returns false when the server is
     * unreachable, the config set is missing, or the core already exists; the
     * schema field types and the maps are provisioned by the caller afterwards.
     */
    public function createCore(array $connection, string $coreName, string $configSet = '_default'): bool
    {
        if ($coreName === '') {
            $this->logger->err('SearchSolr: Cannot create core: no core name.'); // @translate
            return false;
        }

        $mode = $this->serverMode($connection);
        if ($mode === null) {
            $this->logger->err('SearchSolr: Cannot create core: Solr server is unreachable.'); // @translate
            return false;
        }

        if ($mode === 'cloud') {
            $path = '/solr/admin/collections?action=CREATE'
                . '&name=' . urlencode($coreName)
                . '&collection.configName=' . urlencode($configSet)
                . '&numShards=1&replicationFactor=1&wt=json';
        } else {
            $path = '/solr/admin/cores?action=CREATE'
                . '&name=' . urlencode($coreName)
                . '&configSet=' . urlencode($configSet)
                . '&wt=json';
        }

        $result = $this->httpGet($connection, $path);
        if ($result === null || !empty($result['error'])) {
            $this->logger->err(
                'SearchSolr: Core "{core}" creation failed: {error}', // @translate
                ['core' => $coreName, 'error' => $result['error']['msg'] ?? 'unreachable']
            );
            return false;
        }

        $this->logger->info(
            'SearchSolr: Core "{core}" created on the {mode} server.', // @translate
            ['core' => $coreName, 'mode' => $mode]
        );
        return true;
    }

    /**
     * Delete a core (standalone) or collection (cloud) on the Solr server.
     *
     * Standalone unloads the core, optionally deleting its index, data and
     * instance directories; SolrCloud deletes the collection (always removing
     * its data). Returns false when the server is unreachable or on api error.
     */
    public function deleteCore(array $connection, string $coreName, bool $deleteFiles = true): bool
    {
        if ($coreName === '') {
            $this->logger->err('SearchSolr: Cannot delete core: no core name.'); // @translate
            return false;
        }

        $mode = $this->serverMode($connection);
        if ($mode === null) {
            $this->logger->err('SearchSolr: Cannot delete core: Solr server is unreachable.'); // @translate
            return false;
        }

        if ($mode === 'cloud') {
            $path = '/solr/admin/collections?action=DELETE'
                . '&name=' . urlencode($coreName) . '&wt=json';
        } else {
            $path = '/solr/admin/cores?action=UNLOAD'
                . '&core=' . urlencode($coreName);
            if ($deleteFiles) {
                $path .= '&deleteIndex=true&deleteDataDir=true&deleteInstanceDir=true';
            }
            $path .= '&wt=json';
        }

        $result = $this->httpGet($connection, $path);
        if ($result === null || !empty($result['error'])) {
            $this->logger->err(
                'SearchSolr: Core "{core}" deletion failed: {error}', // @translate
                ['core' => $coreName, 'error' => $result['error']['msg'] ?? 'unreachable']
            );
            return false;
        }

        $this->logger->info(
            'SearchSolr: Core "{core}" deleted on the {mode} server.', // @translate
            ['core' => $coreName, 'mode' => $mode]
        );
        return true;
    }

    /**
     * GET a Solr admin api path on the connection and decode the json response.
     *
     * @return array|null Decoded response, or null when the request failed.
     */
    protected function httpGet(array $connection, string $path): ?array
    {
        $base = ($connection['scheme'] ?? 'http') . '://'
            . ($connection['host'] ?? 'localhost') . ':'
            . ($connection['port'] ?? 8983);
        $header = null;
        if (!empty($connection['username'])) {
            $credentials = $connection['username'] . ':' . ($connection['password'] ?? '');
            $header = 'Authorization: Basic ' . base64_encode($credentials);
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $header,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($base . $path, false, $context);
        if ($response === false) {
            return null;
        }
        return json_decode($response, true) ?: null;
    }
}
