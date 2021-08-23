<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016
 * Copyright Daniel Berthereau 2018-2021
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

namespace SearchSolr\Form\Admin;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SolrConfigFieldset extends Fieldset
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'solr_core_id',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Solr core', // @translate
                    'value_options' => $this->getSolrCoresOptions(),
                ],
                'attributes' => [
                    'id' => 'solr_core_id',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'index_name',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Solr index name for shared core', // @translate
                    'info' => 'May be empty, or may be or may not be the same index name than the third party, depending on its configuration.', // @translate
                ],
                'attributes' => [
                    'id' => 'index_name',
                    'required' => false,
                ],
            ]);
    }

    protected function getSolrCoresOptions()
    {
        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation[] $solrCores */
        $solrCores = $this->getOption('solrCores');
        if (!count($solrCores)) {
            return [];
        }

        $searchEngineId = $this->getOption('search_engine_id');

        // If the core doesn't support multiple index, it will be unavailable,
        // except for the current index.
        $solrCore = reset($solrCores);
        $services = $solrCore->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        /** @var \AdvancedSearch\Api\Representation\SearchEngineRepresentation[] $searchEngines */
        $searchEngines = $services->get('Omeka\ApiManager')->search('search_engines', ['adapter' => 'solarium'])->getContent();
        $coreIndexes = [];
        foreach ($searchEngines as $searchEngine) {
            $coreId = $searchEngine->settingAdapter('solr_core_id', '');
            $coreIndexes[$coreId][] = $searchEngine->id();
        }

        $options = [];
        foreach ($solrCores as $solrCore) {
            $option = [
                'value' => $solrCore->id(),
                'label' => $solrCore->name(),
            ];
            if (isset($coreIndexes[$solrCore->id()])
                && !$solrCore->setting('index_field')
                && !in_array($searchEngineId, $coreIndexes[$solrCore->id()])
            ) {
                $option['label'] = sprintf($translator->translate('%s (unavailable: option multi-index not set)'), $option['label']); // @translate
                $option['disabled'] = true;
            }
            $options[] = $option;
        }

        return $options;
    }
}
