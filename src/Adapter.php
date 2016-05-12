<?php

/*
 * Copyright BibLibre, 2016
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

namespace Solr;

use Search\Adapter\AbstractAdapter;
use Solr\Form\ConfigForm;

class Adapter extends AbstractAdapter
{
    public function getLabel()
    {
        return 'Solr';
    }

    public function getConfigForm()
    {
        return new ConfigForm($this->getServiceLocator());
    }

    public function getIndexerClass()
    {
        return 'Solr\Indexer';
    }

    public function getQuerierClass()
    {
        return 'Solr\Querier';
    }

    public function getAvailableFacetFields()
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');

        $response = $api->search('solr_fields', ['is_indexed' => 1]);
        $fields = $response->getContent();
        $facetFields = [];
        foreach ($fields as $field) {
            $facetFields[] = [
                'name' => $field->name(),
                'label' => $field->label(),
            ];
        }

        return $facetFields;
    }

    public function getAvailableSortFields()
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');

        $response = $api->search('solr_fields', ['is_indexed' => 1, 'is_multivalued' => 0]);
        $fields = $response->getContent();
        $sortFields = [];
        foreach ($fields as $field) {
            $sortFields[] = [
                'name' => $field->name(),
                'label' => $field->label(),
            ];
        }

        return $sortFields;
    }
}
