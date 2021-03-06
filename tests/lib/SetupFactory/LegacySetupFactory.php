<?php

/**
 * This file is part of the eZ Platform Solr Search Engine package.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 *
 * @version //autogentag//
 */
namespace EzSystems\EzPlatformSolrSearchEngine\Tests\SetupFactory;

use eZ\Publish\API\Repository\Tests\SetupFactory\Legacy as CoreLegacySetupFactory;
use eZ\Publish\Core\Base\Container\Compiler as BaseCompiler;
use EzSystems\EzPlatformSolrSearchEngine\Container\Compiler;
use PDO;
use RuntimeException;
use eZ\Publish\API\Repository\Tests\SearchServiceTranslationLanguageFallbackTest;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

/**
 * Used to setup the infrastructure for Repository Public API integration tests,
 * based on Repository with Legacy Storage Engine implementation.
 */
class LegacySetupFactory extends CoreLegacySetupFactory
{
    /**
     * Returns a configured repository for testing.
     *
     * @param bool $initializeFromScratch
     *
     * @return \eZ\Publish\API\Repository\Repository
     */
    public function getRepository($initializeFromScratch = true)
    {
        // Load repository first so all initialization steps are done
        $repository = parent::getRepository($initializeFromScratch);

        if ($initializeFromScratch) {
            $this->indexAll();
        }

        return $repository;
    }

    protected function externalBuildContainer(ContainerBuilder $containerBuilder)
    {
        $settingsPath = __DIR__ . '/../../../lib/Resources/config/container/';
        $testSettingsPath = __DIR__ . '/../Resources/config/';

        $solrLoader = new YamlFileLoader($containerBuilder, new FileLocator($settingsPath));
        $solrLoader->load('solr.yml');

        $solrTestLoader = new YamlFileLoader($containerBuilder, new FileLocator($testSettingsPath));
        $solrTestLoader->load($this->getTestConfigurationFile());

        $containerBuilder->addCompilerPass(new Compiler\FieldMapperPass\BlockFieldMapperPass());
        $containerBuilder->addCompilerPass(new Compiler\FieldMapperPass\BlockTranslationFieldMapperPass());
        $containerBuilder->addCompilerPass(new Compiler\FieldMapperPass\ContentFieldMapperPass());
        $containerBuilder->addCompilerPass(new Compiler\FieldMapperPass\ContentTranslationFieldMapperPass());
        $containerBuilder->addCompilerPass(new Compiler\FieldMapperPass\LocationFieldMapperPass());
        $containerBuilder->addCompilerPass(new Compiler\AggregateCriterionVisitorPass());
        $containerBuilder->addCompilerPass(new Compiler\AggregateFacetBuilderVisitorPass());
        $containerBuilder->addCompilerPass(new Compiler\AggregateSortClauseVisitorPass());
        $containerBuilder->addCompilerPass(new Compiler\EndpointRegistryPass());
        $containerBuilder->addCompilerPass(new BaseCompiler\Search\AggregateFieldValueMapperPass());
        $containerBuilder->addCompilerPass(new BaseCompiler\Search\FieldRegistryPass());
        $containerBuilder->addCompilerPass(new BaseCompiler\Search\SearchEngineSignalSlotPass('solr'));
    }

    /**
     * Indexes all Content objects.
     */
    protected function indexAll()
    {
        // @todo: Is there a nicer way to get access to all content objects? We
        // require this to run a full index here.
        /** @var \eZ\Publish\SPI\Persistence\Handler $persistenceHandler */
        $persistenceHandler = $this->getServiceContainer()->get('ezpublish.spi.persistence.legacy');
        /** @var \eZ\Publish\SPI\Search\Handler $searchHandler */
        $searchHandler = $this->getServiceContainer()->get('ezpublish.spi.search.solr');
        /** @var \eZ\Publish\Core\Persistence\Database\DatabaseHandler $databaseHandler */
        $databaseHandler = $this->getServiceContainer()->get('ezpublish.api.storage_engine.legacy.dbhandler');

        $query = $databaseHandler
            ->createSelectQuery()
            ->select('id', 'current_version')
            ->from('ezcontentobject');

        $stmt = $query->prepare();
        $stmt->execute();

        $contentObjects = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $contentObjects[] = $persistenceHandler->contentHandler()->load(
                $row['id'],
                $row['current_version']
            );
        }

        /** @var \EzSystems\EzPlatformSolrSearchEngine\Handler $searchHandler */
        $searchHandler->purgeIndex();
        $searchHandler->bulkIndexContent($contentObjects);
        $searchHandler->commit();
    }

    protected function getTestConfigurationFile()
    {
        $coresSetup = getenv('CORES_SETUP');

        switch ($coresSetup) {
            case SearchServiceTranslationLanguageFallbackTest::SETUP_DEDICATED:
                return 'multicore_dedicated.yml';
            case SearchServiceTranslationLanguageFallbackTest::SETUP_SHARED:
                return 'multicore_shared.yml';
            case SearchServiceTranslationLanguageFallbackTest::SETUP_SINGLE:
                return 'single_core.yml';
        }

        throw new RuntimeException("Backend cores setup '{$coresSetup}' is not handled");
    }
}
