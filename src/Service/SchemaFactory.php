<?php

/*
 * Copyright BibLibre, 2017
 * Copyright Daniel Berthereau, 2019
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace Solr\Service;

use Interop\Container\ContainerInterface;
use Solr\Schema\Schema;
use Zend\ServiceManager\Factory\FactoryInterface;

class SchemaFactory implements FactoryInterface
{
    protected $schemas = [];

    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        /** @var \Solr\Api\Representation\SolrNodeRepresentation $solrNode */
        $solrNode = $options['solr_node'];

        if (!isset($this->schemas[$solrNode->id()])) {
            $schemaUrl = $solrNode->clientUrl() . '/schema';
            $schema = new Schema($schemaUrl);
            if (!empty($solrNode->clientSettings()['secure'])
                && !empty($services->get('Config')['solr']['config']['solr_bypass_certificate_check'])
            ) {
                $this->setSchemaConfig($schema, $schemaUrl);
            }
            $this->schemas[$solrNode->id()] = $schema;
        }

        return $this->schemas[$solrNode->id()];
    }

    /**
     * Set the schema directly in order to bypass certificate check.
     *
     * To bypass certificate check avoids only the expiration or incompletion
     * issue, not the authentication. Nevertheless, it's not recommended for
     * production.
     *
     * @param Schema $schema
     * @param string $schemaUrl
     */
    protected function setSchemaConfig(Schema $schema, $schemaUrl)
    {
        $arrContextOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
        $contents = @file_get_contents($schemaUrl, false, stream_context_create($arrContextOptions));
        if ($contents) {
            $response = json_decode($contents, true);
            $schema->setSchema($response['schema']);
        }
    }
}
