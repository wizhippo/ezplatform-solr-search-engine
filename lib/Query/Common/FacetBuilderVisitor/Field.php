<?php

/**
 * This file is part of the eZ Platform Solr Search Engine package.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 *
 * @version //autogentag//
 */
namespace EzSystems\EzPlatformSolrSearchEngine\Query\Common\FacetBuilderVisitor;

use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\Content\Query\FacetBuilder;
use eZ\Publish\API\Repository\Values\Content\Query\FacetBuilder\FieldFacetBuilder;
use eZ\Publish\API\Repository\Values\Content\Search\Facet\FieldFacet;
use eZ\Publish\Core\Search\Common\FieldNameResolver;
use EzSystems\EzPlatformSolrSearchEngine\Query\FacetBuilderVisitor;
use EzSystems\EzPlatformSolrSearchEngine\Query\FacetFieldVisitor;

/**
 * Visits the Field facet builder.
 */
class Field extends FacetBuilderVisitor implements FacetFieldVisitor
{
    /**
     * @var FieldNameResolver
     */
    private $fieldNameResolver;

    public function __construct(FieldNameResolver $fieldNameResolver)
    {
        $this->fieldNameResolver = $fieldNameResolver;
    }

    public function mapField($field, array $data, FacetBuilder $facetBuilder)
    {
        $values = [];
        $totalCount = 0;
        $missingCount = 0;

        reset($data);
        while ($key = current($data)) {
            $totalCount += $values[$key] = next($data);
            next($data);
        }

        if (current($data) === null) {
            $totalCount += $missingCount = next($data);
        }

        return new FieldFacet([
            'name' => $facetBuilder->name,
            'entries' => $values,
            'missingCount' => $missingCount,
            'totalCount' => $totalCount,
            'otherCount' => $totalCount - $missingCount,
        ]);
    }

    public function canVisit(FacetBuilder $facetBuilder)
    {
        return $facetBuilder instanceof FieldFacetBuilder;
    }

    public function visitBuilder(FacetBuilder $facetBuilder, $fieldId)
    {
        $parameters = [];
        $criteria = new Criterion\MatchAll();
        $fieldPaths = $facetBuilder->fieldPaths;

        if (count($fieldPaths) > 1) {
            $contentTypeIdentifier = array_shift($fieldPaths);
            $criteria = new Criterion\ContentTypeIdentifier($contentTypeIdentifier);
        }

        $fieldDefinitionIdentifier = array_shift($fieldPaths);
        $name = array_shift($fieldPaths);

        $fieldTypes = $this->fieldNameResolver->getFieldTypes(
            $criteria,
            $fieldDefinitionIdentifier,
            null,
            $name
        );

        foreach ($fieldTypes as $fieldName => $fieldType) {
            $parameters = array_merge($parameters, [
                'facet.field' => "{!ex=dt key={$fieldId}}{$fieldName}",
                "f.{$fieldName}.facet.limit" => $facetBuilder->limit,
                "f.{$fieldName}.facet.mincount" => $facetBuilder->minCount,
                "f.{$fieldName}.facet.sort" => $this->getSort($facetBuilder),
                "f.{$fieldName}.facet.missing" => 'true',
            ]);
        }

        return $parameters;
    }

    private function getSort(FieldFacetBuilder $facetBuilder)
    {
        switch ($facetBuilder->sort) {
            case FieldFacetBuilder::COUNT_DESC:
                return 'count';
            case FieldFacetBuilder::TERM_ASC:
                return 'index';
        }

        return 'index';
    }
}
