<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Core\Tests\Functional\TypoScript\IncludeTree;

use SebastianBergmann\Comparator\ObjectComparator;
use TYPO3\CMS\Core\TypoScript\IncludeTree\IncludeNode\IncludeInterface;

/**
 * Custom comparator to compare default IncludeTree without looking at the identifier.
 */
final class IncludeTreeObjectIgnoringIdentifierAndPathComparator extends ObjectComparator
{
    /**
     * Ignore some IncludeTree properties.
     *
     * @param object $object
     */
    protected function toArray($object): array
    {
        $arrayRepresentation = parent::toArray($object);
        if ($object instanceof IncludeInterface) {
            unset($arrayRepresentation['identifier'], $arrayRepresentation['path']);
        }
        return $arrayRepresentation;
    }
}
