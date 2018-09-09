<?php

/*
 * Copyright BibLibre, 2017
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

namespace Solr\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class SolrMappingRepresentation extends AbstractEntityRepresentation
{
    public function getJsonLdType()
    {
        return 'o:SolrMapping';
    }

    public function getJsonLd()
    {
        $entity = $this->resource;
        return [
            'o:solr_node' => $this->solrNode()->getReference(),
            'o:resource_name' => $entity->getResourceName(),
            'o:field_name' => $entity->getFieldName(),
            'o:source' => $entity->getSource(),
            'o:settings' => $entity->getSettings(),
        ];
    }

    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        $params = [
            'action' => $action,
            'id' => $this->id(),
            'resourceName' => $this->resourceName(),
            'nodeId' => $this->solrNode()->id(),
        ];
        $options = [
            'force_canonical' => $canonical,
        ];

        return $url('admin/solr/node-id-mapping-resource-id', $params, $options);
    }

    /**
     * @return \Solr\Api\Representation\SolrNodeRepresentation
     */
    public function solrNode()
    {
        $solrNode = $this->resource->getSolrNode();
        return $this->getAdapter('solr_nodes')->getRepresentation($solrNode);
    }

    /**
     * @return string
     */
    public function resourceName()
    {
        return $this->resource->getResourceName();
    }

    /**
     * @return string
     */
    public function fieldName()
    {
        return $this->resource->getFieldName();
    }

    /**
     * @return string
     */
    public function source()
    {
        return $this->resource->getSource();
    }

    /**
     * @return array
     */
    public function settings()
    {
        return $this->resource->getSettings();
    }
}
