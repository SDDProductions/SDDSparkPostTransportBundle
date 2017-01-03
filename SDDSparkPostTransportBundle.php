<?php

namespace SDD\Bundle\SparkPostTransportBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ds\Bundle\TransportBundle\DependencyInjection\Compiler\TransportPass;

/**
 * Class SDDSparkPostTransportBundle
 */
class SDDSparkPostTransportBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new TransportPass);
    }
}
