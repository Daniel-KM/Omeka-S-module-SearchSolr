<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2020-2025
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

namespace SearchSolr\ValueFormatter;

use Laminas\ServiceManager\ServiceLocatorInterface;

interface ValueFormatterInterface
{
    /**
     * Get the label of the formatter.
     */
    public function getLabel(): string;

    /**
     * Get the comment of the formatter.
     */
    public function getComment(): ?string;

    /**
     * Set services to be used by the formatter.
     *
     * @deprecated Use factories.
     */
    public function setServiceLocator(ServiceLocatorInterface $services): self;

    /**
     * Set settings to be used by the formatter, if any.
     */
    public function setSettings(array $settings): self;

    /**
     * Pre-format a value, so extract the requested parts.
     *
     * @param mixed $value
     */
    public function preFormat($value): array;

    /**
     * Convert a value (Omeka Value, string…) into indexable values.
     *
     * Most of the times, a value is output as it is as string, integer or date.
     *
     * @param mixed $value
     */
    public function format($value): array;

    /**
     * Post-format a list of scalar values.
     *
     * @param mixed $value Should be a scalar value.
     */
    public function postFormat($value): array;

    /**
     * Finalize formatting.
     *
     * @param mixed $value Should be an array of scalar values.
     */
    public function finalizeFormat(array $values): array;
}
