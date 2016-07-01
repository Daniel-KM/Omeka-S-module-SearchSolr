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

namespace Solr\ValueExtractor;

class Manager
{
    protected $config;
    protected $extractors = [];

    protected $serviceLocator;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function get($resourceName)
    {
        if (isset($this->extractors[$resourceName])) {
            return $this->extractors[$resourceName];
        }

        if (!isset($this->config[$resourceName])) {
            return null;
        }

        $class = $this->config[$resourceName];
        if (!class_exists($class)) {
            return null;
        }

        if (!in_array('Solr\ValueExtractor\ValueExtractorInterface', class_implements($class))) {
            return null;
        }

        $this->extractors[$resourceName] = new $class;
        $this->extractors[$resourceName]->setServiceLocator($this->getServiceLocator());
        return $this->extractors[$resourceName];
    }

    public function getAll()
    {
        $extractors = [];
        foreach ($this->config as $resourceName => $class) {
            $extractor = $this->get($resourceName);
            if ($extractor !== null) {
                $extractors[$resourceName] = $extractor;
            }
        }
        return $extractors;
    }

    public function setServiceLocator($serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }

}
