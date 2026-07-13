<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016
 * Copyright Daniel Berthereau, 2018-2026
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
        // The connection to the Solr core is a facet of the engine, edited on
        // its core page: no more core to select here.
        $this
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

}
